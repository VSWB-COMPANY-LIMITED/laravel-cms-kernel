// push.go implements --push-to: after a normal --path mirror run finishes,
// push whatever changed into a SECOND destination in one bounded, one-shot
// batch — typically a live OneDrive/Google Drive/Dropbox-synced folder.
//
// Why this exists (as a SEPARATE step from the Drive download machinery,
// not --path itself): a folder a cloud-sync DESKTOP CLIENT is actively
// watching reacts badly to a long-lived process rewriting files inside it
// unpredictably over time — see cloudsync package doc for the real incident
// (OneDrive spawning "name-2.ext", "name-3.ext"... conflict copies).
// PushToDestination sidesteps that specific failure mode two ways at once:
//   - it is a BOUNDED batch that starts and finishes once per run, not a
//     background watcher's worth of scattered writes spread across a long
//     `--concurrency`-parallel download session;
//   - it only ever touches a file when its size/mtime at the destination
//     actually differs — the same "don't touch what's already right"
//     contract the Drive-download delta-check already has, applied a
//     second time at the OneDrive-facing boundary.
//
// This does NOT make writing into a live-synced folder risk-free (nothing
// external to that sync client's own protocol can fully guarantee that —
// see README "KHÔNG mirror thẳng vào thư mục cloud-sync sống" for the
// honest limit), but it removes every mechanism this tool's own behavior
// was contributing to the problem.
//
// One-way, non-destructive, same as the rest of this tool: NEVER deletes
// anything already at dst that src doesn't have (no /MIR, no /PURGE) — a
// second destination folder may hold other content this tool has no
// business touching.
package mirror

import (
	"context"
	"fmt"
	"io"
	"io/fs"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"strings"
	"time"
)

// PushResult summarizes one PushToDestination call.
type PushResult struct {
	Method  string // "robocopy" | "copy"
	Copied  int    // files actually written (new or changed) — only set by the Go copier; robocopy's own count lives in its logged output
	Skipped int    // files left untouched because dst already matched — Go copier only
	Errors  int
}

// PushToDestination copies everything under src into dst, one-way,
// non-destructively, touching only files that are new or actually
// different. On Windows it shells out to `robocopy` (no /MIR — see package
// doc) since it's the platform's own well-tested tool for exactly this and
// already this project's documented recommendation; everywhere else (no
// robocopy binary) it falls back to a small portable Go copier with the
// identical "only touch what's different" contract.
func PushToDestination(ctx context.Context, src, dst string, logf func(format string, args ...any)) (PushResult, error) {
	if err := os.MkdirAll(dst, 0o755); err != nil {
		return PushResult{}, fmt.Errorf("push: không tạo được đích %s: %w", dst, err)
	}

	if runtime.GOOS == "windows" {
		return pushWithRobocopy(ctx, src, dst, logf)
	}
	return pushWithGoCopier(ctx, src, dst, logf)
}

// pushWithRobocopy shells out to `robocopy src dst /E /MT:8 /R:2 /W:2`.
// /E copies subdirectories including empty ones; /MT:8 copies up to 8 files
// concurrently (robocopy's own equivalent of --concurrency, keeps this fast
// on a large tree); /R:2 /W:2 keeps its built-in per-file retry short (this
// tool's own --retry already handled the flaky-network case during
// download — robocopy here is pushing already-local files, retries are
// only for transient local I/O hiccups). Deliberately NO /MIR and NO
// /PURGE — see package doc: this step must never delete anything at dst.
func pushWithRobocopy(ctx context.Context, src, dst string, logf func(string, ...any)) (PushResult, error) {
	cmd := exec.CommandContext(ctx, "robocopy", src, dst, "/E", "/MT:8", "/R:2", "/W:2")
	var out strings.Builder
	cmd.Stdout = &out
	cmd.Stderr = &out

	runErr := cmd.Run()
	exitCode := 0
	if exitErr, ok := runErr.(*exec.ExitError); ok {
		exitCode = exitErr.ExitCode()
	} else if runErr != nil {
		return PushResult{Method: "robocopy"}, fmt.Errorf("push: không chạy được robocopy (%w) — có nằm trong PATH không? output:\n%s", runErr, out.String())
	}

	logf("Push (robocopy %s -> %s) xong, exit=%d:\n%s", src, dst, exitCode, out.String())

	if err := interpretRobocopyExitCode(exitCode); err != nil {
		return PushResult{Method: "robocopy"}, err
	}
	return PushResult{Method: "robocopy"}, nil
}

