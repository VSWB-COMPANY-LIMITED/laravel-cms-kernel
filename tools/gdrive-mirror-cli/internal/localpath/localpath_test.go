package localpath

import (
	"strings"
	"testing"
	"unicode/utf8"
)

func TestSanitizeComponent_ForbiddenChars(t *testing.T) {
	got := SanitizeComponent(`a<b>c:d"e|f?g*h\i/j`)
	want := "a_b_c_d_e_f_g_h_i_j"
	if got != want {
		t.Fatalf("SanitizeComponent = %q, want %q", got, want)
	}
}

func TestSanitizeComponent_ControlChars(t *testing.T) {
	got := SanitizeComponent("a\x00b\x1fc")
	if got != "a_b_c" {
		t.Fatalf("SanitizeComponent = %q, want %q", got, "a_b_c")
	}
}

func TestSanitizeComponent_TrailingDotsAndSpaces(t *testing.T) {
	cases := map[string]string{
		"report.":   "report",
		"report ":   "report",
		"report. .": "report",
		"report...": "report",
		"a.b.":      "a.b",
	}
	for in, want := range cases {
		if got := SanitizeComponent(in); got != want {
			t.Errorf("SanitizeComponent(%q) = %q, want %q", in, got, want)
		}
	}
}

// TestSanitizeComponent_LeadingSpace reproduces a real Drive item found
// 2026-10-26 (" SOL24.0619 [in] PNJP E-Contract Management - Technical
// Proposal.pptx") whose name begins with a literal space. Plain NTFS
// happily stores that name, but SharePoint/OneDrive — reached via
// --push-to — refuses to sync it ("Rename 1 item?... doesn't begin or end
// with a space"), surfacing as a permanent, unresolvable "Sync issues" entry
// until a human renames it by hand on the OneDrive side.
func TestSanitizeComponent_LeadingSpace(t *testing.T) {
	cases := map[string]string{
		" report.pdf":   "report.pdf",
		"  report.pdf":  "report.pdf",
		" a b.pdf":      "a b.pdf", // only the LEADING space is trimmed, not an internal one
		" report. .pdf": "report. .pdf",
	}
	for in, want := range cases {
		if got := SanitizeComponent(in); got != want {
			t.Errorf("SanitizeComponent(%q) = %q, want %q", in, got, want)
		}
	}
}

// TestSanitizeComponent_LeadingSingleDotIsHiddenFileConventionNotTrimmed
// guards the deliberate asymmetry: a SINGLE leading dot (".env",
// ".gitignore"…) is an ordinary hidden-file name, not a SharePoint
// violation (SharePoint only refuses a name starting with TWO periods) —
// unlike the leading-space case above, it must survive untouched.
func TestSanitizeComponent_LeadingSingleDotIsHiddenFileConventionNotTrimmed(t *testing.T) {
	cases := []string{".env", ".gitignore", ".DS_Store"}
	for _, in := range cases {
		if got := SanitizeComponent(in); got != in {
			t.Errorf("SanitizeComponent(%q) = %q, want unchanged (hidden-file convention)", in, got)
		}
	}
}

func TestSanitizeComponent_EmptyName(t *testing.T) {
	if got := SanitizeComponent(""); got != "_" {
		t.Fatalf("SanitizeComponent(\"\") = %q, want \"_\"", got)
	}
	// Each forbidden char is replaced individually (not stripped), so "///"
	// becomes "___" — non-empty, and never falls back to the empty-name rule.
	if got := SanitizeComponent("///"); got != "___" {
		t.Fatalf("SanitizeComponent(\"///\") = %q, want \"___\"", got)
	}
	// A name made ENTIRELY of trailing dots/spaces (nothing left after trim)
	// is what actually exercises the empty-name fallback.
	if got := SanitizeComponent("..."); got != "_" {
		t.Fatalf("SanitizeComponent(\"...\") = %q, want \"_\"", got)
	}
}

