// Package cloudsync detects whether a local path sits inside a folder a
// THIRD-PARTY cloud-sync desktop client (OneDrive, Google Drive, Dropbox,
// iCloud Drive) is actively watching/uploading.
//
// Why this exists (real incident): gdrive-mirror-cli's download path is
// atomic download-to-temp-then-rename-into-place (see mirror/download.go).
// That is the correct pattern for a filesystem nobody else is watching, but
// when --path is ALSO the live root of another sync client, every rename
// this tool performs — even one that produces byte-identical content — is
// an external, out-of-protocol write from that other client's point of
// view. OneDrive in particular reacts to this by treating it as a
// conflicting simultaneous edit and creating a NEW numbered copy
// ("name-2.ext", "name-3.ext", ...) rather than reconciling — one new
// duplicate per run, forever, even though the content never actually
// changed. This was observed in production: --path was set to
// `C:\Users\<user>\OneDrive - <org>`, and files accumulated dozens of
// "-N" siblings, one per scheduled run.
//
// Detect is a best-effort heuristic, not a security boundary — it exists to
// catch the common, costly mistake early (before a single byte is written)
// with a clear explanation, not to enumerate every possible cloud-sync
// client on every OS.
package cloudsync

import (
	"os"
	"path/filepath"
	"strings"
)

// Match describes one detected cloud-sync root that absPath falls under.
type Match struct {
	Provider string // human-readable name, e.g. "OneDrive", "Google Drive"
	Root     string // the sync root absPath was found under
	// FromEnv is true when Root came from an authoritative source (an
	// environment variable the sync client itself sets) rather than a
	// heuristic substring match on the path — used only to phrase the
	// warning message with the right confidence, never to change behavior.
	FromEnv bool
}

// envRoots lists environment variables cloud-sync clients set to their own
// tracked folder(s). Checked first because they are authoritative (the
// client told us directly), unlike the substring fallback below.
var envRoots = []struct {
	provider string
	envVar   string
}{
	// Windows OneDrive sync client sets one or more of these to the
	// account's local sync root(s); a machine signed into both a personal
	// and a work/school account can have both set simultaneously.
	{"OneDrive", "OneDrive"},
	{"OneDrive", "OneDriveConsumer"},
	{"OneDrive", "OneDriveCommercial"},
}

// pathSubstrings is the cross-platform fallback for when a client doesn't
// expose (or we can't read) an env var — matched case-insensitively against
// every path component. Deliberately narrow: only names that are
// essentially unambiguous signals of a cloud-sync client's own folder
// naming convention, to keep false positives rare.
var pathSubstrings = []struct {
	provider string
	needle   string
}{
	{"OneDrive", "onedrive"},
	{"Google Drive", "google drive"},
	{"Google Drive", "googledrive-"}, // macOS ~/Library/CloudStorage/GoogleDrive-<account>
	{"Dropbox", "dropbox"},
	{"iCloud Drive", "cloudstorage/icloud"},
	{"iCloud Drive", "mobile documents/com~apple~clouddocs"},
	{"Box", "cloudstorage/box-"},
}

// Detect reports whether absPath (already filepath.Abs'd by the caller) is
// at or under a known cloud-sync client's watched root. Returns the first
// match found; ok is false when nothing matched.
func Detect(absPath string) (Match, bool) {
	normalized := filepath.Clean(absPath)

	for _, e := range envRoots {
		root := os.Getenv(e.envVar)
		if root == "" {
			continue
		}
		root = filepath.Clean(root)
		if isSameOrUnder(normalized, root) {
			return Match{Provider: e.provider, Root: root, FromEnv: true}, true
		}
	}

	lower := strings.ToLower(filepath.ToSlash(normalized))
	for _, p := range pathSubstrings {
		if strings.Contains(lower, p.needle) {
			return Match{Provider: p.provider, Root: normalized}, true
		}
	}

	return Match{}, false
}

// isSameOrUnder reports whether path equals root or is nested under it,
// comparing case-insensitively (Windows/macOS filesystems backing these
// clients are case-insensitive by default) and independent of a
// trailing separator on either side.
func isSameOrUnder(path, root string) bool {
	path = strings.ToLower(filepath.ToSlash(strings.TrimRight(path, `/\`)))
	root = strings.ToLower(filepath.ToSlash(strings.TrimRight(root, `/\`)))
	if path == root {
		return true
	}
	return strings.HasPrefix(path, root+"/")
}
