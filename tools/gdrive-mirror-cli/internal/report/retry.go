// retry.go loads a failed-report JSON file (as written by
// WriteFailedReportJSON) back in for the --retry-failed re-run mode. Ports
// the JSON-loading half of GDriveMirrorSync::handle()'s "2b. --retry-failed"
// block (PHP source ~L565-598) — the shape was already compatible (see
// FailedReport/FailedItem in report.go), this file is the reader.
package report

import (
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"sort"
)

// ParseFailedReport parses raw failed-report JSON bytes into a FailedReport.
// Pure — no I/O — so malformed input is exercised in tests without touching
// a filesystem. Mirrors the PHP source's validation
// (`! is_array($json) || ! isset($json['items']) || ! is_array($json['items'])`):
// invalid JSON or a missing/wrong-typed "items" field is a clear error, but
// items being present and simply empty is NOT an error — that's a valid
// "nothing failed" or "everything already retried" report, and the caller
// decides what to do with zero items (matches PHP: it falls through to
// "Nothing left to retry", not a failure).
func ParseFailedReport(data []byte) (FailedReport, error) {
	var fr FailedReport
	if err := json.Unmarshal(data, &fr); err != nil {
		return FailedReport{}, fmt.Errorf("parse failed-report JSON: %w", err)
	}
	if fr.Items == nil {
		return FailedReport{}, fmt.Errorf(`parse failed-report JSON: missing or invalid "items" array`)
	}
	return fr, nil
}

// FilterRetryItems drops permanent items unless includePermanent is true —
// retrying a permanent failure (locked file, size-limit export, revoked
// permission…) cannot succeed, so retrying it wastes time by default.
// Mirrors GDriveMirrorSync::handle()'s array_filter over
// `! empty($item['permanent'])`. An item with Permanent==false (including
// pre-classification report entries, which round-trip as false, not null —
// see FailedItem.Permanent) is always kept, matching the PHP source's
// documented backward-compat note ("item cũ không có field này = coi như
// retryable").
func FilterRetryItems(items []FailedItem, includePermanent bool) (kept []FailedItem, skippedPermanent int) {
	if includePermanent {
		return items, 0
	}
	kept = make([]FailedItem, 0, len(items))
	for _, it := range items {
		if it.Permanent {
			skippedPermanent++
			continue
		}
		kept = append(kept, it)
	}
	return kept, skippedPermanent
}

// ResolveRetryFailedPath resolves a user-supplied --retry-failed argument to
// one concrete JSON file: itself when it already points at a file, or the
// most-recently-modified failed-*.json inside it when it's a directory —
// lets a user point --retry-failed straight at the reports directory
// without having to go find the exact filename.
func ResolveRetryFailedPath(path string) (string, error) {
	fi, err := os.Stat(path)
	if err != nil {
		return "", fmt.Errorf("--retry-failed: %w", err)
	}
	if !fi.IsDir() {
		return path, nil
	}

	matches, err := filepath.Glob(filepath.Join(path, "failed-*.json"))
	if err != nil {
		return "", fmt.Errorf("--retry-failed: glob %s: %w", path, err)
	}
	if len(matches) == 0 {
		return "", fmt.Errorf("--retry-failed: no failed-*.json report found in directory %s", path)
	}

	type fileInfo struct {
		path    string
		modTime int64
	}
	infos := make([]fileInfo, 0, len(matches))
	for _, m := range matches {
		st, statErr := os.Stat(m)
		if statErr != nil {
			continue
		}
		infos = append(infos, fileInfo{path: m, modTime: st.ModTime().UnixNano()})
	}
	if len(infos) == 0 {
		return "", fmt.Errorf("--retry-failed: no readable failed-*.json report found in directory %s", path)
	}
	sort.Slice(infos, func(i, j int) bool { return infos[i].modTime > infos[j].modTime })

	return infos[0].path, nil
}
