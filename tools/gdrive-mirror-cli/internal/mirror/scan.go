// scan.go implements --scan-duplicates: a READ-ONLY report of local files
// under --path that no longer correspond to anything in the CURRENT Drive
// listing — the retroactive counterpart to applyKnownRename (sync.go),
// which only prevents NEW duplicates from here on. A tree mirrored before
// rename-tracking existed (or any tree where a file was renamed on Drive
// without the local copy ever getting the chance to be moved — e.g. a run
// that failed partway) can already have stale orphans sitting on disk;
// this never moves or deletes anything, only lists what it finds so a
// human can confirm and clean up by hand.
//
// Two categories, by how confident the match is:
//   - Confirmed: the orphan's own MD5 hash matches EXACTLY a MD5 Drive
//     currently reports for a known item — only possible for regular files
//     (Google-native files — Docs/Sheets/Slides/Drawings/Script — have no
//     md5Checksum on the Drive side at all, so this can never prove a
//     match for the exact .docx/.xlsx/.pptx exports this feature was asked
//     for; see Suspected below).
//   - Suspected: everything else not currently expected on disk. Reported
//     with context (size, mtime, and — when a current item shares the same
//     parent folder and extension — a hint pointing at it) but NEVER
//     claimed as a confirmed match, since there is no way to prove it
//     without Drive-side content identity to compare against.
package mirror

import (
	"context"
	"fmt"
	"io/fs"
	"path/filepath"
	"strings"
	"time"

	"github.com/vswb/gdrive-mirror/internal/localpath"
)

// DuplicateMatch is one orphan local file whose content exactly matches
// (same MD5) a Drive item currently listed at a DIFFERENT path.
type DuplicateMatch struct {
	OrphanAbs   string // absolute local path of the extra file
	MatchesID   string // Drive file ID it matches
	MatchesPath string // that item's CURRENT correct relative path
}

// SuspectedOrphan is a local file not accounted for by the current Drive
// listing, but whose relationship to any current item could not be proven
// (no remote MD5 to compare against — the common case for Google-native
// exports).
type SuspectedOrphan struct {
	Abs     string
	Size    int64
	ModTime time.Time
	Hint    string // "" when nothing nearby suggests a specific origin
}

// DuplicateScanResult is ScanDuplicates' full report for one folder.
type DuplicateScanResult struct {
	Confirmed []DuplicateMatch
	Suspected []SuspectedOrphan
}

// isScratchTempFile recognizes this tool's own in-flight atomic-download
// temp files (see tempDownloadPath) so a run caught mid-download by the
// scan never reports its own scratch file as an "orphan".
func isScratchTempFile(name string) bool {
	return strings.HasPrefix(name, ".") && strings.HasSuffix(name, ".tmp")
}

// ScanDuplicates lists the CURRENT Drive tree (same recursive listing Run()
// uses — always a full listing, independent of --incremental, since this
// has to compare against the complete picture) and walks --path to find
// local files that listing no longer accounts for. Read-only: never writes,
// moves, or deletes anything on disk, and never touches the --incremental/
// rename-tracking index (this is a diagnostic pass, not a sync).
func (s *Syncer) ScanDuplicates(ctx context.Context) (DuplicateScanResult, error) {
	localPrefix := localpath.TruncateComponent(localpath.SanitizeComponent(s.resolveFolderName(ctx)), 0)

	items, err := s.ListFolderRecursive(ctx, s.cfg.FolderID, "")
	if err != nil {
		return DuplicateScanResult{}, fmt.Errorf("list folder: %w", err)
	}

	expected := make(map[string]Item, len(items)) // absPath -> current item
	md5Index := map[string][]Item{}               // md5 -> current regular-file items sharing it
	byDir := map[string][]Item{}                  // dir abs path -> current file items directly inside (for hints)

	for _, it := range items {
		absPath, perr := localpath.SafeJoin(s.cfg.Path, filepath.Join(localPrefix, filepath.FromSlash(it.Path)))
		if perr != nil {
			// Same class of malformed name buildPlan already rejects and
			// reports elsewhere — not this read-only scan's job to flag
			// again, and definitely not safe to guess a path for.
			continue
		}
		expected[absPath] = it
		if it.Type != "file" {
			continue
		}
		byDir[filepath.Dir(absPath)] = append(byDir[filepath.Dir(absPath)], it)
		if it.MD5Checksum != "" {
			md5Index[it.MD5Checksum] = append(md5Index[it.MD5Checksum], it)
		}
	}

	root := filepath.Join(s.cfg.Path, localPrefix)
	var result DuplicateScanResult

	walkErr := filepath.WalkDir(root, func(path string, d fs.DirEntry, err error) error {
		if err != nil {
			// Best-effort: one unreadable entry (permission, dangling
			// symlink...) must never abort the whole scan.
			return nil
		}
		if d.IsDir() {
			return nil
		}
		if isScratchTempFile(d.Name()) {
			return nil
		}
		if _, ok := expected[path]; ok {
			return nil // accounted for by the current listing
		}

		if matches, ok := matchByContent(path, md5Index); ok {
			for _, m := range matches {
				result.Confirmed = append(result.Confirmed, DuplicateMatch{
					OrphanAbs:   path,
					MatchesID:   m.ID,
					MatchesPath: m.Path,
				})
			}
			return nil
		}

		var size int64
		var modTime time.Time
		if info, ierr := d.Info(); ierr == nil {
			size = info.Size()
			modTime = info.ModTime()
		}

		result.Suspected = append(result.Suspected, SuspectedOrphan{
			Abs:     path,
			Size:    size,
			ModTime: modTime,
			Hint:    sameFolderSameExtHint(path, byDir),
		})
		return nil
	})
	if walkErr != nil {
		return result, fmt.Errorf("walk --path: %w", walkErr)
	}

	return result, nil
}

