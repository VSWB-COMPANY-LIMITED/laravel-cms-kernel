package mirror

import (
	"strings"
	"testing"
)

// resolvedNames is a small test helper: id -> final local name, for
// order-independence assertions.
func resolvedNames(resolved []resolvedChild) map[string]string {
	out := make(map[string]string, len(resolved))
	for _, r := range resolved {
		out[r.id] = r.localName
	}
	return out
}

// TestResolveSiblingNames_CaseInsensitiveCollision reproduces C1: two Drive
// files whose names differ only by case are DISTINCT to Drive but the SAME
// file on a case-insensitive filesystem (NTFS/APFS default) — must not both
// resolve to the same local name.
func TestResolveSiblingNames_CaseInsensitiveCollision(t *testing.T) {
	children := []rawChild{
		{id: "idBBBBBBBBBBBBBBBBBBBB", name: "Report.pdf"},
		{id: "idAAAAAAAAAAAAAAAAAAAA", name: "report.pdf"},
	}
	resolved := resolveSiblingNames(children)

	names := map[string]bool{}
	collidedCount := 0
	for _, r := range resolved {
		key := strings.ToLower(r.localName)
		if names[key] {
			t.Fatalf("two siblings still resolved to the same case-insensitive name: %q", r.localName)
		}
		names[key] = true
		if r.collided {
			collidedCount++
		}
	}
	if collidedCount != 1 {
		t.Fatalf("expected exactly 1 collided entry, got %d", collidedCount)
	}
}

// TestResolveSiblingNames_FolderFileCollision reproduces C2: a folder and a
// file sharing a Drive-side name must be deduped as ONE namespace — a file
// task must never end up pointed at what should have been a directory.
func TestResolveSiblingNames_FolderFileCollision(t *testing.T) {
	children := []rawChild{
		{id: "folderIDAAAAAAAAAAAAA", name: "Docs", isFolder: true},
		{id: "fileIDBBBBBBBBBBBBBBB", name: "Docs", isFolder: false},
	}
	resolved := resolveSiblingNames(children)

	seen := map[string]bool{}
	for _, r := range resolved {
		key := strings.ToLower(r.localName)
		if seen[key] {
			t.Fatalf("folder and file still collided on local name %q", r.localName)
		}
		seen[key] = true
	}
}

// TestResolveSiblingNames_TwoFoldersSameName reproduces C3: two distinct
// Drive folders with the same name in the same parent must not be allowed to
// merge their contents by resolving to the same local directory.
func TestResolveSiblingNames_TwoFoldersSameName(t *testing.T) {
	children := []rawChild{
		{id: "folderIDCCCCCCCCCCCCC", name: "Invoices", isFolder: true},
		{id: "folderIDDDDDDDDDDDDDD", name: "Invoices", isFolder: true},
	}
	resolved := resolveSiblingNames(children)

	if resolved[0].localName == resolved[1].localName {
		t.Fatalf("two folders with the same Drive name both resolved to %q", resolved[0].localName)
	}
}

// TestResolveSiblingNames_DeterministicRegardlessOfListingOrder reproduces
// C4: Drive does not guarantee stable files.list ordering between runs. If
// dedup decided "who wins the bare name" by arrival order, the loser would
// flip every time Drive reordered its response — and since this tool never
// deletes, each flip left a new orphaned duplicate behind. Resolution must
// depend ONLY on the (stable) Drive file ID, never on listing order.
func TestResolveSiblingNames_DeterministicRegardlessOfListingOrder(t *testing.T) {
	a := rawChild{id: "idAAAAAAAAAAAAAAAAAAAA", name: "budget.xlsx"}
	b := rawChild{id: "idBBBBBBBBBBBBBBBBBBBB", name: "budget.xlsx"}

	order1 := resolvedNames(resolveSiblingNames([]rawChild{a, b}))
	order2 := resolvedNames(resolveSiblingNames([]rawChild{b, a}))

	if len(order1) != 2 || len(order2) != 2 {
		t.Fatalf("expected 2 resolved entries each, got %d and %d", len(order1), len(order2))
	}
	for id, name := range order1 {
		if order2[id] != name {
			t.Fatalf("resolution for id=%s depends on listing order: got %q vs %q", id, name, order2[id])
		}
	}
}

func TestResolveSiblingNames_ExportExtensionAppended(t *testing.T) {
	children := []rawChild{
		{id: "docIDxxxxxxxxxxxxxxxxx", name: "My Doc", mimeType: "application/vnd.google-apps.document"},
	}
	resolved := resolveSiblingNames(children)
	if resolved[0].localName != "My Doc.docx" {
		t.Fatalf("localName = %q, want %q", resolved[0].localName, "My Doc.docx")
	}
}

func TestResolveSiblingNames_TruncatesLongExportName(t *testing.T) {
	longName := strings.Repeat("a", 300)
	children := []rawChild{
		{id: "docIDyyyyyyyyyyyyyyyyy", name: longName, mimeType: "application/vnd.google-apps.document"},
	}
	resolved := resolveSiblingNames(children)
	if len(resolved[0].localName) > 255 {
		t.Fatalf("localName length = %d, want <= 255", len(resolved[0].localName))
	}
	if !strings.HasSuffix(resolved[0].localName, ".docx") {
		t.Fatalf("localName = %q, expected .docx suffix preserved", resolved[0].localName)
	}
}

