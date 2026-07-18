package mirror

import (
	"path/filepath"
	"strings"
	"testing"
)

// TestTempDownloadPath_FixedLengthRegardlessOfTargetNameLength reproduces
// L1: the old scheme (targetPath + ".tmp-" + runID) added bytes ON TOP OF an
// already up-to-255-byte target component, so a target name already near the
// limit failed the moment a download was attempted. The new tmp filename
// must have the SAME length whether the target name is short or already at
// the 255-byte ceiling.
func TestTempDownloadPath_FixedLengthRegardlessOfTargetNameLength(t *testing.T) {
	shortTarget := filepath.Join("/mirror/root", "a.txt")
	longTarget := filepath.Join("/mirror/root", strings.Repeat("a", 250)+".txt")

	shortTmp := filepath.Base(tempDownloadPath(shortTarget, "abcd1234"))
	longTmp := filepath.Base(tempDownloadPath(longTarget, "abcd1234"))

	if len(shortTmp) != len(longTmp) {
		t.Fatalf("tmp filename length depends on target name length: short=%d(%q) long=%d(%q)", len(shortTmp), shortTmp, len(longTmp), longTmp)
	}
}

// TestTempDownloadPath_NeverExceedsComponentLimitAtMaxTargetLength directly
// proves the old bug is fixed: a target component sitting exactly at the
// 255-byte ceiling (the max localpath.TruncateComponent will ever produce)
// must still get a valid, non-empty tmp path.
func TestTempDownloadPath_NeverExceedsComponentLimitAtMaxTargetLength(t *testing.T) {
	maxTarget := filepath.Join("/mirror/root", strings.Repeat("a", 251)+".txt") // 255 bytes exactly
	tmp := tempDownloadPath(maxTarget, "abcd1234")
	if filepath.Base(tmp) == "" {
		t.Fatalf("expected a non-empty tmp filename")
	}
	if len(filepath.Base(tmp)) > 255 {
		t.Fatalf("tmp filename itself exceeds 255 bytes: %d", len(filepath.Base(tmp)))
	}
}

func TestTempDownloadPath_SameDirAsTarget(t *testing.T) {
	target := filepath.Join("/mirror/root", "sub", "file.pdf")
	tmp := tempDownloadPath(target, "abcd1234")
	if filepath.Dir(tmp) != filepath.Dir(target) {
		t.Fatalf("tmp path dir = %q, want %q (same dir as target)", filepath.Dir(tmp), filepath.Dir(target))
	}
}

func TestTempDownloadPath_DifferentTargetsDoNotCollide(t *testing.T) {
	a := tempDownloadPath(filepath.Join("/mirror/root", "a.pdf"), "abcd1234")
	b := tempDownloadPath(filepath.Join("/mirror/root", "b.pdf"), "abcd1234")
	if a == b {
		t.Fatalf("two different targets produced the same tmp path: %q", a)
	}
}

func TestTempDownloadPath_DeterministicForSameTargetAndRun(t *testing.T) {
	target := filepath.Join("/mirror/root", "a.pdf")
	a := tempDownloadPath(target, "abcd1234")
	b := tempDownloadPath(target, "abcd1234")
	if a != b {
		t.Fatalf("tempDownloadPath is not deterministic for the same input: %q vs %q", a, b)
	}
}

// TestTempDownloadPath_DifferentRunsDoNotCollide guards concurrent runs
// against the same destination (the original doc comment's stated intent
// for including runID at all).
func TestTempDownloadPath_DifferentRunsDoNotCollide(t *testing.T) {
	target := filepath.Join("/mirror/root", "a.pdf")
	a := tempDownloadPath(target, "run1")
	b := tempDownloadPath(target, "run2")
	if a == b {
		t.Fatalf("two different runIDs produced the same tmp path: %q", a)
	}
}