// interpretRobocopyExitCode translates robocopy's bitmask exit code per its
// own documented contract (`robocopy /?`): bits 0-2 (values 1/2/4, any
// combination summing to 0-7) all describe DEGREES OF SUCCESS (files
// copied / extra files present / mismatches noted) — none of those are a
// failure. Bit 3 (value 8, "some files/dirs could not be copied") and bit 4
// (value 16, "serious error, no files copied") are the only failure
// signals; any code >= 8 means at least one of those is set. Pure — no I/O
// — so this is unit-tested against the documented code table directly.
func interpretRobocopyExitCode(code int) error {
	if code >= 8 {
		return fmt.Errorf("robocopy: exit code %d (>=8 nghĩa là có lỗi — chạy lại với output ở trên, hoặc xem `robocopy /?`)", code)
	}
	return nil
}

// pushWithGoCopier is the no-robocopy fallback (macOS/Linux): walks src,
// and for every file whose destination is missing or differs in size/mtime
// (mtimeEqualEnough tolerates the ~2s granularity some filesystems round
// to — FAT32-formatted external/network destinations in particular),
// copies it via write-to-temp-then-rename in the SAME destination
// directory (same atomicity contract as download.go's downloadItem — a
// crash mid-copy never leaves a truncated file at the real path).
func pushWithGoCopier(ctx context.Context, src, dst string, logf func(string, ...any)) (PushResult, error) {
	var result PushResult

	walkErr := filepath.WalkDir(src, func(p string, d fs.DirEntry, err error) error {
		if err != nil {
			result.Errors++
			logf("Push: lỗi đọc %s: %v", p, err)
			return nil
		}
		if ctx.Err() != nil {
			return ctx.Err()
		}

		rel, rerr := filepath.Rel(src, p)
		if rerr != nil || rel == "." {
			return nil
		}
		destPath := filepath.Join(dst, rel)

		if d.IsDir() {
			return os.MkdirAll(destPath, 0o755)
		}

		srcInfo, ierr := d.Info()
		if ierr != nil {
			result.Errors++
			logf("Push: không đọc được info %s: %v", rel, ierr)
			return nil
		}

		if dstInfo, derr := os.Stat(destPath); derr == nil && dstInfo.Size() == srcInfo.Size() && mtimeEqualEnough(dstInfo.ModTime(), srcInfo.ModTime()) {
			result.Skipped++
			return nil
		}

		if cerr := copyFileAtomic(p, destPath, srcInfo.ModTime()); cerr != nil {
			result.Errors++
			logf("Push: lỗi copy %s: %v", rel, cerr)
			return nil
		}
		result.Copied++
		return nil
	})

	result.Method = "copy"
	if walkErr != nil {
		return result, fmt.Errorf("push: %w", walkErr)
	}
	logf("Push (copy %s -> %s) xong: copied=%d skipped=%d errors=%d", src, dst, result.Copied, result.Skipped, result.Errors)
	if result.Errors > 0 {
		return result, fmt.Errorf("push: %d file lỗi khi copy", result.Errors)
	}
	return result, nil
}

// mtimeEqualEnough tolerates up to 2 seconds of drift — FAT32 (common on
// external/USB destinations) only stores mtime at 2-second resolution, so
// an exact Equal() comparison would force a needless re-copy of every file
// on every run.
func mtimeEqualEnough(a, b time.Time) bool {
	d := a.Sub(b)
	if d < 0 {
		d = -d
	}
	return d <= 2*time.Second
}

// copyFileAtomic copies src to a temp file in destPath's own directory,
// then renames it into place — a crash/interrupt mid-copy never leaves a
// truncated file at destPath (same contract as download.go's downloadItem).
func copyFileAtomic(src, destPath string, modTime time.Time) error {
	if err := os.MkdirAll(filepath.Dir(destPath), 0o755); err != nil {
		return err
	}

	in, err := os.Open(src)
	if err != nil {
		return err
	}
	defer in.Close()

	tmp, err := os.CreateTemp(filepath.Dir(destPath), ".push-*.tmp")
	if err != nil {
		return err
	}
	tmpPath := tmp.Name()

	_, copyErr := io.Copy(tmp, in)
	closeErr := tmp.Close()
	if copyErr != nil {
		os.Remove(tmpPath)
		return copyErr
	}
	if closeErr != nil {
		os.Remove(tmpPath)
		return closeErr
	}

	if err := os.Rename(tmpPath, destPath); err != nil {
		os.Remove(tmpPath)
		return err
	}

	if !modTime.IsZero() {
		_ = os.Chtimes(destPath, modTime, modTime)
	}
	return nil
}
