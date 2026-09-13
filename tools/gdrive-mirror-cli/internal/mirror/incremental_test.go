package mirror

import (
	"os"
	"path/filepath"
	"testing"

	"github.com/vswb/gdrive-mirror/internal/report"
)

const testRoot = "rootFolderID"

// TestComputeDirtyFolders_NewFileUnderKnownFolder is the common case: a
// brand new file appears directly under an already-tracked folder.
func TestComputeDirtyFolders_NewFileUnderKnownFolder(t *testing.T) {
	manifest := map[string]report.ManifestEntry{
		"sub1": {ParentID: testRoot, IsFolder: true, Path: "sub1"},
	}
	changes := []driveChange{
		{fileID: "newFile1", parents: []string{"sub1"}},
	}

	dirty := computeDirtyFolders(testRoot, manifest, changes)
	if !dirty["sub1"] || len(dirty) != 1 {
		t.Fatalf("expected exactly {sub1: true}, got %+v", dirty)
	}
}

// TestComputeDirtyFolders_ChangeUnderUnrelatedFolder must NOT mark anything
// dirty — the service account can see changes to items outside our tracked
// tree too (anything else it has access to), and those must be silently
// ignored rather than triggering pointless re-listings.
func TestComputeDirtyFolders_ChangeUnderUnrelatedFolder(t *testing.T) {
	manifest := map[string]report.ManifestEntry{
		"sub1": {ParentID: testRoot, IsFolder: true, Path: "sub1"},
	}
	changes := []driveChange{
		{fileID: "unrelatedFile", parents: []string{"someOtherFolderNotOurs"}},
	}

	dirty := computeDirtyFolders(testRoot, manifest, changes)
	if len(dirty) != 0 {
		t.Fatalf("expected no dirty folders for an unrelated change, got %+v", dirty)
	}
}

// TestComputeDirtyFolders_RemovedFileMarksOldParentDirty covers deletion:
// the change event for a removed/trashed known file must mark its OLD
// parent dirty so a fresh listing confirms it's actually gone.
func TestComputeDirtyFolders_RemovedFileMarksOldParentDirty(t *testing.T) {
	manifest := map[string]report.ManifestEntry{
		"file1": {ParentID: "sub1", IsFolder: false, Path: "sub1/file1.pdf"},
		"sub1":  {ParentID: testRoot, IsFolder: true, Path: "sub1"},
	}
	changes := []driveChange{
		{fileID: "file1", removed: true},
	}

	dirty := computeDirtyFolders(testRoot, manifest, changes)
	if !dirty["sub1"] || len(dirty) != 1 {
		t.Fatalf("expected exactly {sub1: true}, got %+v", dirty)
	}
}

// TestComputeDirtyFolders_MovedFileMarksBothOldAndNewParentDirty: a file
// moving from one tracked folder to another must invalidate BOTH sides —
// missing either would either leave a stale entry behind (old parent) or
// never discover the file under its new location (new parent).
func TestComputeDirtyFolders_MovedFileMarksBothOldAndNewParentDirty(t *testing.T) {
	manifest := map[string]report.ManifestEntry{
		"file1": {ParentID: "sub1", IsFolder: false, Path: "sub1/file1.pdf"},
		"sub1":  {ParentID: testRoot, IsFolder: true, Path: "sub1"},
		"sub2":  {ParentID: testRoot, IsFolder: true, Path: "sub2"},
	}
	changes := []driveChange{
		{fileID: "file1", parents: []string{"sub2"}}, // moved sub1 -> sub2
	}

	dirty := computeDirtyFolders(testRoot, manifest, changes)
	if !dirty["sub1"] || !dirty["sub2"] || len(dirty) != 2 {
		t.Fatalf("expected {sub1: true, sub2: true}, got %+v", dirty)
	}
}

// TestComputeDirtyFolders_NewFileDirectlyUnderRoot covers the root folder
// itself as a valid dirty target — it's "known" even though it has no
// manifest ENTRY of its own (only its children do).
func TestComputeDirtyFolders_NewFileDirectlyUnderRoot(t *testing.T) {
	manifest := map[string]report.ManifestEntry{}
	changes := []driveChange{
		{fileID: "newFileAtRoot", parents: []string{testRoot}},
	}

	dirty := computeDirtyFolders(testRoot, manifest, changes)
	if !dirty[testRoot] || len(dirty) != 1 {
		t.Fatalf("expected {%s: true}, got %+v", testRoot, dirty)
	}
}

