package mirror

import (
	"crypto/md5"
	"encoding/hex"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func md5HexOf(content []byte) string {
	sum := md5.Sum(content)
	return hex.EncodeToString(sum[:])
}

// TestRenderDuplicateScanReport_EmptyResultSaysSoClearly: no findings must
// read as "all clear", not as a blank/ambiguous report.
func TestRenderDuplicateScanReport_EmptyResultSaysSoClearly(t *testing.T) {
	out := RenderDuplicateScanReport(DuplicateScanResult{}, "/mirror/root")
	if !containsAll(out, "Không có file local nào thừa") {
		t.Fatalf("expected an explicit all-clear message, got: %s", out)
	}
}

// TestRenderDuplicateScanReport_ConfirmedNeverAutoClaimsSuspected: the two
// categories must stay visually distinct — a confirmed MD5 match must not
// be reported with the "cần tự soát" hedge language meant for unconfirmed
// cases, and vice versa.
func TestRenderDuplicateScanReport_ConfirmedNeverAutoClaimsSuspected(t *testing.T) {
	result := DuplicateScanResult{
		Confirmed: []DuplicateMatch{{OrphanAbs: "/mirror/root/Old.pdf", MatchesID: "id1", MatchesPath: "New.pdf"}},
	}
	out := RenderDuplicateScanReport(result, "/mirror/root")
	if !containsAll(out, "XÁC NHẬN TRÙNG", "Old.pdf", "New.pdf", "id1") {
		t.Fatalf("missing expected confirmed-match content: %s", out)
	}
	if containsAll(out, "NGHI NGỜ") {
		t.Fatalf("a purely-confirmed result must not also print the suspected-only section header: %s", out)
	}
}

func containsAll(s string, subs ...string) bool {
	for _, sub := range subs {
		if !strings.Contains(s, sub) {
			return false
		}
	}
	return true
}

// TestMatchByContent_ConfirmsExactContentMatchAgainstCurrentItem reproduces
// the clean case ScanDuplicates relies on: a local file left over at an OLD
// path exactly matches (same MD5) a regular file Drive currently lists at a
// NEW path — this must resolve to a match (ScanDuplicates then reports it
// under Confirmed, never Suspected — plenty of proof available here).
func TestMatchByContent_ConfirmsExactContentMatchAgainstCurrentItem(t *testing.T) {
	dir := t.TempDir()
	content := []byte("same bytes on both sides")
	oldAbs := filepath.Join(dir, "Old.pdf")
	if err := os.WriteFile(oldAbs, content, 0o644); err != nil {
		t.Fatalf("setup: %v", err)
	}

	current := Item{Type: "file", Path: "New.pdf", ID: "id1", MimeType: "application/pdf", MD5Checksum: md5HexOf(content)}
	md5Index := map[string][]Item{current.MD5Checksum: {current}}

	matches, ok := matchByContent(oldAbs, md5Index)
	if !ok {
		t.Fatalf("expected a content match for %s", oldAbs)
	}
	if len(matches) != 1 || matches[0].ID != "id1" {
		t.Fatalf("unexpected matches: %+v", matches)
	}
}

// TestMatchByContent_NoMatchForUnrelatedContent: a local file whose content
// doesn't match ANY current item's MD5 must not be force-matched to
// something unrelated just because one happens to exist.
func TestMatchByContent_NoMatchForUnrelatedContent(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "mystery.bin")
	if err := os.WriteFile(path, []byte("totally unrelated bytes"), 0o644); err != nil {
		t.Fatalf("setup: %v", err)
	}
	md5Index := map[string][]Item{
		md5HexOf([]byte("something else entirely")): {{ID: "id1", Path: "New.pdf"}},
	}

	if _, ok := matchByContent(path, md5Index); ok {
		t.Fatalf("expected no match for unrelated content")
	}
}

// TestSameFolderSameExtHint_PointsAtPlausibleSiblingButNeverClaimsProof
// covers the Google-native case this feature exists for: no MD5 is
// available (native files have none from Drive), so the best this can do
// is point at a same-folder, same-extension current item — WITHOUT ever
// using confirmed-match language.
func TestSameFolderSameExtHint_PointsAtPlausibleSiblingButNeverClaimsProof(t *testing.T) {
	orphan := filepath.Join("/mirror/root", "MyFolder", "OldName.docx")
	byDir := map[string][]Item{
		filepath.Join("/mirror/root", "MyFolder"): {
			{ID: "docID1", Path: "MyFolder/NewName.docx"},
		},
	}

	hint := sameFolderSameExtHint(orphan, byDir)
	if !containsAll(hint, "docID1", "NewName.docx", "không có MD5") {
		t.Fatalf("hint missing expected content: %q", hint)
	}
}

// TestSameFolderSameExtHint_EmptyWhenNoPlausibleSibling: no same-folder,
// same-extension current item exists — must return "" rather than guessing.
func TestSameFolderSameExtHint_EmptyWhenNoPlausibleSibling(t *testing.T) {
	orphan := filepath.Join("/mirror/root", "MyFolder", "Orphan.docx")
	byDir := map[string][]Item{
		filepath.Join("/mirror/root", "MyFolder"): {
			{ID: "id1", Path: "MyFolder/Report.pdf"}, // different extension
		},
	}

	if hint := sameFolderSameExtHint(orphan, byDir); hint != "" {
		t.Fatalf("expected no hint, got %q", hint)
	}
}

func TestIsScratchTempFile(t *testing.T) {
	cases := map[string]bool{
		".a1b2c3d4-abcd1234.tmp": true,
		"Report.docx":            false,
		".hidden-but-not-tmp":    false,
		"notatmp.tmp":            false, // must also start with "."
	}
	for name, want := range cases {
		if got := isScratchTempFile(name); got != want {
			t.Errorf("isScratchTempFile(%q) = %v, want %v", name, got, want)
		}
	}
}
