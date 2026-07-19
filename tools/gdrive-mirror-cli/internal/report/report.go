// Package report writes the end-of-run failed-item reports: a JSON file
// shaped for a future --retry-failed re-run, and an Excel-safe CSV
// (semicolon-delimited, UTF-8 BOM) for quick human triage.
package report

import (
	"bytes"
	"crypto/md5"
	"encoding/csv"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"sort"
	"strconv"
	"strings"
	"time"
)

// FailedItem mirrors one entry of $stats['failed_files'] in GDriveMirrorSync.php
// (see storage/app/gdrive-sync/failed/*.json on a real run for the exact
// shape). Nullable PHP fields (id/mimeType/md5Checksum/error_reason) are
// pointers here so they round-trip as JSON null instead of "".
type FailedItem struct {
	Type        string  `json:"type"`
	Path        string  `json:"path"`
	ID          *string `json:"id"`
	MimeType    *string `json:"mimeType"`
	MD5Checksum *string `json:"md5Checksum"`
	Timestamp   int64   `json:"timestamp"`
	Size        int64   `json:"size"`
	Attempts    int     `json:"attempts"`
	Reason      string  `json:"reason"`
	Permanent   bool    `json:"permanent"`
	ErrorReason *string `json:"error_reason"`
	Category    string  `json:"category"`
}

// FailedReport is the top-level JSON payload written for --retry-failed.
type FailedReport struct {
	GeneratedAt   string   `json:"generated_at"`
	RunID         string   `json:"run_id"`
	FolderIDs     []string `json:"folder_ids"`
	BaseLocalPath string   `json:"base_local_path"`
	// LocalPrefix is the sanitized top-level directory name every item in
	// this report was written under (the synced folder's own Drive name).
	// FailedItem.Path is relative to it, NOT to BaseLocalPath — so a
	// --retry-failed run that ignores this field rebuilds every target one
	// directory too high and re-downloads into the wrong place (caught in
	// review, 2026-07-19). Empty on reports written before this field
	// existed; the retry path then re-resolves it from Drive.
	LocalPrefix    string       `json:"local_prefix"`
	Count          int          `json:"count"`
	PermanentCount int          `json:"permanent_count"`
	RetryableCount int          `json:"retryable_count"`
	Items          []FailedItem `json:"items"`
}

// FolderTag derives the short filename tag used by both the JSON report and
// the (future) per-folder unexportable manifest — md5(folderIDs joined by
// ",")[:8], matching GDriveMirrorSync::finalReport()/writeFailedReport().
func FolderTag(folderIDs []string) string {
	if len(folderIDs) == 0 {
		return "unknown"
	}
	sum := md5.Sum([]byte(strings.Join(folderIDs, ",")))
	return hex.EncodeToString(sum[:])[:8]
}

// WriteFailedReportJSON writes the failed-items JSON report to dir
// (created if missing) and returns its absolute path. Returns ("", nil) when
// items is empty — matches writeFailedReport()'s "nothing to report" no-op.
func WriteFailedReportJSON(dir string, items []FailedItem, baseLocalPath string, localPrefix string, folderIDs []string, runID string) (string, error) {
	if len(items) == 0 {
		return "", nil
	}
	if err := os.MkdirAll(dir, 0o755); err != nil {
		return "", fmt.Errorf("create report dir: %w", err)
	}

	permanentCount := 0
	for _, it := range items {
		if it.Permanent {
			permanentCount++
		}
	}

	payload := FailedReport{
		GeneratedAt:    time.Now().Format(time.RFC3339),
		RunID:          runID,
		FolderIDs:      folderIDs,
		BaseLocalPath:  baseLocalPath,
		LocalPrefix:    localPrefix,
		Count:          len(items),
		PermanentCount: permanentCount,
		RetryableCount: len(items) - permanentCount,
		Items:          items,
	}

	data, err := json.MarshalIndent(payload, "", "  ")
	if err != nil {
		return "", fmt.Errorf("marshal report: %w", err)
	}

	fileName := fmt.Sprintf("failed-%s-%s.json", FolderTag(folderIDs), time.Now().Format("20060102-150405"))
	absPath := filepath.Join(dir, fileName)
	if err := os.WriteFile(absPath, data, 0o644); err != nil {
		return "", fmt.Errorf("write report: %w", err)
	}

	return absPath, nil
}

// PruneOldFailedReports keeps only the `keep` most-recently-modified
// failed-*.json reports in dir, deleting the rest. A delete failure is
// returned as an error slice length via the returned count of failures — the
// caller should log-and-continue, never fail the run over report pruning.
func PruneOldFailedReports(dir string, keep int) (deleted int, err error) {
	matches, globErr := filepath.Glob(filepath.Join(dir, "failed-*.json"))
	if globErr != nil {
		return 0, globErr
	}
	if len(matches) <= keep {
		return 0, nil
	}

	type fileInfo struct {
		path    string
		modTime time.Time
	}
	infos := make([]fileInfo, 0, len(matches))
	for _, m := range matches {
		st, statErr := os.Stat(m)
		if statErr != nil {
			continue
		}
		infos = append(infos, fileInfo{path: m, modTime: st.ModTime()})
	}
	sort.Slice(infos, func(i, j int) bool { return infos[i].modTime.After(infos[j].modTime) })

	if len(infos) <= keep {
		return 0, nil
	}
	for _, fi := range infos[keep:] {
		if rmErr := os.Remove(fi.path); rmErr == nil {
			deleted++
		}
	}
	return deleted, nil
}

