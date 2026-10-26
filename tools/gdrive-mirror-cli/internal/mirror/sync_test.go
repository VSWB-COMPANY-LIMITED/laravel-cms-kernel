package mirror

import (
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/vswb/gdrive-mirror/internal/report"
)

func newTestSyncer(t *testing.T) *Syncer {
	t.Helper()
	return &Syncer{cfg: Config{Path: t.TempDir()}, runID: "x"}
}

// TestBuildPlan_NormalItemResolvesInsideRoot is the sanity baseline: a
// well-formed item still produces exactly one task, located under the
// configured root.
func TestBuildPlan_NormalItemResolvesInsideRoot(t *testing.T) {
	s := newTestSyncer(t)
	items := []Item{
		{Type: "file", Path: "notes.txt", ID: "id1", MimeType: "text/plain"},
	}

	tasks := s.buildPlan(items, "MyFolder")

	if len(tasks) != 1 {
		t.Fatalf("expected 1 task, got %d", len(tasks))
	}
	if !strings.HasPrefix(tasks[0].targetPath, s.cfg.Path) {
		t.Fatalf("targetPath %q escaped root %q", tasks[0].targetPath, s.cfg.Path)
	}
	wantSuffix := filepath.Join("MyFolder", "notes.txt")
	if !strings.HasSuffix(tasks[0].targetPath, wantSuffix) {
		t.Fatalf("targetPath = %q, want suffix %q", tasks[0].targetPath, wantSuffix)
	}
}

// TestBuildPlan_TraversalPathRejectedNotCircuitBreaker reproduces C5: even
// if a malformed/traversal Item.Path somehow reached buildPlan (list.go is
// supposed to prevent this via SanitizeComponent, but this is the
// defense-in-depth layer — localpath.SafeJoin), it must be rejected as a
// permanent failure WITHOUT writing outside --path, and must NOT be counted
// toward consecutiveLocalFsErrors (a hostile/malformed name is not evidence
// the destination disk is failing).
func TestBuildPlan_TraversalPathRejectedNotCircuitBreaker(t *testing.T) {
	s := newTestSyncer(t)
	items := []Item{
		{Type: "file", Path: "../../../etc/evil.txt", ID: "evilFile1", MimeType: "text/plain"},
	}

	tasks := s.buildPlan(items, "MyFolder")

	if len(tasks) != 0 {
		t.Fatalf("expected 0 tasks for a rejected traversal path, got %d", len(tasks))
	}
	if s.stats.Errors != 1 {
		t.Fatalf("expected 1 recorded error, got %d", s.stats.Errors)
	}
	if len(s.stats.FailedFiles) != 1 || !s.stats.FailedFiles[0].Permanent {
		t.Fatalf("expected 1 permanent FailedFiles entry, got %+v", s.stats.FailedFiles)
	}
	if s.consecutiveLocalFsErrors.Load() != 0 {
		t.Fatalf("traversal rejection must not increment the local-fs circuit breaker, got %d", s.consecutiveLocalFsErrors.Load())
	}

	escaped := filepath.Join(filepath.Dir(s.cfg.Path), "etc", "evil.txt")
	if _, err := os.Stat(escaped); err == nil {
		t.Fatalf("traversal path was actually created outside root: %s", escaped)
	}
}

// TestBuildPlan_TraversalDirRejected is the directory-item equivalent of the
// above — GDriveMirrorSync creates directories with a separate branch in
// buildPlan, so it needs its own regression coverage.
func TestBuildPlan_TraversalDirRejected(t *testing.T) {
	s := newTestSyncer(t)
	items := []Item{
		// One ".." cancels out the "MyFolder" prefix and stays inside root (not
		// an escape) — two levels are needed to actually go past root itself.
		{Type: "dir", Path: "../../escape-dir", ID: "evilDir1"},
	}

	tasks := s.buildPlan(items, "MyFolder")

	if len(tasks) != 0 {
		t.Fatalf("expected 0 tasks (dirs never produce a fileTask), got %d", len(tasks))
	}
	if s.stats.Folders != 0 {
		t.Fatalf("expected 0 folders created, got %d", s.stats.Folders)
	}
	if len(s.stats.FailedFiles) != 1 || !s.stats.FailedFiles[0].Permanent {
		t.Fatalf("expected 1 permanent FailedFiles entry for the rejected dir, got %+v", s.stats.FailedFiles)
	}
	if s.consecutiveLocalFsErrors.Load() != 0 {
		t.Fatalf("traversal rejection must not increment the local-fs circuit breaker, got %d", s.consecutiveLocalFsErrors.Load())
	}
}

