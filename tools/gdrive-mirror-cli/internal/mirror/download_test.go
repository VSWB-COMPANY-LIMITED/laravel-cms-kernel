package mirror

import (
	"crypto/md5"
	"encoding/hex"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
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

// writeFileAt writes content to path and sets its mtime to exactly mtime
// (truncated to the second, matching downloadItem's os.Chtimes + Item's unix
// timestamp granularity).
func writeFileAt(t *testing.T, path string, content []byte, mtime time.Time) {
	t.Helper()
	if err := os.WriteFile(path, content, 0o644); err != nil {
		t.Fatalf("write %s: %v", path, err)
	}
	mtime = mtime.Truncate(time.Second)
	if err := os.Chtimes(path, mtime, mtime); err != nil {
		t.Fatalf("chtimes %s: %v", path, err)
	}
}

func md5Hex(content []byte) string {
	sum := md5.Sum(content)
	return hex.EncodeToString(sum[:])
}

// TestEvaluateLocal_FastPath_SkipsHashWhenMTimeExactlyMatchesRemote covers
// the optimization: downloadItem always sets the local file's mtime to
// EXACTLY Drive's reported ModifiedTime on success, so size+mtime matching
// that value is already proof the content matches RemoteMD5 — no need to
// re-read and re-hash the file. Proven here by giving the file WRONG
// on-disk content for its claimed MD5: if evaluateLocal actually hashed it,
// LocalMD5 would NOT equal RemoteMD5 and ShouldDownload would trigger a
// redundant re-download; the fast path must report LocalMD5 == RemoteMD5
// regardless, by construction.
func TestEvaluateLocal_FastPath_SkipsHashWhenMTimeExactlyMatchesRemote(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "file.txt")
	remoteContent := []byte("this is what Drive actually has")
	onDiskContent := []byte(strings.Repeat("X", len(remoteContent))) // deliberately wrong, same size
	remoteMTime := time.Date(2026, 1, 15, 10, 0, 0, 0, time.UTC)
	writeFileAt(t, path, onDiskContent, remoteMTime)

	s := &Syncer{}
	task := fileTask{
		item: Item{
			Size:         int64(len(remoteContent)),
			MD5Checksum:  md5Hex(remoteContent),
			ModifiedTime: remoteMTime.Unix(),
		},
		targetPath: path,
	}

	dc, err := s.evaluateLocal(task)
	if err != nil {
		t.Fatalf("evaluateLocal: %v", err)
	}
	if dc.LocalMD5 != dc.RemoteMD5 {
		t.Fatalf("fast path did not trust the mtime match: LocalMD5=%q RemoteMD5=%q", dc.LocalMD5, dc.RemoteMD5)
	}
	if ShouldDownload(dc) {
		t.Fatalf("ShouldDownload=true, want false (fast path should have treated this as unchanged)")
	}
}

// TestEvaluateLocal_FastPath_FallsBackToHashWhenMTimeDiffers is the
// necessary counterpart: when mtime does NOT exactly match remote
// (first download seen from a different run, or a file genuinely touched by
// something else), evaluateLocal must still hash for real and catch a
// content mismatch — the fast path must never widen into a weaker check.
func TestEvaluateLocal_FastPath_FallsBackToHashWhenMTimeDiffers(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "file.txt")
	remoteContent := []byte("this is what Drive actually has")
	onDiskContent := []byte(strings.Repeat("X", len(remoteContent))) // same size, different content
	localMTime := time.Date(2020, 1, 1, 0, 0, 0, 0, time.UTC)        // deliberately NOT the remote mtime
	writeFileAt(t, path, onDiskContent, localMTime)

	remoteMTime := time.Date(2026, 1, 15, 10, 0, 0, 0, time.UTC)
	s := &Syncer{}
	task := fileTask{
		item: Item{
			Size:         int64(len(remoteContent)),
			MD5Checksum:  md5Hex(remoteContent),
			ModifiedTime: remoteMTime.Unix(),
		},
		targetPath: path,
	}

	dc, err := s.evaluateLocal(task)
	if err != nil {
		t.Fatalf("evaluateLocal: %v", err)
	}
	if dc.LocalMD5 != md5Hex(onDiskContent) {
		t.Fatalf("expected a real hash of on-disk content, got LocalMD5=%q", dc.LocalMD5)
	}
	if !ShouldDownload(dc) {
		t.Fatalf("ShouldDownload=false, want true (content actually differs from Drive)")
	}
}
