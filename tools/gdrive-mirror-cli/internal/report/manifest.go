// manifest.go writes the "unexportable" manifest — a Markdown file listing
// every permanent (non-retryable) failed item for a folder, grouped by why
// it can't be mirrored, with a manual-download link per item. Ports
// GDriveMirrorSync::writeUnexportableManifest() /
// buildUnexportableManifest() (PHP source ~L2626/~L2673).
package report

import (
	"fmt"
	"os"
	"path/filepath"
	"sort"
	"strings"
)

// ManifestMeta carries the run-level metadata shown at the top of the
// manifest — mirrors the $meta array GDriveMirrorSync::
// buildUnexportableManifest() takes (generated_at/run_id/folder_ids/
// base_local_path).
type ManifestMeta struct {
	GeneratedAt   string
	RunID         string
	FolderIDs     []string
	BaseLocalPath string
}

// manualDownloadNote gives a short Vietnamese explanation of why an item in
// that group can't be mirrored + what the user needs to do about it, keyed
// by the same permanent classify.DriveError() reason strings PHP's
// $reasonNotes table uses (GDriveMirrorSync::buildUnexportableManifest,
// ~L2673). A reason with no entry here still gets a group heading (from its
// Category), just without the extra guidance line — matches PHP.
var manualDownloadNote = map[string]string{
	"exportSizeLimitExceeded": "QUÁ LỚN để export qua API (giới hạn 10MB của `files.export`) — **tải tay qua trình duyệt được**.",
	"cannotExportFile":        "Bị chủ file KHOÁ — không tải được bằng cách nào, kể cả thủ công.",
	"fileNotExportable":       "Bị chủ file KHOÁ — không tải được bằng cách nào, kể cả thủ công.",
	"cannotDownloadFile":      "Chủ file TẮT quyền tải xuống cho người xem — mirror KHÔNG lấy được. Gỡ bằng cách xin chủ file bật lại \"Viewers can download\" trong setting chia sẻ.",
}

// humanSize formats bytes for human display, matching
// GDriveMirrorSync::humanSize() (PHP source ~L2795) unit-for-unit (same
// B/KB/MB/GB/TB thresholds, one decimal place).
func humanSize(bytes int64) string {
	if bytes <= 0 {
		return "-"
	}
	units := []string{"B", "KB", "MB", "GB", "TB"}
	v := float64(bytes)
	i := 0
	for v >= 1024 && i < len(units)-1 {
		v /= 1024
		i++
	}
	return fmt.Sprintf("%.1f %s", v, units[i])
}

// orDash returns "-" for an empty string, matching PHP's `$meta['x'] ?? '-'`
// fallback used throughout buildUnexportableManifest()'s header block.
func orDash(s string) string {
	if s == "" {
		return "-"
	}
	return s
}

