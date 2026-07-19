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
	"github.com/vswb/gdrive-mirror/internal/localpath"
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
	// localPrefix is the sanitized top-level dir every target sits under; it
	// goes into the failed report so a later --retry-failed run rebuilds the
	// exact same paths (see report.FailedReport.LocalPrefix).
	localPrefix    string
	processedCount atomic.Int64
	totalFileTasks int
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

// Run executes one full mirror pass (live Drive listing) and returns the
// accumulated stats. For the --retry-failed re-run mode (no listing, items
// loaded from a prior JSON report) see RunRetry.
func (s *Syncer) Run(ctx context.Context) (Stats, error) {
	started := time.Now()

	if err := s.preflight(); err != nil {
		return s.stats, err
	}

	// The synced folder's own name comes straight from Drive and needs the
	// same sanitize+truncate treatment as every other path component (§C1-3/
	// L1-3) — it becomes the top-level directory every downloaded item lands
	// under, so leaving it unsanitized would defeat the point.
	localPrefix := localpath.TruncateComponent(localpath.SanitizeComponent(s.resolveFolderName(ctx)), 0)

	s.logf("Fetching remote item list (recursive) via Drive API...")
	items, err := s.ListFolderRecursive(ctx, s.cfg.FolderID, "")
	if err != nil {
		return s.stats, fmt.Errorf("list folder: %w", err)
	}
	s.stats.TotalListed = len(items)
	s.logf("Found %d item(s).", len(items))

	folderIDs := []string{s.cfg.FolderID}

	// BUG A-3 (PHP source) — last-resort safety net: compare this folder's
	// item count against the previous run's, independent of WHY it might
	// have shrunk (unlike the "verify on zero" retry in ListFolderRecursive,
	// which only catches the ONE known cause — Drive returning an empty
	// page under load). Placed BEFORE --limit/--dry-run below, same as the
	// PHP source: --limit deliberately truncates the list for testing and
	// must never be misread as "the tree really shrank", and --dry-run must
	// never write state from a throwaway listing.
	if aborted := s.applyShrinkGuard(len(items)); aborted {
		reportDir := s.reportDir()
		s.writeReports(reportDir, folderIDs)
		s.printSummary(time.Since(started))
		return s.stats, nil
	}

	return s.runTasksAndReport(ctx, items, localPrefix, folderIDs, started)
}

// applyShrinkGuard compares currentCount against the previous run's stored
// item count (internal/report state file) via classify.ShouldAbortOnShrink,
// and records the abort on s.stats when it trips. Returns true when the
// caller must skip downloading anything for this folder — the folder is
// left untouched and its state is NOT overwritten, so a genuinely-bad
// listing never poisons the baseline the NEXT run compares against.
//
// A no-op (always returns false, never reads/writes state) in --dry-run: a
// dry run is just a debug listing, not a real sync, and must not influence
// future shrink-guard decisions — mirrors GDriveMirrorSync::handle()'s
// $isFullSync guard.
func (s *Syncer) applyShrinkGuard(currentCount int) bool {
	if s.cfg.DryRun {
		return false
	}

	folderTag := report.FolderTag([]string{s.cfg.FolderID})
	stateDir := filepath.Join(s.reportDir(), "state")

	var prevCount *int
	if prev := report.ReadListingState(stateDir, folderTag); prev != nil {
		pc := prev.ItemCount
		prevCount = &pc
	}

	if !s.cfg.IgnoreShrink && classify.ShouldAbortOnShrink(prevCount, currentCount, classify.ListingShrinkAbortRatio) {
		dropPct := 0.0
		if prevCount != nil && *prevCount > 0 {
			dropPct = (1 - float64(currentCount)/float64(*prevCount)) * 100
		}
		s.logError("ABORT folder %s: lần trước %d item, lần này %d item (giảm %.1f%%) — nghi liệt kê Drive bị cụt (mất quyền truy cập / lỗi API tạm thời), KHÔNG mirror đè lên cây có thể đang thiếu dữ liệu. Nếu bạn CHẮC CHẮN vừa tự xoá bớt file/folder trên Drive, chạy lại với --ignore-shrink.",
			s.cfg.FolderID, prevCountOrZero(prevCount), currentCount, dropPct)
		s.stats.Aborted = true
		s.stats.AbortReason = "shrink-guard"
		return true
	}

	if err := report.WriteListingState(stateDir, folderTag, s.cfg.FolderID, currentCount, s.runID); err != nil {
		s.logWarn("Could not write listing state: %v", err)
	}
	return false
}