func TestResolveSiblingNames_NoCollisionKeepsOriginalName(t *testing.T) {
	children := []rawChild{
		{id: "idOne0000000000000000", name: "alpha.pdf"},
		{id: "idTwo0000000000000000", name: "beta.pdf"},
	}
	resolved := resolveSiblingNames(children)
	got := resolvedNames(resolved)
	if got["idOne0000000000000000"] != "alpha.pdf" || got["idTwo0000000000000000"] != "beta.pdf" {
		t.Fatalf("unexpected renaming with no collision: %+v", got)
	}
	for _, r := range resolved {
		if r.collided {
			t.Fatalf("collided=true for a non-colliding item: %+v", r)
		}
	}
}

// TestResolveSiblingNames_ThreeWayCollisionAllDistinct guards against the
// disambiguation loop only handling exactly 2 colliding siblings — 3 Drive
// items with the same case-folded name in one folder must still all end up
// with distinct local names.
func TestResolveSiblingNames_ThreeWayCollisionAllDistinct(t *testing.T) {
	children := []rawChild{
		{id: "id1AAAAAAAAAAAAAAAAAA", name: "same.pdf"},
		{id: "id2AAAAAAAAAAAAAAAAAA", name: "SAME.pdf"},
		{id: "id3AAAAAAAAAAAAAAAAAA", name: "Same.pdf"},
	}
	resolved := resolveSiblingNames(children)
	seen := map[string]bool{}
	for _, r := range resolved {
		key := strings.ToLower(r.localName)
		if seen[key] {
			t.Fatalf("3-way collision left a duplicate local name: %q", r.localName)
		}
		seen[key] = true
	}
}

// A Drive ID shorter than the 8-char suffix window used to skip the ID branch
// of disambiguate() entirely and fall through to the numeric-counter branch,
// which additionally appended the counter AFTER the extension — yielding
// "bao cao_BBBB222.pdf-2", a file with no usable extension left. Caught while
// reviewing the PHP port (2026-07-19); both ports shared the defect.
func TestResolveSiblingNames_ShortIDKeepsExtension(t *testing.T) {
	resolved := resolveSiblingNames([]rawChild{
		{id: "AAAA111", name: "bao cao.pdf", mimeType: "application/pdf"},
		{id: "BBBB222", name: "Bao Cao.pdf", mimeType: "application/pdf"},
	})

	for _, r := range resolved {
		if !strings.HasSuffix(r.localName, ".pdf") {
			t.Errorf("id=%s lost its extension: %q", r.id, r.localName)
		}
		if strings.Contains(r.localName, "-2") {
			t.Errorf("id=%s fell through to the counter branch unnecessarily: %q", r.id, r.localName)
		}
	}
	if resolved[0].localName == resolved[1].localName {
		t.Fatalf("names not deduped: %q", resolved[0].localName)
	}
}

// Tên THẬT lấy từ lần chạy 4843 item ngày 2026-07-19: thư mục
// "WEB19.0607 Ms Giau - Coding _ GHN - Facebook App" bị trùng tên, và hậu tố
// chống trùng chèn vào GIỮA tên ("WEB19_1e1Z27tn.0607 Ms Giau - ...") vì
// filepath.Ext coi cả phần đuôi sau "WEB19" là phần mở rộng. Thư mục KHÔNG có
// phần mở rộng — hậu tố phải nằm ở CUỐI.
func TestResolveSiblingNames_FolderWithDotKeepsSuffixAtEnd(t *testing.T) {
	const dirName = "WEB19.0607 Ms Giau - Coding _ GHN - Facebook App"
	resolved := resolveSiblingNames([]rawChild{
		{id: "1aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa", name: dirName, mimeType: FolderMimeType, isFolder: true},
		{id: "1e1Z27tnkodv7hsALrTCHxSp-ACdmdbfB", name: dirName, mimeType: FolderMimeType, isFolder: true},
	})

	for _, r := range resolved {
		if !strings.HasPrefix(r.localName, "WEB19.0607 Ms Giau") {
			t.Errorf("tên thư mục bị cắt xén ở giữa: %q", r.localName)
		}
	}
	if resolved[0].localName == resolved[1].localName {
		t.Fatalf("hai thư mục trùng tên chưa được khử: %q", resolved[0].localName)
	}
	t.Logf("giữ tên gốc : %q", resolved[0].localName)
	t.Logf("đổi tên     : %q", resolved[1].localName)
}

// File thật thì vẫn phải giữ đúng phần mở rộng như cũ.
func TestResolveSiblingNames_FileKeepsRealExtension(t *testing.T) {
	resolved := resolveSiblingNames([]rawChild{
		{id: "1aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa", name: "Câu Hỏi.docx", mimeType: "application/vnd.openxmlformats-officedocument.wordprocessingml.document"},
		{id: "1ht4YwWKsoIhbWktmDWqKtyTjxMNLyUDU", name: "Câu Hỏi.docx", mimeType: "application/vnd.openxmlformats-officedocument.wordprocessingml.document"},
	})
	for _, r := range resolved {
		if !strings.HasSuffix(r.localName, ".docx") {
			t.Errorf("file mất phần mở rộng: %q", r.localName)
		}
	}
}