// TestBuildPlan_DefenseInDepthCollisionFailsLoudly: buildPlan's `used` map is
// a last-resort safety net in case two items ever reach it with the same
// resolved target path (list.go's resolveSiblingNames is supposed to have
// already prevented this at the source). It must record a loud, permanent
// failure — never silently overwrite one item's plan with another's (the
// original C1/C2/C3 bug).
func TestBuildPlan_DefenseInDepthCollisionFailsLoudly(t *testing.T) {
	s := newTestSyncer(t)
	items := []Item{
		{Type: "file", Path: "dup.txt", ID: "winner", MimeType: "text/plain"},
		{Type: "file", Path: "dup.txt", ID: "loser", MimeType: "text/plain"},
	}

	tasks := s.buildPlan(items, "MyFolder")

	if len(tasks) != 1 {
		t.Fatalf("expected exactly 1 task to win the collision, got %d", len(tasks))
	}
	if tasks[0].item.ID != "winner" {
		t.Fatalf("expected first item (winner) to keep the slot, got id=%s", tasks[0].item.ID)
	}
	if len(s.stats.FailedFiles) != 1 || !s.stats.FailedFiles[0].Permanent {
		t.Fatalf("expected the loser to be recorded as a permanent failure, got %+v", s.stats.FailedFiles)
	}
	if s.stats.FailedFiles[0].ID == nil || *s.stats.FailedFiles[0].ID != "loser" {
		t.Fatalf("expected FailedFiles entry to reference the loser id, got %+v", s.stats.FailedFiles[0])
	}
}

// TestBuildPlan_UnsanitizedLocalPrefixRejected covers the localPrefix
// argument itself (the synced folder's own Drive name) as an attack vector,
// not just Item.Path: sync.go's Run() is expected to sanitize it before
// calling buildPlan, but SafeJoin's defense-in-depth must ALSO catch it if
// that ever regresses — a folder literally named "../.." must never be
// allowed to write outside --path just because it arrived as localPrefix
// instead of an item path.
func TestBuildPlan_UnsanitizedLocalPrefixRejected(t *testing.T) {
	s := newTestSyncer(t)
	items := []Item{
		{Type: "file", Path: "notes.txt", ID: "id1", MimeType: "text/plain"},
	}

	tasks := s.buildPlan(items, "../../escape")

	if len(tasks) != 0 {
		t.Fatalf("expected 0 tasks — a traversal localPrefix must be rejected by SafeJoin, got %d", len(tasks))
	}
	if len(s.stats.FailedFiles) != 1 || !s.stats.FailedFiles[0].Permanent {
		t.Fatalf("expected 1 permanent FailedFiles entry, got %+v", s.stats.FailedFiles)
	}
	if s.consecutiveLocalFsErrors.Load() != 0 {
		t.Fatalf("traversal rejection must not increment the local-fs circuit breaker, got %d", s.consecutiveLocalFsErrors.Load())
	}
}

// TestBuildPlan_KnownRename_MovesFileInsteadOfDuplicating is the core
// regression for the "Google Doc renamed on Drive leaves an orphaned
// old-name .docx behind" problem: when renameIndex says this ID used to
// live at a different path, and that old file is still there (and nothing
// already occupies the new path), buildPlan must MOVE it rather than leave
// it in place and let a fresh download create a second file.
func TestBuildPlan_KnownRename_MovesFileInsteadOfDuplicating(t *testing.T) {
	s := newTestSyncer(t)
	oldAbs := filepath.Join(s.cfg.Path, "MyFolder", "OldName.docx")
	if err := os.MkdirAll(filepath.Dir(oldAbs), 0o755); err != nil {
		t.Fatalf("setup mkdir: %v", err)
	}
	if err := os.WriteFile(oldAbs, []byte("doc content"), 0o644); err != nil {
		t.Fatalf("setup write: %v", err)
	}

	s.renameIndex = map[string]report.ManifestEntry{
		"docID1": {Path: "OldName.docx"},
	}
	items := []Item{
		{Type: "file", Path: "NewName.docx", ID: "docID1", MimeType: "application/vnd.google-apps.document"},
	}

	s.buildPlan(items, "MyFolder")

	newAbs := filepath.Join(s.cfg.Path, "MyFolder", "NewName.docx")
	if _, err := os.Stat(newAbs); err != nil {
		t.Fatalf("expected file moved to new path %s: %v", newAbs, err)
	}
	if _, err := os.Stat(oldAbs); !os.IsNotExist(err) {
		t.Fatalf("expected old path %s to be gone after move, stat err=%v", oldAbs, err)
	}
	content, err := os.ReadFile(newAbs)
	if err != nil || string(content) != "doc content" {
		t.Fatalf("content not preserved by move: err=%v content=%q", err, content)
	}
	if s.stats.RenamesApplied != 1 {
		t.Fatalf("RenamesApplied = %d, want 1", s.stats.RenamesApplied)
	}
}