func TestSanitizeComponent_DotAndDotDotNeverSurvive(t *testing.T) {
	for _, in := range []string{".", ".."} {
		got := SanitizeComponent(in)
		if got == "." || got == ".." || got == "" {
			t.Errorf("SanitizeComponent(%q) = %q — must never be \".\", \"..\", or \"\"", in, got)
		}
	}
}

func TestSanitizeComponent_ReservedDeviceNames(t *testing.T) {
	cases := map[string]string{
		"CON":         "CON_",
		"con":         "con_",
		"PRN":         "PRN_",
		"AUX":         "AUX_",
		"NUL":         "NUL_",
		"COM1":        "COM1_",
		"com9":        "com9_",
		"LPT1":        "LPT1_",
		"lpt9":        "lpt9_",
		"CON.txt":     "CON_.txt",
		"con.TXT":     "con_.TXT",
		"COM1.tar.gz": "COM1_.tar.gz",
	}
	for in, want := range cases {
		if got := SanitizeComponent(in); got != want {
			t.Errorf("SanitizeComponent(%q) = %q, want %q", in, got, want)
		}
	}
}

func TestSanitizeComponent_ReservedNameIsNotAPrefixMatch(t *testing.T) {
	// "CONSOLE" must NOT be treated as reserved just because it starts with CON.
	if got := SanitizeComponent("CONSOLE.txt"); got != "CONSOLE.txt" {
		t.Fatalf("SanitizeComponent(CONSOLE.txt) = %q, want unchanged", got)
	}
}

func TestTruncateComponent_ShortNameUnchanged(t *testing.T) {
	if got := TruncateComponent("short.txt", 0); got != "short.txt" {
		t.Fatalf("got %q, want unchanged", got)
	}
}

func TestTruncateComponent_LimitsTo255Bytes(t *testing.T) {
	long := strings.Repeat("a", 400) + ".txt"
	got := TruncateComponent(long, 0)
	if len(got) > 255 {
		t.Fatalf("truncated length = %d, want <= 255", len(got))
	}
	if !strings.HasSuffix(got, ".txt") {
		t.Fatalf("extension not preserved: %q", got)
	}
}

func TestTruncateComponent_Reserve(t *testing.T) {
	long := strings.Repeat("a", 400)
	got := TruncateComponent(long, 20)
	if len(got) > 255-20 {
		t.Fatalf("truncated length = %d, want <= %d (reserve=20)", len(got), 255-20)
	}
}

func TestTruncateComponent_RuneBoundaryVietnamese(t *testing.T) {
	// Vietnamese diacritics are multi-byte UTF-8; a byte-blind cut would slice
	// one in half and produce invalid UTF-8.
	word := "Đơn xin nghỉ phép có dấu tiếng Việt đầy đủ dài dòng văn tự "
	long := strings.Repeat(word, 10) + ".docx"
	got := TruncateComponent(long, 0)
	if len(got) > 255 {
		t.Fatalf("truncated length = %d, want <= 255", len(got))
	}
	if !utf8.ValidString(got) {
		t.Fatalf("truncated result is not valid UTF-8: %q", got)
	}
}

func TestTruncateComponent_DifferentLongNamesStayDistinct(t *testing.T) {
	a := strings.Repeat("x", 400) + "-first-file.pdf"
	b := strings.Repeat("x", 400) + "-second-file.pdf"
	gotA := TruncateComponent(a, 0)
	gotB := TruncateComponent(b, 0)
	if gotA == gotB {
		t.Fatalf("two different long names truncated to the same result: %q", gotA)
	}
}

func TestTruncateComponent_SameNameIsDeterministic(t *testing.T) {
	long := strings.Repeat("y", 400) + ".pdf"
	if TruncateComponent(long, 0) != TruncateComponent(long, 0) {
		t.Fatalf("TruncateComponent is not deterministic for the same input")
	}
}

func TestSafeJoin_NormalPathStaysInside(t *testing.T) {
	root := t.TempDir()
	got, err := SafeJoin(root, "sub/dir/file.txt")
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if !strings.HasPrefix(got, root) {
		t.Fatalf("result %q not inside root %q", got, root)
	}
}

