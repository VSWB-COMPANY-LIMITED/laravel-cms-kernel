package mirror

import (
	"context"
	"os"
	"path/filepath"
	"testing"
	"time"
)

func TestInterpretRobocopyExitCode_SuccessCodes(t *testing.T) {
	for code := 0; code <= 7; code++ {
		if err := interpretRobocopyExitCode(code); err != nil {
			t.Errorf("code %d: expected success (nil), got %v", code, err)
		}
	}
}

func TestInterpretRobocopyExitCode_FailureCodes(t *testing.T) {
	for _, code := range []int{8, 9, 16, 24} {
		if err := interpretRobocopyExitCode(code); err == nil {
			t.Errorf("code %d: expected an error (>=8 means failure per robocopy's own contract), got nil", code)
		}
	}
}

func TestMtimeEqualEnough(t *testing.T) {
	base := time.Date(2026, 1, 1, 12, 0, 0, 0, time.UTC)
	cases := []struct {
		name string
		a, b time.Time
		want bool
	}{
		{"identical", base, base, true},
		{"1s drift (FAT32 rounding)", base, base.Add(1 * time.Second), true},
		{"exactly 2s drift", base, base.Add(2 * time.Second), true},
		{"3s drift — genuinely different", base, base.Add(3 * time.Second), false},
		{"negative drift within tolerance", base, base.Add(-1 * time.Second), true},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			if got := mtimeEqualEnough(tc.a, tc.b); got != tc.want {
				t.Errorf("mtimeEqualEnough(%v, %v) = %v, want %v", tc.a, tc.b, got, tc.want)
			}
		})
	}
}

// TestPushWithGoCopier_CopiesNewFiles is the baseline: a fresh destination
// with nothing in it yet gets every file from src.
func TestPushWithGoCopier_CopiesNewFiles(t *testing.T) {
	src := t.TempDir()
	dst := t.TempDir()

	writeFile(t, filepath.Join(src, "a.txt"), "hello")
	writeFile(t, filepath.Join(src, "sub", "b.txt"), "world")

	result, err := pushWithGoCopier(context.Background(), src, dst, func(string, ...any) {})
	if err != nil {
		t.Fatalf("push: %v", err)
	}
	if result.Copied != 2 || result.Skipped != 0 || result.Errors != 0 {
		t.Fatalf("got %+v, want Copied=2 Skipped=0 Errors=0", result)
	}
	assertFileContent(t, filepath.Join(dst, "a.txt"), "hello")
	assertFileContent(t, filepath.Join(dst, "sub", "b.txt"), "world")
}

// TestPushWithGoCopier_SkipsUnchangedOnSecondRun is the whole point of the
// push step: a second run against an already-pushed destination must not
// rewrite anything that's already correct — this is what keeps repeated
// pushes into a live cloud-sync folder from repeatedly touching files that
// haven't actually changed.
func TestPushWithGoCopier_SkipsUnchangedOnSecondRun(t *testing.T) {
	src := t.TempDir()
	dst := t.TempDir()
	writeFile(t, filepath.Join(src, "a.txt"), "hello")

	if _, err := pushWithGoCopier(context.Background(), src, dst, func(string, ...any) {}); err != nil {
		t.Fatalf("first push: %v", err)
	}

	result, err := pushWithGoCopier(context.Background(), src, dst, func(string, ...any) {})
	if err != nil {
		t.Fatalf("second push: %v", err)
	}
	if result.Copied != 0 || result.Skipped != 1 {
		t.Fatalf("second run got %+v, want Copied=0 Skipped=1 (nothing changed)", result)
	}
}

// TestPushWithGoCopier_RecopiesChangedContent: a file whose SIZE differs
// from what's already at dst must be recopied (content genuinely changed),
// even though skip-on-match is otherwise the default.
func TestPushWithGoCopier_RecopiesChangedContent(t *testing.T) {
	src := t.TempDir()
	dst := t.TempDir()
	writeFile(t, filepath.Join(src, "a.txt"), "short")

	if _, err := pushWithGoCopier(context.Background(), src, dst, func(string, ...any) {}); err != nil {
		t.Fatalf("first push: %v", err)
	}

	writeFile(t, filepath.Join(src, "a.txt"), "a much longer replacement content")
	result, err := pushWithGoCopier(context.Background(), src, dst, func(string, ...any) {})
	if err != nil {
		t.Fatalf("second push: %v", err)
	}
	if result.Copied != 1 || result.Skipped != 0 {
		t.Fatalf("got %+v, want Copied=1 (size changed)", result)
	}
	assertFileContent(t, filepath.Join(dst, "a.txt"), "a much longer replacement content")
}

// TestPushWithGoCopier_NeverDeletesExtraFilesAtDestination is the
// non-destructive contract (package doc): a file that exists at dst but
// NOT at src must survive untouched — this step is a one-way ADD/UPDATE
// push, never a mirror-with-delete.
func TestPushWithGoCopier_NeverDeletesExtraFilesAtDestination(t *testing.T) {
	src := t.TempDir()
	dst := t.TempDir()
	writeFile(t, filepath.Join(dst, "preexisting.txt"), "do not touch me")
	writeFile(t, filepath.Join(src, "a.txt"), "hello")

	if _, err := pushWithGoCopier(context.Background(), src, dst, func(string, ...any) {}); err != nil {
		t.Fatalf("push: %v", err)
	}
	assertFileContent(t, filepath.Join(dst, "preexisting.txt"), "do not touch me")
}

func writeFile(t *testing.T, path, content string) {
	t.Helper()
	if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
		t.Fatalf("mkdir: %v", err)
	}
	if err := os.WriteFile(path, []byte(content), 0o644); err != nil {
		t.Fatalf("write: %v", err)
	}
}

func assertFileContent(t *testing.T, path, want string) {
	t.Helper()
	got, err := os.ReadFile(path)
	if err != nil {
		t.Fatalf("read %s: %v", path, err)
	}
	if string(got) != want {
		t.Fatalf("%s content = %q, want %q", path, got, want)
	}
}