// matchByContent hashes path's actual on-disk content (only once it's
// already known not to be at any currently-expected path) and looks it up
// against md5Index. A read/hash error (unreadable file, vanished mid-walk)
// just yields no match — never an error that would abort the scan.
func matchByContent(path string, md5Index map[string][]Item) ([]Item, bool) {
	sum, err := md5File(path)
	if err != nil {
		return nil, false
	}
	m, ok := md5Index[sum]
	return m, ok
}

// sameFolderSameExtHint gives a best-effort, explicitly-unproven pointer:
// when a currently-listed file sits in the SAME directory and shares the
// same extension as the orphan, it's a plausible (not confirmed) origin —
// most useful for the exact case this feature exists for, a Google-native
// export (.docx/.xlsx/.pptx/...) with no Drive MD5 to actually prove it.
func sameFolderSameExtHint(orphanAbs string, byDir map[string][]Item) string {
	ext := strings.ToLower(filepath.Ext(orphanAbs))
	if ext == "" {
		return ""
	}
	for _, sib := range byDir[filepath.Dir(orphanAbs)] {
		if strings.ToLower(filepath.Ext(sib.Path)) == ext {
			return fmt.Sprintf(
				"cùng thư mục, cùng đuôi %s với %q (id=%s) đang có trên Drive — CÓ THỂ là bản cũ trước khi đổi tên, "+
					"nhưng không có MD5 từ Drive để xác nhận tự động (thường là file Google-native) — tự mở so sánh trước khi xoá.",
				ext, sib.Path, sib.ID)
		}
	}
	return ""
}

// RenderDuplicateScanReport renders one folder's DuplicateScanResult as
// plain text for the console. Pure (no I/O), kept separate from
// ScanDuplicates so the exact wording is unit-testable without a
// filesystem/Drive call.
func RenderDuplicateScanReport(result DuplicateScanResult, basePath string) string {
	var b strings.Builder
	b.WriteString("=== Quét file nghi trùng (so với listing Drive hiện tại) ===\n")

	if len(result.Confirmed) == 0 && len(result.Suspected) == 0 {
		b.WriteString("Không có file local nào thừa so với listing Drive hiện tại.\n")
		return b.String()
	}

	if len(result.Confirmed) > 0 {
		fmt.Fprintf(&b, "\nXÁC NHẬN TRÙNG (MD5 khớp đúng với bản Drive đang có — an toàn để xoá tay):\n")
		for _, m := range result.Confirmed {
			fmt.Fprintf(&b, "  - %s\n      trùng nội dung với id=%s, Drive hiện liệt kê ở: %s\n",
				relOrAbs(m.OrphanAbs, basePath), m.MatchesID, m.MatchesPath)
		}
	}

	if len(result.Suspected) > 0 {
		fmt.Fprintf(&b, "\nNGHI NGỜ — CẦN TỰ SOÁT (không có MD5 Drive để xác nhận tự động, vd file Google-native .docx/.xlsx/.pptx):\n")
		for _, o := range result.Suspected {
			fmt.Fprintf(&b, "  - %s (size=%d byte, sửa lúc %s)\n", relOrAbs(o.Abs, basePath), o.Size, o.ModTime.Format(time.RFC3339))
			if o.Hint != "" {
				fmt.Fprintf(&b, "      %s\n", o.Hint)
			}
		}
	}

	b.WriteString("\nTool KHÔNG tự xoá/di chuyển gì ở đây — đây chỉ là báo cáo để bạn tự xác nhận rồi xoá tay.\n")
	return b.String()
}

func relOrAbs(path, base string) string {
	if rel, err := filepath.Rel(base, path); err == nil && !strings.HasPrefix(rel, "..") {
		return rel
	}
	return path
}
