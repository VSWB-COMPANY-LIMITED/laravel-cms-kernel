// Package mirror implements the Google Drive → local one-way mirror sync:
// recursive listing, delta sync, concurrent download with retry/backoff, and
// a local-filesystem circuit breaker — ported from
// Dev\Kernel\Commands\GDriveMirrorSync (GDriveMirrorSync.php).
package mirror

import (
	"context"
	"crypto/rand"
	"encoding/hex"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"sync/atomic"
	"time"

	"google.golang.org/api/drive/v3"
	"google.golang.org/api/option"

	"github.com/vswb/gdrive-mirror/internal/classify"
	"github.com/vswb/gdrive-mirror/internal/report"
)

// Syncer runs one mirror pass. Create with New(), run with Run(). Safe for
// its internal worker pool to call from multiple goroutines; Syncer itself
// is not meant to be reused across concurrent Run() calls.
type Syncer struct {
	cfg   Config
	srv   *drive.Service
	runID string

	mu    sync.Mutex
	stats Stats

	consecutiveLocalFsErrors atomic.Int64
	aborted                  atomic.Bool
	listingRetryHits         atomic.Int64
	processedCount           atomic.Int64
	totalFileTasks           int
}

// fileTask is a planned download: the Drive item plus its fully resolved
// (collision-safe, export-extension-suffixed) local destination path.
type fileTask struct {
	item       Item
	targetPath string
	exportSpec *ExportSpec
}

// New authenticates against Drive with a service-account JSON key
// (read-only scope) and prepares a Syncer for cfg.
func New(ctx context.Context, cfg Config) (*Syncer, error) {
	srv, err := drive.NewService(ctx,
		option.WithCredentialsFile(cfg.CredsFile),
		option.WithScopes(drive.DriveReadonlyScope),
	)
	if err != nil {
		return nil, fmt.Errorf("init Drive service: %w", err)
	}

	return &Syncer{cfg: cfg, srv: srv, runID: newRunID()}, nil
}

func newRunID() string {
	b := make([]byte, 4)
	if _, err := rand.Read(b); err != nil {
		return "00000000"
	}
	return hex.EncodeToString(b)
}

// Run executes one full mirror pass and returns the accumulated stats.
func (s *Syncer) Run(ctx context.Context) (Stats, error) {
	started := time.Now()

	if !s.cfg.DryRun {
		if err := os.MkdirAll(s.cfg.Path, 0o755); err != nil {
			return s.stats, fmt.Errorf("preflight: cannot create destination dir %s: %w", s.cfg.Path, err)
		}
		// A real write→read→delete probe catches a disk that reports writable
		// bits but is actually wedged/read-only/dead — os.MkdirAll succeeding
		// above is not proof the disk can really take writes (see WriteProbe doc).
		if !WriteProbe(s.cfg.Path, s.runID) {
			return s.stats, fmt.Errorf("preflight: destination not writable (read-only/I/O error/hung disk): %s", s.cfg.Path)
		}
	}

	localPrefix := s.resolveFolderName(ctx)

	s.logf("Fetching remote item list (recursive) via Drive API...")
	items, err := s.ListFolderRecursive(ctx, s.cfg.FolderID, "")
	if err != nil {
		return s.stats, fmt.Errorf("list folder: %w", err)
	}
	s.stats.TotalListed = len(items)
	s.logf("Found %d item(s).", len(items))

	if s.cfg.Limit > 0 && len(items) > s.cfg.Limit {
		items = items[:s.cfg.Limit]
		s.logf("--limit=%d: processing first %d item(s) only.", s.cfg.Limit, len(items))
	}

	if s.cfg.DryRun {
		s.printDryRun(items)
		return s.stats, nil
	}

	tasks := s.buildPlan(items, localPrefix)
	s.stats.Processed = len(items)

	if len(tasks) > 0 && !s.aborted.Load() {
		s.totalFileTasks = len(tasks)
		s.logf("Starting synchronization of %d file(s) with concurrency=%d...", len(tasks), s.cfg.Concurrency)
		s.runWorkers(ctx, tasks)
	}

	reportDir := filepath.Join(filepath.Dir(strings.TrimRight(s.cfg.Path, string(filepath.Separator))), "gdrive-mirror-reports")
	s.writeReports(reportDir)
	s.printSummary(time.Since(started))

	return s.stats, nil
}

