package report

import (
	"os"
	"path/filepath"
	"testing"
)

func TestParseListingState_Valid(t *testing.T) {
	data := []byte(`{"folder_id":"abc123","item_count":42,"listed_at":"2026-07-19T00:00:00Z","run_id":"deadbeef"}`)
	st, err := ParseListingState(data)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if st.FolderID != "abc123" || st.ItemCount != 42 || st.RunID != "deadbeef" {
		t.Fatalf("unexpected parsed state: %+v", st)
	}
}

func TestParseListingState_CorruptJSON(t *testing.T) {
	if _, err := ParseListingState([]byte("{not json")); err == nil {
		t.Fatal("expected an error for corrupt JSON, got nil")
	}
}

func TestReadListingState_MissingFileReturnsNil(t *testing.T) {
	dir := t.TempDir()
	if st := ReadListingState(dir, "nope"); st != nil {
		t.Fatalf("expected nil for a missing state file, got %+v", st)
	}
}

func TestReadListingState_CorruptFileReturnsNilNotError(t *testing.T) {
	dir := t.TempDir()
	if err := os.WriteFile(filepath.Join(dir, "tag1.json"), []byte("{garbage"), 0o644); err != nil {
		t.Fatal(err)
	}
	if st := ReadListingState(dir, "tag1"); st != nil {
		t.Fatalf("expected nil for a corrupt state file (must not panic/fail the run), got %+v", st)
	}
}

func TestWriteListingState_ThenReadRoundTrips(t *testing.T) {
	dir := t.TempDir()
	stateDir := filepath.Join(dir, "state")

	if err := WriteListingState(stateDir, "tag1", "folder-xyz", 123, "run1"); err != nil {
		t.Fatalf("unexpected error: %v", err)
	}

	st := ReadListingState(stateDir, "tag1")
	if st == nil {
		t.Fatal("expected state to round-trip, got nil")
	}
	if st.FolderID != "folder-xyz" || st.ItemCount != 123 || st.RunID != "run1" {
		t.Fatalf("unexpected round-tripped state: %+v", st)
	}
	if st.ListedAt == "" {
		t.Fatal("expected ListedAt to be populated")
	}
}

func TestWriteListingState_OverwritesPreviousRun(t *testing.T) {
	dir := t.TempDir()
	stateDir := filepath.Join(dir, "state")

	if err := WriteListingState(stateDir, "tag1", "folder-xyz", 100, "run1"); err != nil {
		t.Fatal(err)
	}
	if err := WriteListingState(stateDir, "tag1", "folder-xyz", 200, "run2"); err != nil {
		t.Fatal(err)
	}

	st := ReadListingState(stateDir, "tag1")
	if st == nil || st.ItemCount != 200 || st.RunID != "run2" {
		t.Fatalf("expected the second write to win, got %+v", st)
	}
}