func TestSafeJoin_TraversalBlocked(t *testing.T) {
	root := t.TempDir()
	cases := []string{
		"../escape.txt",
		"a/../../escape.txt",
		"../../../../etc/passwd",
	}
	for _, rel := range cases {
		if _, err := SafeJoin(root, rel); err == nil {
			t.Errorf("SafeJoin(%q, %q) did not error, expected traversal to be blocked", root, rel)
		}
	}
}

func TestSafeJoin_RootItselfIsAllowed(t *testing.T) {
	root := t.TempDir()
	got, err := SafeJoin(root, ".")
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if got == "" {
		t.Fatalf("expected a resolved path for root itself")
	}
}

// A name whose LAST dot sits early followed by a long tail (common in VN
// naming: "Bao cao Q1.2026 - <long text>") made filepath.Ext return the whole
// tail as the "extension"; preserving it verbatim pushed the result back over
// the 255-byte cap. Caught in review of the C1-C5/L1-L3 fix — a 311-byte name
// came out at 308 bytes.
func TestTruncateComponent_LongFakeExtensionStillCapped(t *testing.T) {
	name := "Bao cao Q1." + strings.Repeat("x", 300)
	got := TruncateComponent(name, 0)
	if len(got) > 255 {
		t.Fatalf("result %d bytes exceeds the 255-byte component cap: %q", len(got), got)
	}
	if !utf8.ValidString(got) {
		t.Fatalf("result is not valid UTF-8: %q", got)
	}
}

// A genuine extension must still survive truncation — the fix above must not
// have thrown the baby out with the bathwater.
func TestTruncateComponent_RealExtensionPreserved(t *testing.T) {
	name := strings.Repeat("t", 300) + ".docx"
	got := TruncateComponent(name, 0)
	if len(got) > 255 {
		t.Fatalf("result %d bytes exceeds cap", len(got))
	}
	if !strings.HasSuffix(got, ".docx") {
		t.Fatalf("real extension was dropped: %q", got)
	}
}

// The byte cap is the invariant callers depend on: it must hold even when
// `reserve` is absurd enough to leave no room for the collision hash.
func TestTruncateComponent_CapHoldsUnderExtremeReserve(t *testing.T) {
	for _, reserve := range []int{0, 200, 250, 254, 255, 300} {
		name := strings.Repeat("ạ", 200) + ".pdf"
		got := TruncateComponent(name, reserve)
		limit := 255 - reserve
		if limit < 0 {
			limit = 0
		}
		if len(got) > limit {
			t.Errorf("reserve=%d: result %d bytes exceeds limit %d", reserve, len(got), limit)
		}
		if !utf8.ValidString(got) {
			t.Errorf("reserve=%d: result is not valid UTF-8: %q", reserve, got)
		}
	}
}

// CollisionKey folds case AND Unicode normalisation (NFC/NFD), but must never be
// confused with SanitizeComponent: it is only a comparison key.
func TestCollisionKey_FoldsCaseAndNormalization(t *testing.T) {
	nfc := "Thaco tối ưu điểm.xlsx"
	nfd := "Thaco tối ưu điểm.xlsx"
	if nfc == nfd {
		t.Fatal("test setup: NFC and NFD inputs must differ byte-wise")
	}
	if CollisionKey(nfc) != CollisionKey(nfd) {
		t.Fatalf("NFC and NFD forms must share a key: %+q vs %+q", CollisionKey(nfc), CollisionKey(nfd))
	}
	if CollisionKey("Report.PDF") != CollisionKey("report.pdf") {
		t.Fatal("case-only variants must share a key")
	}
	if CollisionKey("a.pdf") == CollisionKey("b.pdf") {
		t.Fatal("different names must not share a key")
	}
}

// SanitizeComponent must NOT rewrite Unicode form: a lone NFD name stays NFD.
func TestSanitizeComponent_LeavesUnicodeFormAlone(t *testing.T) {
	nfd := "tối.xlsx"
	if got := SanitizeComponent(nfd); got != nfd {
		t.Fatalf("SanitizeComponent rewrote an NFD name: got %+q want %+q", got, nfd)
	}
}