// resolveFolderName best-effort looks up the synced folder's own name, used
// as the local subfolder prefix (mirrors GDriveMirrorSync's
// `$localPrefix = $folderInfo->getName()` in service-account mode). Falls
// back to the raw folder ID if the lookup fails.
func (s *Syncer) resolveFolderName(ctx context.Context) string {
	f, err := s.srv.Files.Get(s.cfg.FolderID).Fields("name").Context(ctx).Do()
	if err != nil || f == nil || f.Name == "" {
		s.logWarn("Could not resolve folder name for %s, using ID as prefix: %v", s.cfg.FolderID, err)
		return s.cfg.FolderID
	}
	return f.Name
}

// buildPlan walks items in listing order, sequentially (mkdir is cheap and
// this keeps collision-suffix assignment deterministic), creating
// directories and resolving each file's final target path — including
// shortcut skipping and same-name collision suffixing. Actual network
// downloads happen later in runWorkers so they can run concurrently.
func (s *Syncer) buildPlan(items []Item, localPrefix string) []fileTask {
	used := map[string]string{} // resolved target path -> Drive file ID (collision tracking, this run only)
	var tasks []fileTask

	for _, it := range items {
		absPath := filepath.Join(s.cfg.Path, localPrefix, filepath.FromSlash(it.Path))

		if it.Type == "dir" {
			if err := os.MkdirAll(absPath, 0o755); err != nil {
				s.recordDirFailure(it, err)
				if classify.IsLocalFsError(err.Error()) {
					n := s.consecutiveLocalFsErrors.Add(1)
					if classify.ShouldAbortOnLocalFsErrors(int(n), classify.MaxConsecutiveLocalFsErrors) {
						s.triggerAbort()
						return tasks
					}
				}
				continue
			}
			s.mu.Lock()
			s.stats.Folders++
			s.mu.Unlock()
			s.consecutiveLocalFsErrors.Store(0)
			continue
		}

		// Shortcuts are pointers with no content of their own — their target is
		// listed as its own independent item in the same listing, so skipping
		// here loses nothing (see GDriveMirrorSync.php L854-871).
		if it.MimeType == ShortcutMimeType {
			s.mu.Lock()
			s.stats.Skipped++
			s.mu.Unlock()
			continue
		}

		var exportSpec *ExportSpec
		if spec, ok := ExportMap[it.MimeType]; ok {
			specCopy := spec
			exportSpec = &specCopy
		}

		target := absPath
		if exportSpec != nil {
			target = absPath + "." + exportSpec.Ext
		}

		if prevID, exists := used[target]; exists && prevID != it.ID {
			s.mu.Lock()
			s.stats.Collisions++
			s.mu.Unlock()
			target = collisionSuffix(target, it.ID)
		}
		used[target] = it.ID

		tasks = append(tasks, fileTask{item: it, targetPath: target, exportSpec: exportSpec})
	}

	return tasks
}

// collisionSuffix appends the first 8 chars of the Drive file ID before the
// extension, e.g. "file.pdf" → "file_1JP7CIBW.pdf" — matches
// GDriveMirrorSync's collision handling 1:1.
func collisionSuffix(target string, fileID string) string {
	ext := filepath.Ext(target)
	base := strings.TrimSuffix(target, ext)
	suffix := fileID
	if len(suffix) > 8 {
		suffix = suffix[:8]
	}
	return base + "_" + suffix + ext
}