func prevCountOrZero(p *int) int {
	if p == nil {
		return 0
	}
	return *p
}

// preflight verifies the destination directory can actually be written to,
// before any listing/download work starts. A no-op in --dry-run (a dry run
// never writes anything to --path).
func (s *Syncer) preflight() error {
	if s.cfg.DryRun {
		return nil
	}
	if err := os.MkdirAll(s.cfg.Path, 0o755); err != nil {
		return fmt.Errorf("preflight: cannot create destination dir %s: %w", s.cfg.Path, err)
	}
	// A real write→read→delete probe catches a disk that reports writable
	// bits but is actually wedged/read-only/dead — os.MkdirAll succeeding
	// above is not proof the disk can really take writes (see WriteProbe doc).
	if !WriteProbe(s.cfg.Path, s.runID) {
		return fmt.Errorf("preflight: destination not writable (read-only/I/O error/hung disk): %s", s.cfg.Path)
	}
	return nil
}

// reportDir is the shared parent for every report this package writes
// (failed JSON/CSV/XLSX, unexportable manifest, listing state) — the
// sibling "gdrive-mirror-reports" directory next to --path.
func (s *Syncer) reportDir() string {
	return filepath.Join(filepath.Dir(strings.TrimRight(s.cfg.Path, string(filepath.Separator))), "gdrive-mirror-reports")
}

