// Package localpath makes a Drive item's name safe to use as a local
// filesystem path component on ANY of this tool's target OSes (macOS/Linux/
// Windows) — not just when actually running on Windows. Drive itself allows
// almost any UTF-8 string as a name (including "/", "..", trailing dots,
// reserved device names like CON, and bytes NTFS refuses outright); a mirror
// built on one machine has to stay openable if the disk is later plugged
// into a different OS. Pure functions only — no I/O — so every rule here is
// unit-tested without touching a real filesystem.
//
// Ported from the gdrive-mirror-cli name-collision/path-safety audit
// (2026-07-18, findings C1-C5/L1-L3).
package localpath

import (
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"path/filepath"
	"strings"
	"unicode/utf8"

	"golang.org/x/text/unicode/norm"
)

// forbiddenChars are the characters NTFS refuses in a path component. Drive
// permits all of them in a file/folder name (finding L2).
const forbiddenChars = `<>:"|?*\/`

// windowsReservedNames are the MS-DOS device names Windows refuses as a
// filename component regardless of extension — "CON.txt" is exactly as
// invalid as bare "CON" (finding L3).
var windowsReservedNames = map[string]bool{
	"CON": true, "PRN": true, "AUX": true, "NUL": true,
	"COM1": true, "COM2": true, "COM3": true, "COM4": true, "COM5": true,
	"COM6": true, "COM7": true, "COM8": true, "COM9": true,
	"LPT1": true, "LPT2": true, "LPT3": true, "LPT4": true, "LPT5": true,
	"LPT6": true, "LPT7": true, "LPT8": true, "LPT9": true,
}

// maxComponentBytes is NTFS's per-component limit. Go's os package on
// Windows auto-prefixes long paths with `\\?\`, which lifts the 260-char
// MAX_PATH ceiling on the FULL path — so nothing further is needed for total
// path length, only this per-component limit (finding L1's non-goal).
const maxComponentBytes = 255

// maxExtBytes is how long a trailing ".xxx" may be before TruncateComponent
// stops treating it as a real extension worth preserving. Chosen well above
// any genuine extension (".presentation" is 13) but far below the point
// where keeping it verbatim would eat the whole byte budget.
const maxExtBytes = 32

// SplitExt splits name into base + extension, but ONLY when the trailing
// segment actually looks like an extension. filepath.Ext returns everything
// after the LAST dot, which is wrong twice over for real Drive names:
//
//   - Truncation: "Bao cao Q1.2026 - tong ket <200 more chars>" has a
//     200-byte "extension"; preserving it verbatim pushed a 311-byte name to
//     308 bytes, over the 255 cap (caught in review 2026-07-19).
//   - Collision suffixing: the folder "WEB19.0607 Ms Giau - Coding _ GHN -
//     Facebook App" became "WEB19_1e1Z27tn.0607 Ms Giau - Coding _ GHN -
//     Facebook App" — the disambiguation suffix landed in the MIDDLE of the
//     name (caught on a real 4843-item run 2026-07-19).
//
// isDir short-circuits the whole question: a directory has no extension, so
// nothing after a dot in its name is ever special.
func SplitExt(name string, isDir bool) (base, ext string) {
	if isDir {
		return name, ""
	}
	ext = filepath.Ext(name)
	if len(ext) > maxExtBytes {
		return name, ""
	}
	return strings.TrimSuffix(name, ext), ext
}

// SanitizeComponent makes name safe as a SINGLE path component (no
// directory separators of its own). It:
//  1. Replaces control characters and any of `< > : " | ? * \ /` with `_`
//     (finding L2).
//  2. Trims trailing dots/spaces — Windows silently strips these, which
//     otherwise makes two Drive names collide invisibly ("foo" vs "foo.").
//  3. Trims a LEADING space — plain NTFS allows it (unlike the trailing
//     case above, this isn't a Windows limitation), but SharePoint/OneDrive
//     — this tool's real --push-to destination for most callers — refuses
//     to sync a name starting with a space at all ("Rename 1 item?... make
//     sure the name doesn't begin or end with a space"), surfacing as a
//     permanent "Sync issues" entry for the human running the mirror to
//     clean up by hand. Caught from a real Drive item 2026-10-26 ("
//     SOL24.0619 [in] PNJP E-Contract Management - Technical
//     Proposal.pptx"). Deliberately NOT trimming a leading single dot —
//     that's the ordinary hidden-file convention (".env", ".gitignore"…)
//     and perfectly fine with SharePoint, which only refuses a name
//     starting with TWO periods.
//  4. Falls back to "_" for a name that is empty after the above — this is
//     what makes "." and ".." (and an all-dots/all-forbidden-chars name)
//     land on a safe value instead of ever being returned literally as "."
//     or ".." (which the filesystem would read as self/parent-directory,
//     not a real name).
//  5. Appends "_" to a Windows-reserved device name (CON, PRN, COM1…),
//     checked on the segment before the first '.' so "CON.txt" is caught
//     too (finding L3).
func SanitizeComponent(name string) string {
	var b strings.Builder
	b.Grow(len(name))
	for _, r := range name {
		if r < 0x20 || strings.ContainsRune(forbiddenChars, r) {
			b.WriteByte('_')
			continue
		}
		b.WriteRune(r)
	}
	cleaned := strings.TrimRight(b.String(), ". ")
	cleaned = strings.TrimLeft(cleaned, " ")

	if cleaned == "" {
		return "_"
	}

	base := cleaned
	rest := ""
	if i := strings.IndexByte(cleaned, '.'); i >= 0 {
		base = cleaned[:i]
		rest = cleaned[i:]
	}
	if windowsReservedNames[strings.ToUpper(base)] {
		return base + "_" + rest
	}
	return cleaned
}

