package mirror

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
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
