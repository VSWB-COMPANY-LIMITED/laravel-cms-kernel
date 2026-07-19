package report

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestHumanSize(t *testing.T) {
	cases := []struct {
		bytes int64
		want  string
	}{
		{0, "-"},
		{-5, "-"},
		{500, "500.0 B"},
		{1024, "1.0 KB"},
		{1536, "1.5 KB"},
		{1048576, "1.0 MB"},
		{1073741824, "1.0 GB"},
	}
	for _, c := range cases {
		if got := humanSize(c.bytes); got != c.want {
			t.Errorf("humanSize(%d) = %q, want %q", c.bytes, got, c.want)
		}
	}
}

func TestBuildUnexportableManifest_Empty(t *testing.T) {
	if got := BuildUnexportableManifest(nil, ManifestMeta{}); got != "" {
		t.Fatalf("expected empty string for no items, got %q", got)
	}

	// Only retryable items (no permanent) → still empty, matches
	// writeUnexportableManifest()'s "nothing to report, delete stale file"
	// no-op path.
	items := []FailedItem{{Path: "a.pdf", Category: "Network / timeout", Permanent: false}}
	if got := BuildUnexportableManifest(items, ManifestMeta{}); got != "" {
		t.Fatalf("expected empty string for only-retryable items, got %q", got)
	}
}

func TestBuildUnexportableManifest_FiltersRetryable(t *testing.T) {
	items := []FailedItem{
		{Path: "keep.pdf", Category: "Export size limit", Permanent: true, Size: 10, ErrorReason: strPtr("exportSizeLimitExceeded")},
		{Path: "drop.pdf", Category: "Network / timeout", Permanent: false, Size: 20},
	}
	got := BuildUnexportableManifest(items, ManifestMeta{})
	if strings.Contains(got, "drop.pdf") {
		t.Errorf("retryable item leaked into manifest:\n%s", got)
	}
	if !strings.Contains(got, "keep.pdf") {
		t.Errorf("permanent item missing from manifest:\n%s", got)
	}
}

func TestBuildUnexportableManifest_GroupedByReasonWithNote(t *testing.T) {
	items := []FailedItem{
		{Path: "big.pdf", Category: "Export size limit", Permanent: true, Size: 999, ErrorReason: strPtr("exportSizeLimitExceeded"), ID: strPtr("id1"), MimeType: strPtr("application/vnd.google-apps.document")},
		{Path: "small.pdf", Category: "Export size limit", Permanent: true, Size: 1, ErrorReason: strPtr("exportSizeLimitExceeded"), ID: strPtr("id2"), MimeType: strPtr("application/pdf")},
		{Path: "locked.gdoc", Category: "Not exportable (locked/unsupported)", Permanent: true, Size: 5, ErrorReason: strPtr("cannotExportFile")},
		{Path: "no-note.pdf", Category: "Some other category", Permanent: true, Size: 3, ErrorReason: strPtr("someUnknownReason")},
	}
	got := BuildUnexportableManifest(items, ManifestMeta{
		GeneratedAt: "2026-07-19T00:00:00Z", RunID: "run1",
		FolderIDs: []string{"f1", "f2"}, BaseLocalPath: "/base",
	})

	if !strings.Contains(got, "# File KHÔNG THỂ mirror (permanent)") {
		t.Errorf("missing title:\n%s", got)
	}
	if !strings.Contains(got, "- Tổng số file: 4") {
		t.Errorf("wrong total count:\n%s", got)
	}
	if !strings.Contains(got, "Folder ID(s): f1, f2") {
		t.Errorf("missing folder IDs:\n%s", got)
	}

	if !strings.Contains(got, "## Export size limit (2 files)") {
		t.Errorf("missing/wrong group heading for Export size limit:\n%s", got)
	}
	if !strings.Contains(got, "QUÁ LỚN để export qua API") {
		t.Errorf("missing exportSizeLimitExceeded note:\n%s", got)
	}
	// size-descending within group: big.pdf (999) must appear before small.pdf (1).
	if strings.Index(got, "big.pdf") > strings.Index(got, "small.pdf") {
		t.Errorf("expected big.pdf before small.pdf (size desc):\n%s", got)
	}

	if !strings.Contains(got, "## Not exportable (locked/unsupported) (1 file)") {
		t.Errorf("missing singular group heading:\n%s", got)
	}
	if !strings.Contains(got, "Bị chủ file KHOÁ") {
		t.Errorf("missing cannotExportFile note:\n%s", got)
	}

	// Unknown reason still gets a heading (from Category), just no note line
	// directly after it.
	if !strings.Contains(got, "## Some other category (1 file)\n\n- [3.0 B] no-note.pdf") {
		t.Errorf("expected unknown-reason group with no note line, got:\n%s", got)
	}

	// Google-native item gets its Docs Editors manual-download link + ext.
	if !strings.Contains(got, "https://docs.google.com/document/d/id1") || !strings.Contains(got, "(tải .docx)") {
		t.Errorf("missing/wrong manual download link for google-native item:\n%s", got)
	}
	// Regular file keeps its own path extension.
	if !strings.Contains(got, "https://drive.google.com/file/d/id2") || !strings.Contains(got, "(tải .pdf)") {
		t.Errorf("missing/wrong manual download link for regular file:\n%s", got)
	}
	// No ID → no download-link line for that item (locked.gdoc/no-note.pdf have no ID).
	if strings.Contains(got, "locked.gdoc\n  →") {
		t.Errorf("expected no download link for item without an ID:\n%s", got)
	}
}