// BuildUnexportableManifest renders the permanent (non-retryable) items in
// items into the manifest's Markdown content, grouped by error_reason
// (falling back to Category when error_reason is nil/empty), preserving
// each group's first-appearance order (not alphabetical — matches PHP) and
// sorting items within a group by size descending. Pure function (no I/O),
// so it's unit-testable without a filesystem — mirrors
// GDriveMirrorSync::buildUnexportableManifest(). Re-filters permanent
// itself (defense-in-depth, same as PHP: a caller passing a mixed slice
// must not leak retryable items into the manifest). Returns "" when there
// are no permanent items — the caller must not write a file in that case.
func BuildUnexportableManifest(items []FailedItem, meta ManifestMeta) string {
	type group struct {
		key   string
		label string
		items []FailedItem
	}

	var groups []group
	groupIndex := map[string]int{}
	permanentCount := 0

	for _, it := range items {
		if !it.Permanent {
			continue
		}
		permanentCount++

		key := it.Category
		if it.ErrorReason != nil && *it.ErrorReason != "" {
			key = *it.ErrorReason
		}
		if idx, ok := groupIndex[key]; ok {
			groups[idx].items = append(groups[idx].items, it)
			continue
		}
		groupIndex[key] = len(groups)
		groups = append(groups, group{key: key, label: it.Category, items: []FailedItem{it}})
	}

	if permanentCount == 0 {
		return ""
	}

	for i := range groups {
		gi := groups[i].items
		sort.SliceStable(gi, func(a, b int) bool { return gi[a].Size > gi[b].Size })
	}

	var b strings.Builder
	b.WriteString("# File KHÔNG THỂ mirror (permanent)\n\n")
	fmt.Fprintf(&b, "- Sinh lúc: %s\n", orDash(meta.GeneratedAt))
	fmt.Fprintf(&b, "- Run ID: %s\n", orDash(meta.RunID))
	folderIDs := "-"
	if len(meta.FolderIDs) > 0 {
		folderIDs = strings.Join(meta.FolderIDs, ", ")
	}
	fmt.Fprintf(&b, "- Folder ID(s): %s\n", folderIDs)
	fmt.Fprintf(&b, "- Base local path: %s\n", orDash(meta.BaseLocalPath))
	fmt.Fprintf(&b, "- Tổng số file: %d\n\n", permanentCount)

	for _, g := range groups {
		count := len(g.items)
		plural := ""
		if count > 1 {
			plural = "s"
		}
		fmt.Fprintf(&b, "## %s (%d file%s)\n\n", g.label, count, plural)
		if note, ok := manualDownloadNote[g.key]; ok {
			b.WriteString(note)
			b.WriteString("\n\n")
		}

		for _, it := range g.items {
			path := it.Path
			if path == "" {
				path = "?"
			}
			// Not markdown-escaped — matches the PHP source, which writes
			// `path` raw too. These are illustrative filenames inside a flat
			// list item (not a table cell), so a stray `|`/`[`/`]`/backtick
			// can't corrupt the surrounding list/heading structure; worst
			// case it just renders as a literal character.
			fmt.Fprintf(&b, "- [%s] %s\n", humanSize(it.Size), path)

			if it.ID != nil && *it.ID != "" {
				mime := ""
				if it.MimeType != nil {
					mime = *it.MimeType
				}
				url, ext := manualDownloadURL(*it.ID, mime, it.Path)
				fmt.Fprintf(&b, "  → %s  (tải .%s)\n", url, ext)
			}
		}
		b.WriteString("\n")
	}

	return strings.TrimRight(b.String(), "\n") + "\n"
}

// WriteUnexportableManifest writes (or deletes, if there's nothing left to
// report) the folder-keyed Markdown manifest to
// dir/unexportable/<folderTag>.md and returns the path written ("" if
// nothing was written). Unlike the JSON/CSV/XLSX reports — archived
// per-run, pruned to the last 10 — this file is ALWAYS OVERWRITTEN and
// keyed by folder (report.FolderTag), not timestamp: it's meant to reflect
// the folder's CURRENT state ("what's still missing right now"), not a
// history of every run. When a folder has no permanent failures anymore, a
// stale manifest from an earlier run is deleted so the file's mere
// existence keeps meaning "there's still something to fix by hand". Mirrors
// GDriveMirrorSync::writeUnexportableManifest().
func WriteUnexportableManifest(dir string, items []FailedItem, meta ManifestMeta) (string, error) {
	manifestDir := filepath.Join(dir, "unexportable")
	path := filepath.Join(manifestDir, FolderTag(meta.FolderIDs)+".md")

	content := BuildUnexportableManifest(items, meta)
	if content == "" {
		if _, statErr := os.Stat(path); statErr == nil {
			if rmErr := os.Remove(path); rmErr != nil {
				return "", fmt.Errorf("remove stale manifest: %w", rmErr)
			}
		}
		return "", nil
	}

	if err := os.MkdirAll(manifestDir, 0o755); err != nil {
		return "", fmt.Errorf("create manifest dir: %w", err)
	}
	if err := os.WriteFile(path, []byte(content), 0o644); err != nil {
		return "", fmt.Errorf("write manifest: %w", err)
	}
	return path, nil
}
