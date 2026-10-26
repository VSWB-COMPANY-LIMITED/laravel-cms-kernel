package report

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestBuildNameDuplicatesReport_Empty(t *testing.T) {
	if got := BuildNameDuplicatesReport(nil, ManifestMeta{}); got != "" {
		t.Fatalf("expected empty string for no duplicates, got %q", got)
	}
}

func TestBuildNameDuplicatesReport_BasicPair(t *testing.T) {
	dups := []NameDuplicate{
		{
			FolderPath: "Brief & Kho tính năng",
			Name:       "CREATIVE_BRIEF_V4_PULL.VN_gửi_creative_team.md.docx",
			Kept: NameDuplicateEntry{
				ID: "idKept0000000000000000", Path: "Brief & Kho tính năng/CREATIVE_BRIEF_V4_PULL.VN_gửi_creative_team.md.docx",
				Size: 212446, MimeType: "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
			},
			Renamed: NameDuplicateEntry{
				ID: "idRenamed000000000000", Path: "Brief & Kho tính năng/CREATIVE_BRIEF_V4_PULL.VN_gửi_creative_team.md_idRenam.docx",
				Size: 212448, MimeType: "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
			},
		},
	}
	meta := ManifestMeta{
		GeneratedAt: "2026-10-26T00:00:00Z", RunID: "run1",
		FolderIDs: []string{"f1"}, BaseLocalPath: "/base",
	}
	got := BuildNameDuplicatesReport(dups, meta)

	if !strings.Contains(got, "# Trùng tên THẬT trên Drive") {
		t.Errorf("missing title:\n%s", got)
	}
	if !strings.Contains(got, "- Tổng số cặp: 1") {
		t.Errorf("wrong total count:\n%s", got)
	}
	if !strings.Contains(got, "## Brief & Kho tính năng (1 cặp)") {
		t.Errorf("missing/wrong group heading:\n%s", got)
	}
	if !strings.Contains(got, "CREATIVE_BRIEF_V4_PULL.VN_gửi_creative_team.md.docx") {
		t.Errorf("missing shared name:\n%s", got)
	}
	if !strings.Contains(got, "Giữ nguyên tên") || !strings.Contains(got, "Đổi tên (hậu tố ID)") {
		t.Errorf("missing kept/renamed labels:\n%s", got)
	}
	if !strings.Contains(got, "https://drive.google.com/file/d/idKept0000000000000000") {
		t.Errorf("missing manual-download link for kept side:\n%s", got)
	}
	if !strings.Contains(got, "https://drive.google.com/file/d/idRenamed000000000000") {
		t.Errorf("missing manual-download link for renamed side:\n%s", got)
	}
}

func TestBuildNameDuplicatesReport_GroupsByFolderPreservingFirstAppearance(t *testing.T) {
	entry := func(id, path string) NameDuplicateEntry { return NameDuplicateEntry{ID: id, Path: path} }
	dups := []NameDuplicate{
		{FolderPath: "Zeta", Name: "z.pdf", Kept: entry("z1", "Zeta/z.pdf"), Renamed: entry("z2", "Zeta/z_z2.pdf")},
		{FolderPath: "Alpha", Name: "a.pdf", Kept: entry("a1", "Alpha/a.pdf"), Renamed: entry("a2", "Alpha/a_a2.pdf")},
		{FolderPath: "Zeta", Name: "y.pdf", Kept: entry("y1", "Zeta/y.pdf"), Renamed: entry("y2", "Zeta/y_y2.pdf")},
	}
	got := BuildNameDuplicatesReport(dups, ManifestMeta{})

	zetaIdx := strings.Index(got, "## Zeta")
	alphaIdx := strings.Index(got, "## Alpha")
	if zetaIdx == -1 || alphaIdx == -1 || zetaIdx > alphaIdx {
		t.Errorf("expected group order to match first-appearance order (Zeta before Alpha), got:\n%s", got)
	}
	if !strings.Contains(got, "## Zeta (2 cặp)") {
		t.Errorf("expected both Zeta pairs grouped together:\n%s", got)
	}
}

func TestBuildNameDuplicatesReport_RootFolderLabel(t *testing.T) {
	dups := []NameDuplicate{
		{FolderPath: "", Name: "root-dup.pdf",
			Kept:    NameDuplicateEntry{ID: "r1", Path: "root-dup.pdf"},
			Renamed: NameDuplicateEntry{ID: "r2", Path: "root-dup_r2.pdf"}},
	}
	got := BuildNameDuplicatesReport(dups, ManifestMeta{})
	if !strings.Contains(got, "## (gốc)") {
		t.Errorf("expected root folder to render as '(gốc)', got:\n%s", got)
	}
}

func TestWriteNameDuplicatesReport_EmptyIsNoOp(t *testing.T) {
	dir := t.TempDir()
	path, err := WriteNameDuplicatesReport(dir, nil, ManifestMeta{FolderIDs: []string{"f1"}})
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if path != "" {
		t.Fatalf("expected empty path, got %q", path)
	}
	if _, statErr := os.Stat(filepath.Join(dir, "name-duplicates")); statErr == nil {
		t.Fatalf("name-duplicates dir should not be created when there's nothing to write")
	}
}

func TestWriteNameDuplicatesReport_WritesFile(t *testing.T) {
	dir := t.TempDir()
	dups := []NameDuplicate{
		{FolderPath: "P", Name: "dup.pdf",
			Kept:    NameDuplicateEntry{ID: "k1", Path: "P/dup.pdf"},
			Renamed: NameDuplicateEntry{ID: "k2", Path: "P/dup_k2.pdf"}},
	}
	meta := ManifestMeta{FolderIDs: []string{"folder1"}}

	path, err := WriteNameDuplicatesReport(dir, dups, meta)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	wantPath := filepath.Join(dir, "name-duplicates", FolderTag([]string{"folder1"})+".md")
	if path != wantPath {
		t.Fatalf("path = %q, want %q", path, wantPath)
	}
	data, err := os.ReadFile(path)
	if err != nil {
		t.Fatalf("read written report: %v", err)
	}
	if !strings.Contains(string(data), "dup.pdf") {
		t.Errorf("report missing expected content: %s", data)
	}
}

func TestWriteNameDuplicatesReport_DeletesStaleReportWhenClean(t *testing.T) {
	dir := t.TempDir()
	meta := ManifestMeta{FolderIDs: []string{"folder1"}}

	dups := []NameDuplicate{
		{FolderPath: "P", Name: "dup.pdf",
			Kept:    NameDuplicateEntry{ID: "k1", Path: "P/dup.pdf"},
			Renamed: NameDuplicateEntry{ID: "k2", Path: "P/dup_k2.pdf"}},
	}
	path, err := WriteNameDuplicatesReport(dir, dups, meta)
	if err != nil || path == "" {
		t.Fatalf("setup: expected report written, path=%q err=%v", path, err)
	}
	if _, statErr := os.Stat(path); statErr != nil {
		t.Fatalf("setup: report should exist: %v", statErr)
	}

	// Human resolved it on Drive since the last run (or it was a one-off) →
	// stale report must be removed, not left describing a since-fixed pair.
	cleanPath, err := WriteNameDuplicatesReport(dir, nil, meta)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if cleanPath != "" {
		t.Fatalf("expected empty path when clean, got %q", cleanPath)
	}
	if _, statErr := os.Stat(path); !os.IsNotExist(statErr) {
		t.Fatalf("expected stale report to be deleted, stat err = %v", statErr)
	}
}