// runTasksAndReport is the shared tail of Run() (live-listed items) and
// RunRetry() (items loaded from a --retry-failed JSON): build the download
// plan, execute it, write reports, print the summary. The two callers only
// differ in how items/localPrefix were obtained, not in how they're
// processed from here on.
func (s *Syncer) runTasksAndReport(ctx context.Context, items []Item, localPrefix string, folderIDs []string, started time.Time) (Stats, error) {
	if s.cfg.Limit > 0 && len(items) > s.cfg.Limit {
		items = items[:s.cfg.Limit]
		s.logf("--limit=%d: processing first %d item(s) only.", s.cfg.Limit, len(items))
	}

	if s.cfg.DryRun {
		s.printDryRun(items)
		return s.stats, nil
	}

	s.localPrefix = localPrefix
	tasks := s.buildPlan(items, localPrefix)
	s.stats.Processed = len(items)

	if len(tasks) > 0 && !s.aborted.Load() {
		s.totalFileTasks = len(tasks)
		s.logf("Starting synchronization of %d file(s) with concurrency=%d...", len(tasks), s.cfg.Concurrency)
		s.runWorkers(ctx, tasks)
	}
	if s.aborted.Load() {
		s.stats.Aborted = true
		s.stats.AbortReason = "local-fs-circuit-breaker"
	}

	reportDir := s.reportDir()
	s.writeReports(reportDir, folderIDs)
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

// buildPlan walks items in listing order, sequentially (mkdir is cheap),
// creating directories and resolving each file's final target path —
// including shortcut skipping. Actual network downloads happen later in
// runWorkers so they can run concurrently.
//
// Name collision resolution (sanitize/truncate/dedup) now happens up front
// at listing time (see list.go resolveSiblingNames) — it.Path arrives here
// already safe and unique among its siblings. The `used` map below is
// defense-in-depth only, in case something upstream missed a case; it must
// never silently overwrite (that was the original C1/C2/C3 bug), so a hit
// here is logged loudly and failed instead.
func (s *Syncer) buildPlan(items []Item, localPrefix string) []fileTask {
	used := map[string]string{} // resolved target path -> Drive file ID
	var tasks []fileTask

	for _, it := range items {
		absPath, pathErr := localpath.SafeJoin(s.cfg.Path, filepath.Join(localPrefix, filepath.FromSlash(it.Path)))
		if pathErr != nil {
			// A crafted/malformed Drive name escaping --path is a permanent,
			// non-filesystem-health problem — it must never count toward the
			// local-fs circuit breaker (that breaker exists to detect a dying
			// disk, not a hostile/malformed remote name).
			s.recordPathFailure(it, pathErr)
			continue
		}

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

		if prevID, exists := used[absPath]; exists && prevID != it.ID {
			s.recordUnexpectedCollision(it, prevID, absPath)
			continue
		}
		used[absPath] = it.ID

		tasks = append(tasks, fileTask{item: it, targetPath: absPath, exportSpec: exportSpec})
	}

	return tasks
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

// recordPathFailure records a localpath.SafeJoin rejection (traversal
// attempt / escaping --path). Always permanent — retrying can't fix a
// structurally malformed name — and deliberately does NOT touch
// consecutiveLocalFsErrors: this is a Drive-side data problem, not evidence
// the destination disk is failing, so it must never contribute to the
// local-fs circuit breaker.
func (s *Syncer) recordPathFailure(it Item, err error) {
	s.mu.Lock()
	s.stats.Errors++
	s.stats.FailedFiles = append(s.stats.FailedFiles, report.FailedItem{
		Type:      it.Type,
		Path:      it.Path,
		ID:        optStr(it.ID),
		MimeType:  optStr(it.MimeType),
		Reason:    err.Error(),
		Permanent: true,
		Category:  "Local path rejected",
	})
	s.mu.Unlock()
	s.logError("Rejected local path for %s (id=%s): %v", it.Path, it.ID, err)
}

// recordUnexpectedCollision fires only if two items still resolve to the
// same absPath after list.go's resolveSiblingNames has already deduped
// every folder's direct children — i.e. a bug upstream, not a normal Drive
// occurrence. Logged loudly and failed rather than silently overwritten,
// same principle as every other failure path in this package.
func (s *Syncer) recordUnexpectedCollision(it Item, prevID string, absPath string) {
	s.mu.Lock()
	s.stats.Errors++
	s.stats.FailedFiles = append(s.stats.FailedFiles, report.FailedItem{
		Type:      "file",
		Path:      it.Path,
		ID:        optStr(it.ID),
		MimeType:  optStr(it.MimeType),
		Reason:    fmt.Sprintf("local path already used by another item (id=%s) — listing-time dedup should have prevented this", prevID),
		Permanent: true,
		Category:  "Local name collision (unexpected)",
	})
	s.mu.Unlock()
	s.logError("UNEXPECTED collision at plan time (listing dedup should have prevented this): %s already claimed by id=%s, skipping id=%s", absPath, prevID, it.ID)
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

// writeReports writes every end-of-run report, tagged under folderIDs (see
// report.FolderTag) — the caller's single-element slice for a normal Run(),
// or the retry JSON's own folder_ids for RunRetry().
func (s *Syncer) writeReports(reportDir string, folderIDs []string) {
	s.mu.Lock()
	failed := append([]report.FailedItem(nil), s.stats.FailedFiles...)
	s.mu.Unlock()

	// Unexportable manifest is evaluated UNCONDITIONALLY, even when failed
	// is empty this run — a folder that had permanent failures before but
	// is now clean must have its stale manifest deleted (see
	// WriteUnexportableManifest doc), so this can't sit behind the
	// len(failed)==0 early-return below like the other three reports.
	meta := report.ManifestMeta{
		GeneratedAt:   time.Now().Format(time.RFC3339),
		RunID:         s.runID,
		FolderIDs:     folderIDs,
		BaseLocalPath: s.cfg.Path,
	}
	if manifestPath, err := report.WriteUnexportableManifest(reportDir, failed, meta); err != nil {
		s.logWarn("Could not write unexportable manifest: %v", err)
	} else if manifestPath != "" {
		s.logf("File KHÔNG THỂ mirror (permanent) — chi tiết + link tải tay: %s", manifestPath)
	}

	if len(failed) == 0 {
		return
	}

	jsonPath, err := report.WriteFailedReportJSON(reportDir, failed, s.cfg.Path, s.localPrefix, folderIDs, s.runID)
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

	xlsxPath, err := report.WriteFailedXLSX(reportDir, failed)
	if err != nil {
		s.logWarn("Could not write failed XLSX: %v", err)
	} else if xlsxPath != "" {
		s.logf("Bảng file lỗi (XLSX): %s", xlsxPath)
		if _, pruneErr := report.PruneOldFailedXLSX(reportDir, 10); pruneErr != nil {
			s.logWarn("Could not prune old failed XLSX reports: %v", pruneErr)
		}
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
	if s.stats.Aborted {
		fmt.Printf("Aborted:             true (%s)\n", s.stats.AbortReason)
	}
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