// TestMergeManifest_DropsStaleChildOfVisitedFolder is the removal case at
// the merge stage: an old entry whose parent was freshly re-listed but is
// NOT present in fresh anymore must be dropped, not carried forward as a
// ghost entry.
func TestMergeManifest_DropsStaleChildOfVisitedFolder(t *testing.T) {
	old := map[string]report.ManifestEntry{
		"file1": {ParentID: "sub1", Path: "sub1/file1.pdf"},
		"sub1":  {ParentID: testRoot, Path: "sub1", IsFolder: true},
	}
	visited := map[string]bool{"sub1": true} // sub1's children were re-listed...
	fresh := map[string]report.ManifestEntry{
		// ...and file1 is genuinely gone now (deleted on Drive) — fresh has
		// no entry for it at all.
	}

	merged := mergeManifest(old, visited, fresh)
	if _, exists := merged["file1"]; exists {
		t.Fatalf("expected file1 to be dropped (its parent was re-listed and it's no longer in fresh), got %+v", merged)
	}
	if _, exists := merged["sub1"]; !exists {
		t.Fatalf("expected sub1 itself to survive (its OWN parent, root, was never visited): %+v", merged)
	}
}

// TestMergeManifest_UntouchedSubtreePassesThroughUnchanged is the whole
// point of incremental sync: entries whose parent was never in `visited`
// must survive byte-for-byte, with zero re-derivation.
func TestMergeManifest_UntouchedSubtreePassesThroughUnchanged(t *testing.T) {
	old := map[string]report.ManifestEntry{
		"untouchedFile": {ParentID: "untouchedFolder", Path: "untouchedFolder/x.pdf", MD5Checksum: "abc"},
	}
	merged := mergeManifest(old, map[string]bool{}, map[string]report.ManifestEntry{})

	got, ok := merged["untouchedFile"]
	if !ok || got.MD5Checksum != "abc" {
		t.Fatalf("expected untouchedFile to pass through unchanged, got %+v ok=%v", got, ok)
	}
}

// TestMergeManifest_FreshEntryOverridesOldOnRename: when a known item is
// renamed (same ID, new Path), fresh must win even if, for some reason, an
// old entry with the same ID slipped into the "keep" set.
func TestMergeManifest_FreshEntryOverridesOldOnRename(t *testing.T) {
	old := map[string]report.ManifestEntry{
		"file1": {ParentID: "sub1", Path: "sub1/old-name.pdf"},
	}
	visited := map[string]bool{"sub1": true}
	fresh := map[string]report.ManifestEntry{
		"file1": {ParentID: "sub1", Path: "sub1/new-name.pdf"},
	}

	merged := mergeManifest(old, visited, fresh)
	if merged["file1"].Path != "sub1/new-name.pdf" {
		t.Fatalf("expected fresh (renamed) entry to win, got %+v", merged["file1"])
	}
}

// TestReadWriteIncrementalManifest_RoundTrip is the persistence sanity
// check: what WriteIncrementalManifest writes, ReadIncrementalManifest must
// read back byte-for-byte (field-for-field).
func TestReadWriteIncrementalManifest_RoundTrip(t *testing.T) {
	dir := t.TempDir()
	m := report.IncrementalManifest{
		FolderID:       testRoot,
		LocalPrefix:    "MyFolder",
		StartPageToken: "12345",
		Items: map[string]report.ManifestEntry{
			"file1": {ParentID: testRoot, Path: "file1.pdf", MD5Checksum: "abc", Size: 100},
		},
	}
	if err := report.WriteIncrementalManifest(dir, "tag", m); err != nil {
		t.Fatalf("write: %v", err)
	}

	got := report.ReadIncrementalManifest(dir, "tag")
	if got == nil {
		t.Fatalf("expected a manifest back, got nil")
	}
	if got.StartPageToken != "12345" || got.LocalPrefix != "MyFolder" {
		t.Fatalf("got %+v", got)
	}
	if got.Items["file1"].MD5Checksum != "abc" {
		t.Fatalf("got items %+v", got.Items)
	}
}

// TestReadIncrementalManifest_MissingFileReturnsNil: no prior state is a
// normal first-run condition, never an error the caller has to special-case.
func TestReadIncrementalManifest_MissingFileReturnsNil(t *testing.T) {
	if got := report.ReadIncrementalManifest(t.TempDir(), "nope"); got != nil {
		t.Fatalf("expected nil for a missing manifest file, got %+v", got)
	}
}

// TestReadIncrementalManifest_CorruptJSONReturnsNil: a damaged manifest
// must degrade to "no prior state", never fail the whole run.
func TestReadIncrementalManifest_CorruptJSONReturnsNil(t *testing.T) {
	dir := t.TempDir()
	if err := os.WriteFile(filepath.Join(dir, "tag.json"), []byte("{not valid json"), 0o644); err != nil {
		t.Fatalf("setup: %v", err)
	}
	if got := report.ReadIncrementalManifest(dir, "tag"); got != nil {
		t.Fatalf("expected nil for corrupt JSON, got %+v", got)
	}
}