func (s *Syncer) recordDirFailure(it Item, err error) {
	msg := err.Error()
	cls := classify.DriveError(msg)
	s.mu.Lock()
	s.stats.Errors++
	s.stats.FailedFiles = append(s.stats.FailedFiles, report.FailedItem{
		Type:        "dir",
		Path:        it.Path,
		Reason:      msg,
		Permanent:   !cls.Retryable,
		ErrorReason: optStr(cls.Reason),
		Category:    cls.Category,
	})
	s.mu.Unlock()
	s.logError("Failed to create folder: %s | reason: %s", it.Path, msg)
}

// runWorkers dispatches tasks to a fixed-size goroutine pool. The circuit
// breaker (s.aborted) is checked both before dispatching a new task and
// before a worker starts one, so tripping it mid-run stops all further
// dispatch/processing quickly regardless of which goroutine detected it.
func (s *Syncer) runWorkers(ctx context.Context, tasks []fileTask) {
	concurrency := s.cfg.Concurrency
	if concurrency < 1 {
		concurrency = 1
	}

	taskCh := make(chan fileTask)
	var wg sync.WaitGroup
	for i := 0; i < concurrency; i++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			for t := range taskCh {
				if s.aborted.Load() {
					continue
				}
				s.processFileTask(ctx, t)
			}
		}()
	}

	for _, t := range tasks {
		if s.aborted.Load() {
			break
		}
		taskCh <- t
	}
	close(taskCh)
	wg.Wait()
}

func (s *Syncer) processFileTask(ctx context.Context, t fileTask) {
	dc, verifyErr := s.evaluateLocal(t)

	download := true
	if verifyErr != nil {
		s.logWarn("Delta-check I/O error for %s, forcing re-download: %v", t.item.Path, verifyErr)
	} else {
		download = ShouldDownload(dc)
	}

	s.reportProgress(t.item.Path)

	if !download {
		s.mu.Lock()
		s.stats.Skipped++
		s.mu.Unlock()
		return
	}

	outcome := s.withRetry(ctx, t.item.Path, func() error {
		return s.downloadItem(ctx, t.targetPath, t.item, t.exportSpec)
	})

	if outcome.errMsg == "" {
		s.consecutiveLocalFsErrors.Store(0)
		s.mu.Lock()
		s.stats.Updated++
		s.mu.Unlock()
		return
	}

	cls := classify.DriveError(outcome.errMsg)
	s.mu.Lock()
	s.stats.Errors++
	s.stats.FailedFiles = append(s.stats.FailedFiles, report.FailedItem{
		Type:        "file",
		Path:        t.item.Path,
		ID:          optStr(t.item.ID),
		MimeType:    optStr(t.item.MimeType),
		MD5Checksum: optStr(t.item.MD5Checksum),
		Timestamp:   t.item.ModifiedTime,
		Size:        t.item.Size,
		Attempts:    outcome.attempts,
		Reason:      outcome.errMsg,
		Permanent:   !cls.Retryable,
		ErrorReason: optStr(cls.Reason),
		Category:    cls.Category,
	})
	s.mu.Unlock()
	s.logError("Failed to sync %s | attempts=%d | reason=%s", t.item.Path, outcome.attempts, outcome.errMsg)

	if outcome.localFsError {
		n := s.consecutiveLocalFsErrors.Load()
		if classify.ShouldAbortOnLocalFsErrors(int(n), classify.MaxConsecutiveLocalFsErrors) {
			s.triggerAbort()
		}
	}
}

func (s *Syncer) triggerAbort() {
	s.aborted.Store(true)
	s.logError("ABORT: %d lỗi filesystem cục bộ liên tiếp — ổ đích có thể đã unmount/read-only/hỏng: %s", s.consecutiveLocalFsErrors.Load(), s.cfg.Path)
}

func (s *Syncer) reportProgress(path string) {
	n := s.processedCount.Add(1)
	fmt.Fprintf(os.Stderr, "[%d/%d] %s\n", n, s.totalFileTasks, path)
}

