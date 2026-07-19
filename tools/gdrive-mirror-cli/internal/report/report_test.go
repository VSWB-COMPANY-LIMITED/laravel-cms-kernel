package report

import (
	"bytes"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func strPtr(s string) *string { return &s }

func TestRenderFailedCSV_BOMAndSemicolon(t *testing.T) {
	items := []FailedItem{
		{
			Type: "file", Path: "A/b.pdf", ID: strPtr("abc123"),
			MimeType: strPtr("application/pdf"), Size: 100, Category: "Other",
			Reason: "mkdir(): Permission denied",
		},
	}
	csv := RenderFailedCSV(BuildFailedRows(items))

	if !bytes.HasPrefix(csv, []byte("\xEF\xBB\xBF")) {
		t.Fatalf("expected UTF-8 BOM prefix, got: %x", csv[:3])
	}
	body := string(csv[3:])
	if !strings.Contains(body, ";") {
		t.Fatalf("expected `;` delimiter in CSV body, got: %s", body)
	}
	headerLine := strings.SplitN(body, "\n", 2)[0]
	if strings.Count(headerLine, ";") != len(FailedRowColumns)-1 {
		t.Fatalf("header column count mismatch: %s", headerLine)
	}
}

func TestRenderFailedCSV_Empty(t *testing.T) {
	if got := RenderFailedCSV(nil); got != nil {
		t.Fatalf("expected nil for empty rows, got %v", got)
	}
}

func TestBuildFailedRows_DriveLinkByMimeType(t *testing.T) {
	items := []FailedItem{
		{Path: "doc.gdoc", ID: strPtr("id1"), MimeType: strPtr("application/vnd.google-apps.document"), Category: "Other"},
		{Path: "file.pdf", ID: strPtr("id2"), MimeType: strPtr("application/pdf"), Category: "Other"},
		{Path: "no-id.pdf", ID: nil, Category: "Other"},
	}
	rows := BuildFailedRows(items)
	linkIdx := 6 // drive_link column

	links := map[string]string{}
	for _, r := range rows {
		links[r[0]] = r[linkIdx]
	}
	if links["doc.gdoc"] != "https://docs.google.com/document/d/id1" {
		t.Errorf("google doc link = %q", links["doc.gdoc"])
	}
	if links["file.pdf"] != "https://drive.google.com/file/d/id2" {
		t.Errorf("regular file link = %q", links["file.pdf"])
	}
	if links["no-id.pdf"] != "" {
		t.Errorf("no-id link should be empty, got %q", links["no-id.pdf"])
	}
}

func TestBuildFailedRows_SortOrder(t *testing.T) {
	items := []FailedItem{
		{Path: "retryable-small.pdf", Category: "Network / timeout", Size: 10, Permanent: false},
		{Path: "permanent-big.pdf", Category: "Export size limit", Size: 999, Permanent: true},
		{Path: "permanent-small.pdf", Category: "Export size limit", Size: 1, Permanent: true},
	}
	rows := BuildFailedRows(items)
	if rows[0][0] != "permanent-big.pdf" {
		t.Errorf("expected permanent-big.pdf first, got %s", rows[0][0])
	}
	if rows[1][0] != "permanent-small.pdf" {
		t.Errorf("expected permanent-small.pdf second, got %s", rows[1][0])
	}
	if rows[2][0] != "retryable-small.pdf" {
		t.Errorf("expected retryable-small.pdf last, got %s", rows[2][0])
	}
}

func TestWriteFailedReportJSON_EmptyNoOp(t *testing.T) {
	dir := t.TempDir()
	path, err := WriteFailedReportJSON(dir, nil, "/base", "Prefix", []string{"f1"}, "run1")
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if path != "" {
		t.Fatalf("expected empty path for empty items, got %q", path)
	}
}

func TestWriteFailedReportJSON_WritesAndCounts(t *testing.T) {
	dir := t.TempDir()
	items := []FailedItem{
		{Path: "a.pdf", Permanent: true, Category: "Not found"},
		{Path: "b.pdf", Permanent: false, Category: "Network / timeout"},
	}
	path, err := WriteFailedReportJSON(dir, items, "/base", "Prefix", []string{"folder1"}, "run1")
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if path == "" {
		t.Fatal("expected non-empty path")
	}
	data, err := os.ReadFile(path)
	if err != nil {
		t.Fatalf("read written report: %v", err)
	}
	if !strings.Contains(string(data), `"permanent_count": 1`) {
		t.Errorf("expected permanent_count 1 in report, got: %s", data)
	}
	if !strings.Contains(string(data), `"retryable_count": 1`) {
		t.Errorf("expected retryable_count 1 in report, got: %s", data)
	}
	if filepath.Dir(path) != dir {
		t.Errorf("report written outside dir: %s", path)
	}
}

func TestPruneOldFailedReports_KeepsNewest(t *testing.T) {
	dir := t.TempDir()
	for i := 0; i < 12; i++ {
		p := filepath.Join(dir, fmt.Sprintf("failed-abc123-%02d-000000.json", i))
		if err := os.WriteFile(p, []byte("{}"), 0o644); err != nil {
			t.Fatal(err)
		}
	}
	deleted, err := PruneOldFailedReports(dir, 10)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if deleted != 2 {
		t.Errorf("expected 2 deleted, got %d", deleted)
	}
	remaining, _ := filepath.Glob(filepath.Join(dir, "failed-*.json"))
	if len(remaining) != 10 {
		t.Errorf("expected 10 remaining, got %d", len(remaining))
	}
}