func TestBuildUnexportableManifest_PreservesFirstAppearanceGroupOrder(t *testing.T) {
	items := []FailedItem{
		{Path: "z.pdf", Category: "Zeta category", Permanent: true, ErrorReason: strPtr("zeta")},
		{Path: "a.pdf", Category: "Alpha category", Permanent: true, ErrorReason: strPtr("alpha")},
	}
	got := BuildUnexportableManifest(items, ManifestMeta{})
	zetaIdx := strings.Index(got, "## Zeta category")
	alphaIdx := strings.Index(got, "## Alpha category")
	if zetaIdx == -1 || alphaIdx == -1 || zetaIdx > alphaIdx {
		t.Errorf("expected group order to match first-appearance order (Zeta before Alpha), got:\n%s", got)
	}
}

func TestBuildUnexportableManifest_SpecialMarkdownCharsDontBreakStructure(t *testing.T) {
	items := []FailedItem{
		{Path: "weird | name [brackets] `backtick`.pdf", Category: "Other", Permanent: true, Size: 10, ErrorReason: strPtr("x")},
	}
	got := BuildUnexportableManifest(items, ManifestMeta{})

	if !strings.Contains(got, "weird | name [brackets] `backtick`.pdf") {
		t.Errorf("special-char filename not preserved verbatim:\n%s", got)
	}
	// Structure must still be intact: exactly one "## " heading line, and it
	// precedes the item line — a stray `]`/`|`/backtick in the path must not
	// spill into or break the heading.
	headingCount := strings.Count(got, "\n## ")
	if headingCount != 1 {
		t.Errorf("expected exactly 1 group heading, got %d:\n%s", headingCount, got)
	}
	if idx := strings.Index(got, "## Other"); idx == -1 || idx > strings.Index(got, "weird |") {
		t.Errorf("heading must precede item line:\n%s", got)
	}
}

func TestWriteUnexportableManifest_EmptyIsNoOp(t *testing.T) {
	dir := t.TempDir()
	path, err := WriteUnexportableManifest(dir, nil, ManifestMeta{FolderIDs: []string{"f1"}})
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if path != "" {
		t.Fatalf("expected empty path, got %q", path)
	}
	if _, statErr := os.Stat(filepath.Join(dir, "unexportable")); statErr == nil {
		t.Fatalf("unexportable dir should not be created when there's nothing to write")
	}
}

func TestWriteUnexportableManifest_WritesFile(t *testing.T) {
	dir := t.TempDir()
	items := []FailedItem{{Path: "a.pdf", Category: "Other", Permanent: true, ErrorReason: strPtr("x")}}
	meta := ManifestMeta{FolderIDs: []string{"folder1"}}

	path, err := WriteUnexportableManifest(dir, items, meta)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	wantPath := filepath.Join(dir, "unexportable", FolderTag([]string{"folder1"})+".md")
	if path != wantPath {
		t.Fatalf("path = %q, want %q", path, wantPath)
	}
	data, err := os.ReadFile(path)
	if err != nil {
		t.Fatalf("read written manifest: %v", err)
	}
	if !strings.Contains(string(data), "a.pdf") {
		t.Errorf("manifest missing expected content: %s", data)
	}
}

func TestWriteUnexportableManifest_DeletesStaleManifestWhenClean(t *testing.T) {
	dir := t.TempDir()
	meta := ManifestMeta{FolderIDs: []string{"folder1"}}

	// First run: a permanent failure writes the manifest.
	items := []FailedItem{{Path: "a.pdf", Category: "Other", Permanent: true, ErrorReason: strPtr("x")}}
	path, err := WriteUnexportableManifest(dir, items, meta)
	if err != nil || path == "" {
		t.Fatalf("setup: expected manifest written, path=%q err=%v", path, err)
	}
	if _, statErr := os.Stat(path); statErr != nil {
		t.Fatalf("setup: manifest should exist: %v", statErr)
	}

	// Second run: folder is now clean (no permanent failures) → stale
	// manifest must be removed, not left describing a fixed problem as if
	// it were still current.
	cleanPath, err := WriteUnexportableManifest(dir, nil, meta)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if cleanPath != "" {
		t.Fatalf("expected empty path when clean, got %q", cleanPath)
	}
	if _, statErr := os.Stat(path); !os.IsNotExist(statErr) {
		t.Fatalf("expected stale manifest to be deleted, stat err = %v", statErr)
	}
}
