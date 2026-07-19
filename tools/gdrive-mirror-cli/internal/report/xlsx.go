// xlsx.go writes the failed-items report as a minimal, valid .xlsx — the Go
// port's answer to GDriveMirrorSync::writeFailedSpreadsheet()'s OpenSpout
// branch (PHP source ~L2006). The kernel package intentionally does not
// hard-depend on OpenSpout/PhpSpreadsheet (only pull-server does), and this
// standalone CLI has the same "stay dependency-free" constraint (see
// go.mod: only google.golang.org/api). So instead of a spreadsheet library,
// this hand-rolls the OOXML SpreadsheetML parts (archive/zip +
// encoding/xml, both stdlib) that make up the smallest .xlsx Excel/Sheets/
// LibreOffice will open: [Content_Types].xml, _rels/.rels, xl/workbook.xml,
// xl/_rels/workbook.xml.rels, xl/worksheets/sheet1.xml. Cells use inline
// strings (t="inlineStr") so a sharedStrings.xml part isn't needed.
package report

import (
	"archive/zip"
	"bytes"
	"encoding/xml"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"time"
)

// xlsxSheetName is the single worksheet's tab name.
const xlsxSheetName = "Failed Items"

const xmlDecl = `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>` + "\n"

const xlsxContentTypesXML = xmlDecl + `<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">` +
	`<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>` +
	`<Default Extension="xml" ContentType="application/xml"/>` +
	`<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>` +
	`<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>` +
	`</Types>`

const xlsxRootRelsXML = xmlDecl + `<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">` +
	`<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>` +
	`</Relationships>`

const xlsxWorkbookXML = xmlDecl + `<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">` +
	`<sheets><sheet name="` + xlsxSheetName + `" sheetId="1" r:id="rId1"/></sheets>` +
	`</workbook>`

const xlsxWorkbookRelsXML = xmlDecl + `<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">` +
	`<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>` +
	`</Relationships>`

// columnLetter converts a 0-based column index into its Excel column letter
// (0→A, 25→Z, 26→AA, …). Only columns 0-6 are ever exercised by
// FailedRowColumns today, but this stays general rather than hardcoding 7
// letters so a future column addition doesn't silently truncate.
func columnLetter(i int) string {
	s := ""
	for i >= 0 {
		s = string(rune('A'+i%26)) + s
		i = i/26 - 1
	}
	return s
}

// buildSheetXML renders the header row (FailedRowColumns) plus rows into
// xl/worksheets/sheet1.xml. Cell text goes through xml.EscapeText — stdlib,
// same escaping Go's own encoding/xml marshaler relies on — so `& < > "` are
// entity-escaped and any raw control byte invalid in XML 1.0 (anything
// below 0x20 except tab/LF/CR) is replaced with U+FFFD rather than emitted
// raw and corrupting the file. Valid UTF-8 (Vietnamese diacritics included)
// passes through untouched.
func buildSheetXML(rows [][]string) []byte {
	var b bytes.Buffer
	b.WriteString(xmlDecl)
	b.WriteString(`<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>`)

	writeRow := func(rowNum int, vals []string) {
		fmt.Fprintf(&b, `<row r="%d">`, rowNum)
		for i, v := range vals {
			cellRef := columnLetter(i) + strconv.Itoa(rowNum)
			fmt.Fprintf(&b, `<c r="%s" t="inlineStr"><is><t xml:space="preserve">`, cellRef)
			_ = xml.EscapeText(&b, []byte(v))
			b.WriteString(`</t></is></c>`)
		}
		b.WriteString(`</row>`)
	}

	writeRow(1, FailedRowColumns)
	for i, r := range rows {
		writeRow(i+2, r)
	}

	b.WriteString(`</sheetData></worksheet>`)
	return b.Bytes()
}

