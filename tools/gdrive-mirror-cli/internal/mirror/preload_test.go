package mirror

import (
	"os"
	"strings"
	"testing"

	"github.com/vswb/gdrive-mirror/internal/report"
)

func strPtr(s string) *string { return &s }

func TestItemFromFailed_DerefsNullableFields(t *testing.T) {
	fi := report.FailedItem{
		Type: "file", Path: "docs/notes.txt",
		ID: strPtr("abc123"), MimeType: strPtr("text/plain"), MD5Checksum: strPtr("deadbeef"),
		Timestamp: 1700000000, Size: 42,
	}

	got := ItemFromFailed(fi)
	if got.Type != "file" || got.Path != "docs/notes.txt" || got.ID != "abc123" ||
		got.MimeType != "text/plain" || got.MD5Checksum != "deadbeef" ||
		got.ModifiedTime != 1700000000 || got.Size != 42 {
		t.Fatalf("unexpected conversion: %+v", got)
	}
}

func TestItemFromFailed_NilPointersBecomeEmptyStrings(t *testing.T) {
	fi := report.FailedItem{Type: "dir", Path: "some/dir"}

	got := ItemFromFailed(fi)
	if got.ID != "" || got.MimeType != "" || got.MD5Checksum != "" {
		t.Fatalf("expected nil pointer fields to deref to \"\", got %+v", got)
	}
}

func TestItemsFromFailed_SkipsEmptyPath(t *testing.T) {
	failed := []report.FailedItem{
		{Type: "file", Path: "a.txt", ID: strPtr("id1")},
		{Type: "file", Path: ""}, // malformed/hand-edited entry — must be dropped, not crash
		{Type: "file", Path: "b.txt", ID: strPtr("id2")},
	}

	items := ItemsFromFailed(failed)
	if len(items) != 2 {
		t.Fatalf("expected 2 items (empty-path entry dropped), got %d: %+v", len(items), items)
	}
	if items[0].Path != "a.txt" || items[1].Path != "b.txt" {
		t.Fatalf("unexpected items: %+v", items)
	}
}

func TestItemsFromFailed_EmptyInput(t *testing.T) {
	if got := ItemsFromFailed(nil); len(got) != 0 {
		t.Fatalf("expected 0 items for nil input, got %d", len(got))
	}
}

// TestItemsFromFailed_MaliciousPathStillRejectedBySafeJoin verifies the
// "vẫn phải chạy qua localpath.SafeJoin" requirement end-to-end: a
// hand-edited report entry with a traversal path must still be rejected by
// buildPlan exactly like a live listing would (ItemsFromFailed itself does
// no path-safety filtering — it only drops empty paths — so this must be
// enforced downstream).
func TestItemsFromFailed_MaliciousPathStillRejectedBySafeJoin(t *testing.T) {
	s := newTestSyncer(t)
	failed := []report.FailedItem{
		{Type: "file", Path: "../../../etc/evil.txt", ID: strPtr("evil1")},
	}

	items := ItemsFromFailed(failed)
	if len(items) != 1 {
		t.Fatalf("expected the traversal item to survive ItemsFromFailed (rejection happens in buildPlan), got %d items", len(items))
	}

	tasks := s.buildPlan(items, "")
	if len(tasks) != 0 {
		t.Fatalf("expected 0 tasks — traversal path must be rejected by SafeJoin, got %d", len(tasks))
	}
	if len(s.stats.FailedFiles) != 1 || !s.stats.FailedFiles[0].Permanent {
		t.Fatalf("expected 1 permanent FailedFiles entry, got %+v", s.stats.FailedFiles)
	}
	if !strings.Contains(s.stats.FailedFiles[0].Category, "Local path rejected") {
		t.Fatalf("expected category to note the path rejection, got %q", s.stats.FailedFiles[0].Category)
	}
}

// Chế độ --retry-failed phải dựng lại ĐÚNG đường dẫn mà lần chạy thường đã
// dùng. Bản đầu của RunRetry ép localPrefix="" nên mọi file retry rơi cao hơn
// một cấp thư mục — tải lại im lặng vào sai chỗ, sinh bản sao (bắt được khi
// review 2026-07-19).
func TestRetryTargetPathMatchesNormalRunTargetPath(t *testing.T) {
	root := t.TempDir()
	const prefix = "Tai lieu cong ty"
	item := Item{Type: "file", Path: "hop dong/bao cao.pdf", ID: "F1", Size: 10}

	normal := (&Syncer{cfg: Config{Path: root}, runID: "x"}).buildPlan([]Item{item}, prefix)
	retry := (&Syncer{cfg: Config{Path: root}, runID: "x"}).buildPlan([]Item{item}, prefix)
	wrong := (&Syncer{cfg: Config{Path: root}, runID: "x"}).buildPlan([]Item{item}, "")

	if len(normal) != 1 || len(retry) != 1 || len(wrong) != 1 {
		t.Fatalf("plan rỗng")
	}
	if normal[0].targetPath != retry[0].targetPath {
		t.Errorf("retry lệch đường dẫn:\n  thường: %s\n  retry : %s", normal[0].targetPath, retry[0].targetPath)
	}
	if wrong[0].targetPath == normal[0].targetPath {
		t.Fatalf("test vô nghĩa: prefix rỗng lẽ ra phải cho đường dẫn KHÁC")
	}
}

// local_prefix phải đi được trọn vòng qua report JSON, nếu không lần
// --retry-failed sau lại mất prefix.
func TestLocalPrefixRoundTripsThroughFailedReport(t *testing.T) {
	dir := t.TempDir()
	const prefix = "Tai lieu cong ty"
	items := []report.FailedItem{{Type: "file", Path: "a/b.pdf", Reason: "boom", Category: "Other"}}

	p, err := report.WriteFailedReportJSON(dir, items, "/base", prefix, []string{"folder1"}, "run1")
	if err != nil {
		t.Fatal(err)
	}
	raw, err := os.ReadFile(p)
	if err != nil {
		t.Fatal(err)
	}
	fr, err := report.ParseFailedReport(raw)
	if err != nil {
		t.Fatal(err)
	}
	if fr.LocalPrefix != prefix {
		t.Errorf("local_prefix không round-trip: muốn %q, nhận %q", prefix, fr.LocalPrefix)
	}
}
