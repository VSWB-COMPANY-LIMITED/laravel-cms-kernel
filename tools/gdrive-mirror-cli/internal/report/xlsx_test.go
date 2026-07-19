package report

import (
	"archive/zip"
	"bytes"
	"encoding/xml"
	"fmt"
	"os"
	"path/filepath"
	"testing"
)

// xlsxSheetXML mirrors the subset of xl/worksheets/sheet1.xml this test
// cares about, used to prove the file this package writes is real,
// well-formed OOXML — not just "the function returned no error".
type xlsxSheetXML struct {
	XMLName   xml.Name `xml:"worksheet"`
	SheetData struct {
		Rows []struct {
			R     string `xml:"r,attr"`
			Cells []struct {
				R  string `xml:"r,attr"`
				T  string `xml:"t,attr"`
				Is struct {
					T string `xml:"t"`
				} `xml:"is"`
			} `xml:"c"`
		} `xml:"row"`
	} `xml:"sheetData"`
}

// unzipSheet extracts and XML-decodes xl/worksheets/sheet1.xml from an
// in-memory .xlsx byte stream, failing the test on any error along the way
// (malformed zip, missing part, malformed XML) — this is the "actually
// openable" proof the task requires, not just "RenderFailedXLSX returned
// nil error".
func unzipSheet(t *testing.T, data []byte) xlsxSheetXML {
	t.Helper()

	zr, err := zip.NewReader(bytes.NewReader(data), int64(len(data)))
	if err != nil {
		t.Fatalf("zip.NewReader: %v", err)
	}

	wantParts := []string{
		"[Content_Types].xml",
		"_rels/.rels",
		"xl/workbook.xml",
		"xl/_rels/workbook.xml.rels",
		"xl/worksheets/sheet1.xml",
	}
	byName := map[string]*zip.File{}
	for _, f := range zr.File {
		byName[f.Name] = f
	}
	for _, name := range wantParts {
		if _, ok := byName[name]; !ok {
			t.Fatalf("missing zip part %q", name)
		}
	}

	rc, err := byName["xl/worksheets/sheet1.xml"].Open()
	if err != nil {
		t.Fatalf("open sheet1.xml: %v", err)
	}
	defer rc.Close()

	var sheet xlsxSheetXML
	if err := xml.NewDecoder(rc).Decode(&sheet); err != nil {
		t.Fatalf("decode sheet1.xml: %v", err)
	}
	return sheet
}

func TestRenderFailedXLSX_Empty(t *testing.T) {
	data, err := RenderFailedXLSX(nil)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if data != nil {
		t.Fatalf("expected nil for empty rows, got %d bytes", len(data))
	}
}

func TestRenderFailedXLSX_RoundTripSpecialCharsAndVietnamese(t *testing.T) {
	items := []FailedItem{
		{
			Path: `Báo cáo tháng 6 & sản phẩm <demo> "test".pdf`, ID: strPtr("id1"),
			MimeType: strPtr("application/pdf"), Size: 100, Category: "Other",
			ErrorReason: strPtr("networkError"),
		},
		{
			Path: "file.pdf", ID: strPtr("id2"), MimeType: strPtr("application/pdf"),
			Size: 50, Category: "Export size limit", Permanent: true,
			ErrorReason: strPtr("exportSizeLimitExceeded"),
		},
	}
	rows := BuildFailedRows(items)

	data, err := RenderFailedXLSX(rows)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if data == nil {
		t.Fatal("expected non-nil xlsx bytes")
	}

	sheet := unzipSheet(t, data)

	wantRows := len(rows) + 1 // header + data rows
	if got := len(sheet.SheetData.Rows); got != wantRows {
		t.Fatalf("expected %d rows, got %d", wantRows, got)
	}

	header := sheet.SheetData.Rows[0]
	if len(header.Cells) != len(FailedRowColumns) {
		t.Fatalf("expected %d header cells, got %d", len(FailedRowColumns), len(header.Cells))
	}
	for i, col := range FailedRowColumns {
		if got := header.Cells[i].Is.T; got != col {
			t.Errorf("header cell %d = %q, want %q", i, got, col)
		}
		if got := header.Cells[i].T; got != "inlineStr" {
			t.Errorf("header cell %d type = %q, want inlineStr", i, got)
		}
	}

	// Data rows must, cell-for-cell, match BuildFailedRows()'s own output —
	// same sort order (permanent-first, category, size desc) already
	// verified by TestBuildFailedRows_SortOrder, so this only needs to
	// prove the XLSX writer didn't drop/reorder/mangle anything, including
	// the special characters and Vietnamese diacritics round-tripping
	// exactly through XML-entity escaping.
	for r, wantRow := range rows {
		gotRow := sheet.SheetData.Rows[r+1]
		if len(gotRow.Cells) != len(wantRow) {
			t.Fatalf("row %d: expected %d cells, got %d", r, len(wantRow), len(gotRow.Cells))
		}
		for c, want := range wantRow {
			if got := gotRow.Cells[c].Is.T; got != want {
				t.Errorf("row %d cell %d = %q, want %q", r, c, got, want)
			}
		}
	}
}