// RenderFailedXLSX renders rows (from BuildFailedRows — same columns/sort
// order as RenderFailedCSV, see FailedRowColumns) into a minimal valid
// .xlsx byte stream. Returns nil for empty rows, matching RenderFailedCSV.
func RenderFailedXLSX(rows [][]string) ([]byte, error) {
	if len(rows) == 0 {
		return nil, nil
	}

	var buf bytes.Buffer
	zw := zip.NewWriter(&buf)

	parts := []struct {
		name string
		data []byte
	}{
		{"[Content_Types].xml", []byte(xlsxContentTypesXML)},
		{"_rels/.rels", []byte(xlsxRootRelsXML)},
		{"xl/workbook.xml", []byte(xlsxWorkbookXML)},
		{"xl/_rels/workbook.xml.rels", []byte(xlsxWorkbookRelsXML)},
		{"xl/worksheets/sheet1.xml", buildSheetXML(rows)},
	}
	for _, p := range parts {
		w, err := zw.Create(p.name)
		if err != nil {
			return nil, fmt.Errorf("zip create %s: %w", p.name, err)
		}
		if _, err := w.Write(p.data); err != nil {
			return nil, fmt.Errorf("zip write %s: %w", p.name, err)
		}
	}
	if err := zw.Close(); err != nil {
		return nil, fmt.Errorf("zip close: %w", err)
	}
	return buf.Bytes(), nil
}

// WriteFailedXLSX builds and writes the failed-items XLSX report for the
// current run's items to dir/<ddmmYYYY_HHMMSS>.xlsx and returns its path.
// Reuses BuildFailedRows — the exact same columns and sort order as
// WriteFailedCSV — so the CSV and XLSX reports can never drift apart.
// Returns ("", nil) when items is empty, matching WriteFailedCSV.
func WriteFailedXLSX(dir string, items []FailedItem) (string, error) {
	rows := BuildFailedRows(items)
	if len(rows) == 0 {
		return "", nil
	}
	if err := os.MkdirAll(dir, 0o755); err != nil {
		return "", fmt.Errorf("create xlsx dir: %w", err)
	}

	data, err := RenderFailedXLSX(rows)
	if err != nil {
		return "", fmt.Errorf("render xlsx: %w", err)
	}

	path := filepath.Join(dir, time.Now().Format("02012006_150405")+".xlsx")
	if err := os.WriteFile(path, data, 0o644); err != nil {
		return "", fmt.Errorf("write xlsx: %w", err)
	}
	return path, nil
}

// failedXLSXNamePattern matches only files this package itself wrote
// (ddmmYYYY_HHMMSS.xlsx) — mirrors the PHP source's own
// pruneOldFailedSpreadsheets() glob-then-regex-filter so an unrelated
// .xlsx a user drops in the report dir is never swept up and deleted.
var failedXLSXNamePattern = regexp.MustCompile(`^\d{8}_\d{6}\.xlsx$`)

// PruneOldFailedXLSX keeps only the `keep` most-recently-modified
// <ddmmYYYY_HHMMSS>.xlsx reports in dir, deleting the rest — same keep-10
// convention as PruneOldFailedReports (JSON), mirroring
// GDriveMirrorSync::pruneOldFailedSpreadsheets(). A delete failure is
// swallowed into the returned count only (never an error) — same
// log-and-continue contract as PruneOldFailedReports; report pruning must
// never fail the run.
func PruneOldFailedXLSX(dir string, keep int) (deleted int, err error) {
	matches, globErr := filepath.Glob(filepath.Join(dir, "*.xlsx"))
	if globErr != nil {
		return 0, globErr
	}

	type fileInfo struct {
		path    string
		modTime time.Time
	}
	infos := make([]fileInfo, 0, len(matches))
	for _, m := range matches {
		if !failedXLSXNamePattern.MatchString(filepath.Base(m)) {
			continue
		}
		st, statErr := os.Stat(m)
		if statErr != nil {
			continue
		}
		infos = append(infos, fileInfo{path: m, modTime: st.ModTime()})
	}
	if len(infos) <= keep {
		return 0, nil
	}
	sort.Slice(infos, func(i, j int) bool { return infos[i].modTime.After(infos[j].modTime) })

	for _, fi := range infos[keep:] {
		if rmErr := os.Remove(fi.path); rmErr == nil {
			deleted++
		}
	}
	return deleted, nil
}
