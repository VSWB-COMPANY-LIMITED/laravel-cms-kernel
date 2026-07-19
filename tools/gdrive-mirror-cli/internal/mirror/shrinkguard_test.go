package mirror

import (
	"os"
	"path/filepath"
	"testing"
)

// newShrinkGuardTestSyncer nests cfg.Path one level inside t.TempDir()
// (unlike newTestSyncer in sync_test.go, which uses t.TempDir() itself as
// cfg.Path) so that s.reportDir() — a SIBLING of cfg.Path, not a
// subdirectory of it, matching the real "gdrive-mirror-reports next to
// --path" layout — resolves to somewhere still inside t.TempDir() and gets
// cleaned up automatically. applyShrinkGuard does real file I/O under
// reportDir(); reusing the plain newTestSyncer here would leak
// gdrive-mirror-reports/ directories into the OS temp root on every test
// run.
func newShrinkGuardTestSyncer(t *testing.T) *Syncer {
	t.Helper()
	dest := filepath.Join(t.TempDir(), "dest")
	return &Syncer{cfg: Config{Path: dest}, runID: "x"}
}

// TestApplyShrinkGuard_FirstRunNeverAborts covers the "no prior state" case
// — a folder synced for the very first time has nothing to compare against,
// so applyShrinkGuard must proceed and simply record a baseline.
func TestApplyShrinkGuard_FirstRunNeverAborts(t *testing.T) {
	s := newShrinkGuardTestSyncer(t)
	s.cfg.FolderID = "folder1"

	if aborted := s.applyShrinkGuard(100); aborted {
		t.Fatal("expected no abort on the very first run (no prior state)")
	}
	if s.stats.Aborted {
		t.Fatal("stats.Aborted must stay false on a first-run baseline")
	}

	entries, err := os.ReadDir(filepath.Join(s.reportDir(), "state"))
	if err != nil || len(entries) != 1 {
		t.Fatalf("expected exactly 1 state file to be written as the new baseline, got entries=%v err=%v", entries, err)
	}
}

// TestApplyShrinkGuard_TripsOnBigDrop is the core regression: a listing that
// comes back well below the previous run's count (below the 0.8 ratio) must
// abort and must NOT silently proceed to mirror over what could be a
// truncated/permission-revoked listing.
func TestApplyShrinkGuard_TripsOnBigDrop(t *testing.T) {
	s := newShrinkGuardTestSyncer(t)
	s.cfg.FolderID = "folder1"

	if aborted := s.applyShrinkGuard(100); aborted {
		t.Fatal("seeding baseline should not abort")
	}

	aborted := s.applyShrinkGuard(50) // 50 < 100*0.8=80 → must abort
	if !aborted {
		t.Fatal("expected the shrink guard to trip on a 50% drop")
	}
	if !s.stats.Aborted || s.stats.AbortReason != "shrink-guard" {
		t.Fatalf("expected stats.Aborted=true reason=shrink-guard, got Aborted=%v Reason=%q", s.stats.Aborted, s.stats.AbortReason)
	}
}

// TestApplyShrinkGuard_WithinRatioDoesNotAbort is the non-degenerate
// "healthy" branch: a small, normal decrease (well within the 0.8 ratio)
// must NOT trip the guard — this is the everyday case (a few files deleted
// on Drive) and must keep working exactly like before this guard existed.
func TestApplyShrinkGuard_WithinRatioDoesNotAbort(t *testing.T) {
	s := newShrinkGuardTestSyncer(t)
	s.cfg.FolderID = "folder1"

	s.applyShrinkGuard(100)
	if aborted := s.applyShrinkGuard(90); aborted { // 90 >= 100*0.8=80 → fine
		t.Fatal("a 10% drop must not trip the shrink guard")
	}
	if s.stats.Aborted {
		t.Fatal("stats.Aborted must stay false when the guard does not trip")
	}
}

// TestApplyShrinkGuard_IgnoreShrinkBypasses covers the --ignore-shrink
// escape hatch: a user who deliberately deleted a lot of files on Drive
// must be able to force the run through despite the drop.
func TestApplyShrinkGuard_IgnoreShrinkBypasses(t *testing.T) {
	s := newShrinkGuardTestSyncer(t)
	s.cfg.FolderID = "folder1"
	s.cfg.IgnoreShrink = true

	s.applyShrinkGuard(100)
	if aborted := s.applyShrinkGuard(10); aborted {
		t.Fatal("--ignore-shrink must bypass the guard even on a huge drop")
	}
	if s.stats.Aborted {
		t.Fatal("stats.Aborted must stay false when --ignore-shrink bypasses the guard")
	}
}

// TestApplyShrinkGuard_DryRunNeverTouchesState covers the "--dry-run must
// never write state" requirement: a throwaway debug listing must not become
// the baseline future real runs get compared against.
func TestApplyShrinkGuard_DryRunNeverTouchesState(t *testing.T) {
	s := newShrinkGuardTestSyncer(t)
	s.cfg.FolderID = "folder1"
	s.cfg.DryRun = true

	if aborted := s.applyShrinkGuard(1); aborted {
		t.Fatal("dry-run must never abort")
	}

	stateDir := filepath.Join(s.reportDir(), "state")
	if _, err := os.Stat(stateDir); err == nil {
		t.Fatal("dry-run must not create a state directory/file")
	}
}

// TestApplyShrinkGuard_CorruptStateFileIsIgnored covers "file state
// hỏng/không đọc được → coi như không có, KHÔNG làm fail run": a garbage
// state file must be treated as if there were no prior state at all,
// instead of erroring or panicking.
func TestApplyShrinkGuard_CorruptStateFileIsIgnored(t *testing.T) {
	s := newShrinkGuardTestSyncer(t)
	s.cfg.FolderID = "folder1"

	stateDir := filepath.Join(s.reportDir(), "state")
	if err := os.MkdirAll(stateDir, 0o755); err != nil {
		t.Fatal(err)
	}
	// Deliberately corrupt — must not panic and must not abort.
	if err := os.WriteFile(filepath.Join(stateDir, "e18cd6dc.json"), []byte("{not json"), 0o644); err != nil {
		t.Fatal(err)
	}

	if aborted := s.applyShrinkGuard(1); aborted {
		t.Fatal("a corrupt state file must be treated as no prior state, not an abort")
	}
}

// TestApplyShrinkGuard_ZeroPreviousCountNeverAborts: a folder that was
// legitimately empty last time (prevCount == 0) has nothing to "shrink"
// from — dividing by zero must not happen and must never abort.
func TestApplyShrinkGuard_ZeroPreviousCountNeverAborts(t *testing.T) {
	s := newShrinkGuardTestSyncer(t)
	s.cfg.FolderID = "folder1"

	s.applyShrinkGuard(0)
	if aborted := s.applyShrinkGuard(5); aborted {
		t.Fatal("growing from a previous count of 0 must never abort")
	}
}