// optStr returns nil for "" so JSON encodes it as null (matching PHP's
// nullable id/mimeType/md5Checksum/error_reason fields), or a pointer to a
// copy of v otherwise.
func optStr(v string) *string {
	if v == "" {
		return nil
	}
	return &v
}

func (s *Syncer) writeReports(reportDir string) {
	s.mu.Lock()
	failed := append([]report.FailedItem(nil), s.stats.FailedFiles...)
	s.mu.Unlock()

	if len(failed) == 0 {
		return
	}

	jsonPath, err := report.WriteFailedReportJSON(reportDir, failed, s.cfg.Path, []string{s.cfg.FolderID}, s.runID)
	if err != nil {
		s.logWarn("Could not write failed report JSON: %v", err)
	} else if jsonPath != "" {
		s.logf("Failed-list saved to: %s", jsonPath)
		if _, pruneErr := report.PruneOldFailedReports(reportDir, 10); pruneErr != nil {
			s.logWarn("Could not prune old failed reports: %v", pruneErr)
		}
	}

	csvPath, err := report.WriteFailedCSV(reportDir, failed)
	if err != nil {
		s.logWarn("Could not write failed CSV: %v", err)
	} else if csvPath != "" {
		s.logf("Bảng file lỗi (CSV): %s", csvPath)
	}
}

func (s *Syncer) printDryRun(items []Item) {
	fmt.Println("DRY RUN — listing first 20 items, no download.")
	n := len(items)
	if n > 20 {
		n = 20
	}
	fmt.Printf("%-5s  %-60s  %-45s  %10s  %s\n", "type", "path", "mimeType", "size", "md5")
	for _, it := range items[:n] {
		md5 := it.MD5Checksum
		if len(md5) > 12 {
			md5 = md5[:12]
		}
		fmt.Printf("%-5s  %-60s  %-45s  %10d  %s\n", it.Type, truncate(it.Path, 60), it.MimeType, it.Size, md5)
	}
}

func truncate(s string, n int) string {
	if len(s) <= n {
		return s
	}
	return s[:n]
}

func (s *Syncer) printSummary(duration time.Duration) {
	permanentCount, retryableCount := 0, 0
	for _, f := range s.stats.FailedFiles {
		if f.Permanent {
			permanentCount++
		} else {
			retryableCount++
		}
	}

	fmt.Println()
	fmt.Println(strings.Repeat("=", 50))
	fmt.Println("MIRROR SYNC COMPLETED")
	fmt.Println(strings.Repeat("=", 50))
	fmt.Printf("Folders Created:     %d\n", s.stats.Folders)
	fmt.Printf("Files Updated:       %d\n", s.stats.Updated)
	fmt.Printf("Files Skipped:       %d\n", s.stats.Skipped)
	fmt.Printf("Collisions:          %d\n", s.stats.Collisions)
	fmt.Printf("Errors:              %d (permanent: %d, retryable: %d)\n", s.stats.Errors, permanentCount, retryableCount)
	if n := s.listingRetryHits.Load(); n > 0 {
		fmt.Printf("Listing trả 0 sai %d lần (đã tự verify + khắc phục) — Drive API không ổn định, mirror lần này vẫn đủ.\n", n)
	}
	fmt.Printf("Duration: %s\n", duration.Round(time.Second))
	fmt.Println(strings.Repeat("=", 50))
	fmt.Printf("Storage: %s\n", s.cfg.Path)
}

func (s *Syncer) logf(format string, args ...any) {
	fmt.Fprintf(os.Stderr, "[%s] "+format+"\n", append([]any{s.runID}, args...)...)
}

func (s *Syncer) logWarn(format string, args ...any) {
	fmt.Fprintf(os.Stderr, "[%s] WARN: "+format+"\n", append([]any{s.runID}, args...)...)
}

func (s *Syncer) logError(format string, args ...any) {
	fmt.Fprintf(os.Stderr, "[%s] ERROR: "+format+"\n", append([]any{s.runID}, args...)...)
}