// TestBuildPlan_KnownRename_MovesWholeFolderInOneShot covers the folder
// case: renaming the TOP folder must relocate its entire subtree in one
// move (via the folder Item's own rename, which is always visited before
// its descendants in listing order) — not leave the whole old tree behind
// while a second full copy gets downloaded under the new name.
func TestBuildPlan_KnownRename_MovesWholeFolderInOneShot(t *testing.T) {
	s := newTestSyncer(t)
	oldDirAbs := filepath.Join(s.cfg.Path, "MyFolder", "OldDirName")
	if err := os.MkdirAll(oldDirAbs, 0o755); err != nil {
		t.Fatalf("setup mkdir: %v", err)
	}
	innerFile := filepath.Join(oldDirAbs, "inner.txt")
	if err := os.WriteFile(innerFile, []byte("inner content"), 0o644); err != nil {
		t.Fatalf("setup write: %v", err)
	}

	s.renameIndex = map[string]report.ManifestEntry{
		"folderID1": {Path: "OldDirName"},
		"innerID1":  {Path: "OldDirName/inner.txt"},
	}
	// Depth-first order: the folder's own Item always comes before its
	// children's, exactly as assembleItems produces.
	items := []Item{
		{Type: "dir", Path: "NewDirName", ID: "folderID1"},
		{Type: "file", Path: "NewDirName/inner.txt", ID: "innerID1", MimeType: "text/plain"},
	}

	s.buildPlan(items, "MyFolder")

	newInner := filepath.Join(s.cfg.Path, "MyFolder", "NewDirName", "inner.txt")
	content, err := os.ReadFile(newInner)
	if err != nil || string(content) != "inner content" {
		t.Fatalf("expected inner file moved along with its parent dir: err=%v content=%q", err, content)
	}
	if _, err := os.Stat(oldDirAbs); !os.IsNotExist(err) {
		t.Fatalf("expected old dir %s to be gone after the folder move, stat err=%v", oldDirAbs, err)
	}
	// Only ONE rename should have actually happened at the filesystem level
	// (the folder's own) — the inner file's own lookup must find its
	// recorded old path already gone (moved away with its parent) and
	// quietly no-op, not count as a second rename.
	if s.stats.RenamesApplied != 1 {
		t.Fatalf("RenamesApplied = %d, want 1 (only the folder's own move)", s.stats.RenamesApplied)
	}
}

// TestBuildPlan_KnownRename_NeverOverwritesExistingDestination: if the new
// path is somehow already occupied (e.g. a previous partial run already
// created it), the move must be skipped entirely rather than risk
// clobbering content that's already there — "never overwrite" beats "avoid
// a duplicate" when the two conflict.
func TestBuildPlan_KnownRename_NeverOverwritesExistingDestination(t *testing.T) {
	s := newTestSyncer(t)
	dir := filepath.Join(s.cfg.Path, "MyFolder")
	if err := os.MkdirAll(dir, 0o755); err != nil {
		t.Fatalf("setup mkdir: %v", err)
	}
	oldAbs := filepath.Join(dir, "OldName.docx")
	newAbs := filepath.Join(dir, "NewName.docx")
	if err := os.WriteFile(oldAbs, []byte("old content"), 0o644); err != nil {
		t.Fatalf("setup write old: %v", err)
	}
	if err := os.WriteFile(newAbs, []byte("already here"), 0o644); err != nil {
		t.Fatalf("setup write new: %v", err)
	}

	s.renameIndex = map[string]report.ManifestEntry{
		"docID1": {Path: "OldName.docx"},
	}
	items := []Item{
		{Type: "file", Path: "NewName.docx", ID: "docID1", MimeType: "application/vnd.google-apps.document"},
	}

	s.buildPlan(items, "MyFolder")

	if content, err := os.ReadFile(newAbs); err != nil || string(content) != "already here" {
		t.Fatalf("existing destination content must be untouched: err=%v content=%q", err, content)
	}
	if _, err := os.Stat(oldAbs); err != nil {
		t.Fatalf("old file must still exist (move must have been skipped): %v", err)
	}
	if s.stats.RenamesApplied != 0 {
		t.Fatalf("RenamesApplied = %d, want 0 (destination already occupied)", s.stats.RenamesApplied)
	}
}

// TestBuildPlan_KnownRename_NoOpWhenOldPathMissing: the index says this ID
// used to live somewhere, but nothing is there anymore (already moved in an
// earlier run, never successfully downloaded, or removed by hand) — must
// not error, must just fall through to the normal plan.
func TestBuildPlan_KnownRename_NoOpWhenOldPathMissing(t *testing.T) {
	s := newTestSyncer(t)
	s.renameIndex = map[string]report.ManifestEntry{
		"docID1": {Path: "OldName.docx"},
	}
	items := []Item{
		{Type: "file", Path: "NewName.docx", ID: "docID1", MimeType: "application/vnd.google-apps.document"},
	}

	tasks := s.buildPlan(items, "MyFolder")

	if len(tasks) != 1 {
		t.Fatalf("expected the normal plan (1 download task) when there's nothing to move, got %d", len(tasks))
	}
	if s.stats.RenamesApplied != 0 {
		t.Fatalf("RenamesApplied = %d, want 0", s.stats.RenamesApplied)
	}
}
