// duplicates.go writes the "name-duplicates" report — every pair of Drive
// items this run found sharing the EXACT same original (pre-sanitize) name
// inside the same parent folder. This is a DIFFERENT problem from the
// name-collision handling in internal/mirror/list.go (which safely renames
// the second item so nothing is silently overwritten/merged): Drive itself
// allows two genuinely DIFFERENT items — different file IDs, often
// different content — to carry the identical name in the same folder (a
// human "Save As"-ing the same name twice is the common real-world cause).
// The tool mirrors both sides correctly, but a human still needs to look at
// Drive and decide whether one side is redundant — this report is the
// triage list for that.
//
// Deliberately excludes a collision that was merely an ARTIFACT of
// localpath.SanitizeComponent (e.g. "a:b.pdf" and "a_b.pdf" both sanitizing
// to "a_b.pdf") — that pair is two genuinely different Drive files, not a
// real duplicate, and listing it here would just be noise a human would
// learn to ignore (and then miss the real ones).
package report

import (
	"fmt"
	"os"
	"path/filepath"
	"strings"
)

// NameDuplicateEntry is one side of a NameDuplicate pair.
type NameDuplicateEntry struct {
	ID       string
	Path     string
	Size     int64
	MimeType string
}

// NameDuplicate is one pair of direct-sibling Drive items sharing the
// identical original name. Kept is whichever sorted first by Drive file ID
// ascending (same order internal/mirror's resolveSiblingNames uses) — it
// kept the plain name; Renamed is the other one, the one that got the
// ID-suffix.
type NameDuplicate struct {
	FolderPath string // relative path of the shared parent folder ("" = root)
	Name       string // the original (pre-sanitize) Drive name both share
	Kept       NameDuplicateEntry
	Renamed    NameDuplicateEntry
}

func writeDupSide(b *strings.Builder, label string, e NameDuplicateEntry) {
	url := buildManualDownloadURL(e.ID, e.MimeType)
	fmt.Fprintf(b, "%s: [%s] %s\n    → %s\n", label, humanSize(e.Size), e.Path, url)
}

// BuildNameDuplicatesReport renders dups into Markdown, grouped by shared
// parent folder (first-appearance order, matching BuildUnexportableManifest's
// style). Pure function — no I/O. Returns "" when dups is empty; the caller
// must not write a file in that case.
func BuildNameDuplicatesReport(dups []NameDuplicate, meta ManifestMeta) string {
	if len(dups) == 0 {
		return ""
	}

	type group struct {
		folder string
		items  []NameDuplicate
	}
	var groups []group
	groupIndex := map[string]int{}
	for _, d := range dups {
		if idx, ok := groupIndex[d.FolderPath]; ok {
			groups[idx].items = append(groups[idx].items, d)
			continue
		}
		groupIndex[d.FolderPath] = len(groups)
		groups = append(groups, group{folder: d.FolderPath, items: []NameDuplicate{d}})
	}

	var b strings.Builder
	b.WriteString("# Trùng tên THẬT trên Drive (cần người soát, tool KHÔNG tự xoá)\n\n")
	fmt.Fprintf(&b, "- Sinh lúc: %s\n", orDash(meta.GeneratedAt))
	fmt.Fprintf(&b, "- Run ID: %s\n", orDash(meta.RunID))
	folderIDs := "-"
	if len(meta.FolderIDs) > 0 {
		folderIDs = strings.Join(meta.FolderIDs, ", ")
	}
	fmt.Fprintf(&b, "- Folder ID(s): %s\n", folderIDs)
	fmt.Fprintf(&b, "- Base local path: %s\n", orDash(meta.BaseLocalPath))
	fmt.Fprintf(&b, "- Tổng số cặp: %d\n\n", len(dups))
	b.WriteString("Mỗi cặp dưới đây là 2 Drive item KHÁC NHAU (ID khác, thường nội dung cũng khác) " +
		"mang đúng tên gốc giống nhau trên Drive — tool đã mirror an toàn cả hai (không đè/mất gì), " +
		"chỉ gắn hậu tố ID cho bản đến sau. KHÔNG bao gồm trùng do sanitize ký tự (ví dụ `a:b.pdf` và " +
		"`a_b.pdf` cùng thành `a_b.pdf`) — đó là 2 file khác nhau thật, không phải duplicate. Mở cả 2 " +
		"link dưới, so nội dung, rồi tự xoá bản dư TRÊN DRIVE (không phải ở đây) nếu đúng là trùng.\n\n")

	for _, g := range groups {
		label := g.folder
		if label == "" {
			label = "(gốc)"
		}
		fmt.Fprintf(&b, "## %s (%d cặp)\n\n", label, len(g.items))
		for _, d := range g.items {
			fmt.Fprintf(&b, "- **%s**\n", d.Name)
			writeDupSide(&b, "  Giữ nguyên tên", d.Kept)
			writeDupSide(&b, "  Đổi tên (hậu tố ID)", d.Renamed)
		}
		b.WriteString("\n")
	}

	return strings.TrimRight(b.String(), "\n") + "\n"
}

// WriteNameDuplicatesReport writes (or deletes, if dups is empty) the
// folder-keyed Markdown report to dir/name-duplicates/<folderTag>.md.
// ALWAYS OVERWRITTEN, same lifecycle as WriteUnexportableManifest — reflects
// the folder's CURRENT state, not run history, so a pair resolved on Drive
// since the last run drops off, and the file itself is deleted once nothing
// is left to flag.
func WriteNameDuplicatesReport(dir string, dups []NameDuplicate, meta ManifestMeta) (string, error) {
	reportDir := filepath.Join(dir, "name-duplicates")
	path := filepath.Join(reportDir, FolderTag(meta.FolderIDs)+".md")

	content := BuildNameDuplicatesReport(dups, meta)
	if content == "" {
		if _, statErr := os.Stat(path); statErr == nil {
			if rmErr := os.Remove(path); rmErr != nil {
				return "", fmt.Errorf("remove stale name-duplicates report: %w", rmErr)
			}
		}
		return "", nil
	}

	if err := os.MkdirAll(reportDir, 0o755); err != nil {
		return "", fmt.Errorf("create name-duplicates report dir: %w", err)
	}
	if err := os.WriteFile(path, []byte(content), 0o644); err != nil {
		return "", fmt.Errorf("write name-duplicates report: %w", err)
	}
	return path, nil
}