// manualDownloadURL mirrors GDriveMirrorSync::buildManualDownloadUrl()
// (PHP source ~L2750): a Google-native item (Docs/Sheets/Slides/Drawings)
// gets its native web-editor URL — still openable/downloadable by a human
// even when the export API itself refuses — anything else gets the generic
// Drive file URL. ext is the suggested extension for a manual download
// (native types convert to their Office equivalent on manual export;
// regular files keep whatever extension their own path already has). The
// single source of truth for both buildManualDownloadURL (CSV/XLSX
// drive_link column, url only) and BuildUnexportableManifest (manifest.go,
// needs url+ext) — kept in one place so the two reports can't drift apart
// on which mimeType maps to which URL.
func manualDownloadURL(id string, mimeType string, path string) (url string, ext string) {
	switch mimeType {
	case "application/vnd.google-apps.presentation":
		return "https://docs.google.com/presentation/d/" + id, "pptx"
	case "application/vnd.google-apps.document":
		return "https://docs.google.com/document/d/" + id, "docx"
	case "application/vnd.google-apps.spreadsheet":
		return "https://docs.google.com/spreadsheets/d/" + id, "xlsx"
	case "application/vnd.google-apps.drawing":
		return "https://docs.google.com/drawings/d/" + id, "png"
	default:
		fileExt := strings.TrimPrefix(filepath.Ext(path), ".")
		if fileExt == "" {
			fileExt = "file"
		}
		return "https://drive.google.com/file/d/" + id, fileExt
	}
}

// buildManualDownloadURL is the CSV/XLSX drive_link column's URL-only view
// of manualDownloadURL (no path/extension needed there).
func buildManualDownloadURL(id string, mimeType string) string {
	url, _ := manualDownloadURL(id, mimeType, "")
	return url
}

// FailedRowColumns is the fixed CSV column order — stable across runs so
// spreadsheets diff cleanly.
var FailedRowColumns = []string{"file", "path", "size", "category", "error_reason", "permanent", "drive_link"}

// BuildFailedRows converts failed items into fixed-column string rows ready
// for CSV rendering. Sorted permanent-first (needs manual action), then by
// category, then by size descending — same triage order as the PHP source.
func BuildFailedRows(items []FailedItem) [][]string {
	if len(items) == 0 {
		return nil
	}

	type row struct {
		vals []string
		perm bool
		cat  string
		size int64
	}
	rows := make([]row, 0, len(items))
	for _, it := range items {
		id := ""
		if it.ID != nil {
			id = *it.ID
		}
		mime := ""
		if it.MimeType != nil {
			mime = *it.MimeType
		}
		errReason := ""
		if it.ErrorReason != nil {
			errReason = *it.ErrorReason
		}
		permStr := "no"
		if it.Permanent {
			permStr = "yes"
		}
		fileName := ""
		if it.Path != "" {
			fileName = filepath.Base(it.Path)
		}
		link := ""
		if id != "" {
			link = buildManualDownloadURL(id, mime)
		}

		rows = append(rows, row{
			vals: []string{
				fileName,
				it.Path,
				strconv.FormatInt(it.Size, 10),
				it.Category,
				errReason,
				permStr,
				link,
			},
			perm: it.Permanent,
			cat:  it.Category,
			size: it.Size,
		})
	}

	sort.SliceStable(rows, func(i, j int) bool {
		if rows[i].perm != rows[j].perm {
			return rows[i].perm // permanent (true) sorts first
		}
		if rows[i].cat != rows[j].cat {
			return rows[i].cat < rows[j].cat
		}
		return rows[i].size > rows[j].size
	})

	out := make([][]string, len(rows))
	for i, r := range rows {
		out[i] = r.vals
	}
	return out
}

// RenderFailedCSV renders rows (from BuildFailedRows) into an Excel-safe CSV:
// UTF-8 BOM + `;` delimiter (VN locale Excel treats `,` as the decimal
// separator and mangles a standard comma-CSV into a single column).
func RenderFailedCSV(rows [][]string) []byte {
	if len(rows) == 0 {
		return nil
	}

	var buf bytes.Buffer
	buf.WriteString("\xEF\xBB\xBF")

	w := csv.NewWriter(&buf)
	w.Comma = ';'
	_ = w.Write(FailedRowColumns)
	for _, r := range rows {
		_ = w.Write(r)
	}
	w.Flush()

	return buf.Bytes()
}

// WriteFailedCSV builds and writes the failed-items CSV report for the
// current run's items to dir/<ddmmYYYY_HHMMSS>.csv and returns its path.
// Returns ("", nil) when items is empty.
func WriteFailedCSV(dir string, items []FailedItem) (string, error) {
	rows := BuildFailedRows(items)
	if len(rows) == 0 {
		return "", nil
	}
	if err := os.MkdirAll(dir, 0o755); err != nil {
		return "", fmt.Errorf("create csv dir: %w", err)
	}

	path := filepath.Join(dir, time.Now().Format("02012006_150405")+".csv")
	if err := os.WriteFile(path, RenderFailedCSV(rows), 0o644); err != nil {
		return "", fmt.Errorf("write csv: %w", err)
	}
	return path, nil
}