// TruncateComponent limits name to 255 UTF-8 bytes minus reserve (room the
// caller needs for something appended later). Truncation always lands on a
// rune boundary — cutting mid-rune would corrupt a multi-byte character
// (e.g. Vietnamese diacritics are 2-3 bytes each). When a real cut happens,
// "~" + the first 6 hex chars of sha256(name) is appended before the
// extension: two different long names that happen to share the same
// truncated prefix must NOT collapse onto the same local file (a truncate-
// only scheme silently would).
func TruncateComponent(name string, reserve int) string {
	return truncate(name, reserve, false)
}

// TruncateComponentDir is TruncateComponent for a DIRECTORY name — a
// directory has no extension, so none is carved out and preserved.
func TruncateComponentDir(name string, reserve int) string {
	return truncate(name, reserve, true)
}

func truncate(name string, reserve int, isDir bool) string {
	limit := maxComponentBytes - reserve
	if limit < 0 {
		limit = 0
	}
	if len(name) <= limit {
		return name
	}

	sum := sha256.Sum256([]byte(name))
	suffix := "~" + hex.EncodeToString(sum[:])[:6]

	base, ext := SplitExt(name, isDir)

	// If even suffix+ext cannot fit, drop the extension too and, failing
	// that, hard-cut the name — the byte cap is the invariant callers rely
	// on, so it wins over every cosmetic concern.
	if limit < len(suffix)+len(ext) {
		ext = ""
	}
	if limit < len(suffix) {
		return truncateOnRuneBoundary(name, limit)
	}

	base = truncateOnRuneBoundary(base, limit-len(suffix)-len(ext))

	return base + suffix + ext
}

func truncateOnRuneBoundary(s string, n int) string {
	if n <= 0 {
		return ""
	}
	if len(s) <= n {
		return s
	}
	for n > 0 && !utf8.RuneStart(s[n]) {
		n--
	}
	return s[:n]
}

// SafeJoin joins root and relPath and guarantees the result stays inside
// root, erroring out otherwise. Defense-in-depth: even though
// SanitizeComponent already strips "/" and TrimRight already keeps ".." from
// surviving as a literal component, this is the last line of defense against
// a Drive item name escaping --path entirely — a raw filepath.Join+filepath.
// Clean was proven (finding C5) to resolve OUTSIDE the destination directory
// when handed a crafted relative path.
func SafeJoin(root, relPath string) (string, error) {
	rootAbs, err := filepath.Abs(filepath.Clean(root))
	if err != nil {
		return "", fmt.Errorf("localpath.SafeJoin: resolve root %q: %w", root, err)
	}

	joined := filepath.Join(rootAbs, relPath)
	if joined != rootAbs && !strings.HasPrefix(joined, rootAbs+string(filepath.Separator)) {
		return "", fmt.Errorf("localpath.SafeJoin: path escapes root (root=%q relPath=%q resolved=%q)", root, relPath, joined)
	}
	return joined, nil
}

// CollisionKey is the key two sibling names are compared on to decide whether they
// would land on the SAME file once written to the destination. It is used ONLY for
// that comparison — the name itself is never rewritten with it.
//
// Two folds, both because the destination may not tell the names apart:
//   - lower-case: NTFS/APFS are case-insensitive by default.
//   - Unicode NFC: Drive keeps names byte-for-byte, so the same Vietnamese text can
//     exist as two items, one NFC (typed on Windows), one NFD (uploaded from macOS).
//     NTFS stores them as two files but OneDrive/SharePoint treats them as ONE name,
//     so every --push-to wrote that name from two sources and OneDrive minted a
//     "-<machine>-N" conflict copy each run (real incident 2026-10-27, 183+ copies).
//     Keyed in NFC they collide in the sibling pass, and one gets a "_<id>" suffix.
func CollisionKey(name string) string {
	return strings.ToLower(norm.NFC.String(name))
}