func TestRenderFailedXLSX_CellRefsAreSequential(t *testing.T) {
	items := []FailedItem{{Path: "a.pdf", ID: strPtr("id1"), Category: "Other"}}
	data, err := RenderFailedXLSX(BuildFailedRows(items))
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	sheet := unzipSheet(t, data)

	header := sheet.SheetData.Rows[0]
	if header.R != "1" {
		t.Errorf("header row r=%q, want 1", header.R)
	}
	wantRefs := []string{"A1", "B1", "C1", "D1", "E1", "F1", "G1"}
	for i, want := range wantRefs {
		if got := header.Cells[i].R; got != want {
			t.Errorf("header cell %d ref = %q, want %q", i, got, want)
		}
	}

	dataRow := sheet.SheetData.Rows[1]
	if dataRow.R != "2" {
		t.Errorf("data row r=%q, want 2", dataRow.R)
	}
	if dataRow.Cells[0].R != "A2" {
		t.Errorf("data row cell 0 ref = %q, want A2", dataRow.Cells[0].R)
	}
}

func TestWriteFailedXLSX_EmptyNoOp(t *testing.T) {
	dir := t.TempDir()
	path, err := WriteFailedXLSX(dir, nil)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if path != "" {
		t.Fatalf("expected empty path for empty items, got %q", path)
	}
	entries, _ := os.ReadDir(dir)
	if len(entries) != 0 {
		t.Fatalf("expected no files written, got %v", entries)
	}
}

func TestWriteFailedXLSX_WritesOpenableFile(t *testing.T) {
	dir := t.TempDir()
	items := []FailedItem{
		{Path: "a.pdf", ID: strPtr("id1"), Category: "Other", Permanent: true},
		{Path: "b.pdf", ID: strPtr("id2"), Category: "Network / timeout"},
	}
	path, err := WriteFailedXLSX(dir, items)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if path == "" {
		t.Fatal("expected non-empty path")
	}
	if filepath.Dir(path) != dir {
		t.Errorf("xlsx written outside dir: %s", path)
	}
	if filepath.Ext(path) != ".xlsx" {
		t.Errorf("expected .xlsx extension, got %s", path)
	}

	data, err := os.ReadFile(path)
	if err != nil {
		t.Fatalf("read written xlsx: %v", err)
	}
	sheet := unzipSheet(t, data)
	if len(sheet.SheetData.Rows) != 3 { // header + 2 items
		t.Errorf("expected 3 rows, got %d", len(sheet.SheetData.Rows))
	}
}

func TestPruneOldFailedXLSX_KeepsNewest(t *testing.T) {
	dir := t.TempDir()
	for i := 0; i < 12; i++ {
		p := filepath.Join(dir, fmt.Sprintf("01012025_%06d.xlsx", i))
		if err := os.WriteFile(p, []byte("x"), 0o644); err != nil {
			t.Fatal(err)
		}
	}
	deleted, err := PruneOldFailedXLSX(dir, 10)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if deleted != 2 {
		t.Errorf("expected 2 deleted, got %d", deleted)
	}
	remaining, _ := filepath.Glob(filepath.Join(dir, "*.xlsx"))
	if len(remaining) != 10 {
		t.Errorf("expected 10 remaining, got %d", len(remaining))
	}
}

func TestPruneOldFailedXLSX_IgnoresNonMatchingNames(t *testing.T) {
	dir := t.TempDir()
	if err := os.WriteFile(filepath.Join(dir, "user-dropped-this.xlsx"), []byte("x"), 0o644); err != nil {
		t.Fatal(err)
	}
	for i := 0; i < 11; i++ {
		p := filepath.Join(dir, fmt.Sprintf("01012025_%06d.xlsx", i))
		if err := os.WriteFile(p, []byte("x"), 0o644); err != nil {
			t.Fatal(err)
		}
	}
	if _, err := PruneOldFailedXLSX(dir, 10); err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if _, err := os.Stat(filepath.Join(dir, "user-dropped-this.xlsx")); err != nil {
		t.Fatalf("non-matching file should survive pruning: %v", err)
	}
}
