package report

import (
	"os"
	"path/filepath"
	"testing"
	"time"
)

func TestParseFailedReport_Valid(t *testing.T) {
	data := []byte(`{
		"generated_at": "2026-07-19T00:00:00Z",
		"run_id": "abc",
		"folder_ids": ["folder1"],
		"base_local_path": "/tmp/x",
		"count": 1,
		"permanent_count": 0,
		"retryable_count": 1,
		"items": [
			{"type":"file","path":"a.txt","id":"id1","mimeType":"text/plain","md5Checksum":null,"timestamp":0,"size":10,"attempts":1,"reason":"timeout","permanent":false,"error_reason":null,"category":"Network / timeout"}
		]
	}`)

	fr, err := ParseFailedReport(data)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if len(fr.Items) != 1 || fr.Items[0].Path != "a.txt" {
		t.Fatalf("unexpected parsed items: %+v", fr.Items)
	}
	if len(fr.FolderIDs) != 1 || fr.FolderIDs[0] != "folder1" {
		t.Fatalf("unexpected folder_ids: %+v", fr.FolderIDs)
	}
}

func TestParseFailedReport_CorruptJSON(t *testing.T) {
	if _, err := ParseFailedReport([]byte("{not valid json")); err == nil {
		t.Fatal("expected an error for corrupt JSON, got nil")
	}
}

func TestParseFailedReport_MissingItemsField(t *testing.T) {
	data := []byte(`{"generated_at":"2026-07-19T00:00:00Z","run_id":"abc","folder_ids":["f1"]}`)
	if _, err := ParseFailedReport(data); err == nil {
		t.Fatal("expected an error for a report missing the items field, got nil")
	}
}

func TestParseFailedReport_ItemsWrongType(t *testing.T) {
	data := []byte(`{"items": "not-an-array"}`)
	if _, err := ParseFailedReport(data); err == nil {
		t.Fatal("expected an error when items is not an array, got nil")
	}
}

// TestParseFailedReport_EmptyItemsArrayIsNotAnError documents a deliberate
// asymmetry with the two cases above: an explicit `"items": []` is a VALID
// report (e.g. every failure from a prior run has already been retried
// successfully) — it must parse cleanly, not be treated the same as a
// missing/malformed field. Matches the PHP source's validation, which only
// rejects `! isset($json['items']) || ! is_array($json['items'])`.
func TestParseFailedReport_EmptyItemsArrayIsNotAnError(t *testing.T) {
	data := []byte(`{"items": []}`)
	fr, err := ParseFailedReport(data)
	if err != nil {
		t.Fatalf("expected no error for an empty items array, got: %v", err)
	}
	if len(fr.Items) != 0 {
		t.Fatalf("expected 0 items, got %d", len(fr.Items))
	}
}

func TestFilterRetryItems_DefaultSkipsPermanent(t *testing.T) {
	items := []FailedItem{
		{Path: "a.txt", Permanent: false},
		{Path: "b.txt", Permanent: true},
		{Path: "c.txt", Permanent: false},
	}
	kept, skipped := FilterRetryItems(items, false)
	if skipped != 1 {
		t.Fatalf("expected 1 skipped permanent item, got %d", skipped)
	}
	if len(kept) != 2 {
		t.Fatalf("expected 2 kept items, got %d", len(kept))
	}
	for _, it := range kept {
		if it.Permanent {
			t.Fatalf("permanent item leaked into kept: %+v", it)
		}
	}
}

func TestFilterRetryItems_IncludePermanentKeepsEverything(t *testing.T) {
	items := []FailedItem{
		{Path: "a.txt", Permanent: false},
		{Path: "b.txt", Permanent: true},
	}
	kept, skipped := FilterRetryItems(items, true)
	if skipped != 0 || len(kept) != 2 {
		t.Fatalf("expected all items kept, got kept=%d skipped=%d", len(kept), skipped)
	}
}

func TestFilterRetryItems_EmptyInputStaysEmpty(t *testing.T) {
	kept, skipped := FilterRetryItems(nil, false)
	if len(kept) != 0 || skipped != 0 {
		t.Fatalf("expected empty result for empty input, got kept=%d skipped=%d", len(kept), skipped)
	}
}

func TestResolveRetryFailedPath_DirectFile(t *testing.T) {
	dir := t.TempDir()
	f := filepath.Join(dir, "report.json")
	if err := os.WriteFile(f, []byte(`{"items":[]}`), 0o644); err != nil {
		t.Fatal(err)
	}

	got, err := ResolveRetryFailedPath(f)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if got != f {
		t.Fatalf("expected %q, got %q", f, got)
	}
}

func TestResolveRetryFailedPath_DirectoryPicksNewest(t *testing.T) {
	dir := t.TempDir()
	older := filepath.Join(dir, "failed-aaaaaaaa-20260101-000000.json")
	newer := filepath.Join(dir, "failed-bbbbbbbb-20260102-000000.json")
	if err := os.WriteFile(older, []byte(`{"items":[]}`), 0o644); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(newer, []byte(`{"items":[]}`), 0o644); err != nil {
		t.Fatal(err)
	}
	now := time.Now()
	if err := os.Chtimes(older, now.Add(-time.Hour), now.Add(-time.Hour)); err != nil {
		t.Fatal(err)
	}
	if err := os.Chtimes(newer, now, now); err != nil {
		t.Fatal(err)
	}

	got, err := ResolveRetryFailedPath(dir)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if got != newer {
		t.Fatalf("expected newest file %q, got %q", newer, got)
	}
}

func TestResolveRetryFailedPath_EmptyDirectoryErrors(t *testing.T) {
	dir := t.TempDir()
	if _, err := ResolveRetryFailedPath(dir); err == nil {
		t.Fatal("expected an error for a directory with no failed-*.json report, got nil")
	}
}

func TestResolveRetryFailedPath_MissingPathErrors(t *testing.T) {
	if _, err := ResolveRetryFailedPath(filepath.Join(t.TempDir(), "nope.json")); err == nil {
		t.Fatal("expected an error for a nonexistent path, got nil")
	}
}
