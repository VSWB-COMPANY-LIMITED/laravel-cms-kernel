// preload.go supports the --retry-failed re-run mode: converting items
// loaded from a prior failed-report JSON (internal/report) back into the
// Item shape buildPlan expects, and RunRetry — the retry-mode counterpart
// of Run() that skips remote listing entirely. Ports the "2b. --retry-failed"
// branch of GDriveMirrorSync::handle() (PHP source ~L565-598, ~L674-680,
// ~L738-740).
package mirror

import (
	"context"
	"time"

	"github.com/vswb/gdrive-mirror/internal/localpath"

	"github.com/vswb/gdrive-mirror/internal/report"
)

// ItemFromFailed converts one report.FailedItem (loaded from a
// --retry-failed JSON report) into the Item shape buildPlan expects. The
// nullable pointer fields (ID/MimeType/MD5Checksum) — null in the JSON for
// e.g. a rejected-path failure that never had a Drive ID — deref to "",
// same zero value a live Drive listing would never produce in the first
// place, so buildPlan doesn't need to special-case retry-loaded items.
func ItemFromFailed(it report.FailedItem) Item {
	return Item{
		Type:         it.Type,
		Path:         it.Path,
		ID:           derefStr(it.ID),
		MimeType:     derefStr(it.MimeType),
		MD5Checksum:  derefStr(it.MD5Checksum),
		ModifiedTime: it.Timestamp,
		Size:         it.Size,
	}
}

// ItemsFromFailed converts a slice of report.FailedItem into Items,
// silently dropping any entry with an empty Path — mirrors
// GDriveMirrorSync::handle()'s `if ($relativePath === null) { continue; }`
// guard against a malformed/hand-edited report entry that has no path at
// all (nothing sensible to download).
func ItemsFromFailed(failed []report.FailedItem) []Item {
	items := make([]Item, 0, len(failed))
	for _, f := range failed {
		if f.Path == "" {
			continue
		}
		items = append(items, ItemFromFailed(f))
	}
	return items
}

func derefStr(p *string) string {
	if p == nil {
		return ""
	}
	return *p
}

// RunRetry executes one mirror pass from a caller-supplied item list instead
// of a live Drive listing — the --retry-failed re-run mode.
//
// localPrefix MUST be the same top-level directory name the original run
// used, because Item.Path is relative to THAT, not to --path. An earlier
// version forced it to "" and every retried file landed one directory too
// high — a silent re-download into the wrong place (caught in review
// 2026-07-19 by asserting buildPlan's retry target equals its normal-run
// target for the same item). Callers pass report.FailedReport.LocalPrefix;
// when replaying a report written before that field existed it is empty, and
// resolveRetryPrefix falls back to asking Drive for the folder's name — the
// exact same source Run() derives it from.
//
// Every item still goes through buildPlan → localpath.SafeJoin exactly like
// a live listing does — a hand-edited JSON report is not a trusted input,
// see ItemFromFailed doc.
func (s *Syncer) RunRetry(ctx context.Context, items []Item, folderIDs []string, localPrefix string) (Stats, error) {
	started := time.Now()

	if err := s.preflight(); err != nil {
		return s.stats, err
	}

	prefix := s.resolveRetryPrefix(ctx, localPrefix)

	s.stats.TotalListed = len(items)
	s.logf("RETRY-FAILED mode: %d item(s) loaded (thư mục gốc: %q).", len(items), prefix)

	return s.runTasksAndReport(ctx, items, prefix, folderIDs, started)
}

// resolveRetryPrefix returns the recorded prefix when the report carried one,
// otherwise re-derives it from Drive exactly as Run() does. Sanitizing again
// is harmless (the function is idempotent) and protects against a report
// whose local_prefix was hand-edited into something unsafe.
func (s *Syncer) resolveRetryPrefix(ctx context.Context, recorded string) string {
	if recorded == "" {
		s.logWarn("Report không có 'local_prefix' (bản cũ) — hỏi lại Drive tên thư mục gốc để dựng đúng đường dẫn cũ.")
		recorded = s.resolveFolderName(ctx)
	}
	return localpath.TruncateComponent(localpath.SanitizeComponent(recorded), 0)
}
