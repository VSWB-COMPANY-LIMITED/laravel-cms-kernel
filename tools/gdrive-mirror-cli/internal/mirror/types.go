package mirror

import "github.com/vswb/gdrive-mirror/internal/report"

// FolderMimeType and ShortcutMimeType are the two special Drive mimeTypes
// that need non-standard handling: folders recurse, shortcuts are pointers
// with no content of their own (their target is listed as its own item in
// the same listing — see GDriveMirrorSync.php L854-871).
const (
	FolderMimeType   = "application/vnd.google-apps.folder"
	ShortcutMimeType = "application/vnd.google-apps.shortcut"
)

// ExportSpec is the Microsoft Office (OpenXML) target for a Google-native
// mimeType, ported verbatim from GDriveMirrorSync::$exportMap.
type ExportSpec struct {
	Ext  string
	Mime string
}

// ExportMap: Google Docs/Sheets/Slides/Drawings/Apps Script → downloadable
// OpenXML/binary formats. Any other Google-native mimeType (Forms, Sites,
// My Maps, Jamboard…) is not in this map and fails permanent
// 'fileNotDownloadable' when attempted.
var ExportMap = map[string]ExportSpec{
	"application/vnd.google-apps.document":     {Ext: "docx", Mime: "application/vnd.openxmlformats-officedocument.wordprocessingml.document"},
	"application/vnd.google-apps.spreadsheet":  {Ext: "xlsx", Mime: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"},
	"application/vnd.google-apps.presentation": {Ext: "pptx", Mime: "application/vnd.openxmlformats-officedocument.presentationml.presentation"},
	"application/vnd.google-apps.drawing":      {Ext: "png", Mime: "image/png"},
	"application/vnd.google-apps.script":       {Ext: "json", Mime: "application/vnd.google-apps.script+json"},
}

// Item is one Drive entry discovered during recursive listing, in a shape
// analogous to GDriveMirrorSync::fetchFolderChildrenViaApi()'s array items.
type Item struct {
	Type string // "dir" | "file"
	// Path is the relative path from the sync root, using '/' separators.
	// Every path component in it has ALREADY been through
	// localpath.SanitizeComponent + localpath.TruncateComponent and deduped
	// against its siblings (see list.go resolveSiblingNames) — for a file,
	// this already includes the export extension (".docx"…) when applicable.
	// It is safe to use directly as a local path once joined with the
	// destination root via localpath.SafeJoin.
	Path string
	ID   string
	// ParentID is the Drive ID of the folder this item is a DIRECT child
	// of — "" for items produced by the --retry-failed path (ItemFromFailed),
	// which never needed it before this field existed. Only consumed by the
	// --incremental manifest (see incremental.go); every other code path
	// ignores it.
	ParentID     string
	MimeType     string
	MD5Checksum  string // "" when Drive doesn't provide one (Google-native files)
	ModifiedTime int64  // unix seconds
	Size         int64
}

// Config holds the resolved CLI options for one run.
type Config struct {
	FolderID     string
	Path         string
	CredsFile    string
	Retry        int
	Force        bool
	DryRun       bool
	Limit        int
	Concurrency  int
	IgnoreShrink bool // skip the listing shrink-guard abort (see applyShrinkGuard) — PHP's --allow-shrink

	// ListConcurrency bounds how many folder-listing requests
	// (files.list) run concurrently during the recursive listing phase
	// (see list.go ListFolderRecursive) — independent from Concurrency
	// (which bounds concurrent file DOWNLOADS instead): listing calls are
	// cheap metadata-only round-trips, so this can usually run higher
	// than the download concurrency without issue.
	ListConcurrency int

	// AllowCloudSyncPath bypasses the preflight check that refuses to run
	// when --path resolves inside a folder a THIRD-PARTY cloud-sync
	// client (OneDrive/Google Drive/Dropbox/iCloud desktop app) is
	// actively watching — see cloudsync.Detect. Off by default: mirroring
	// straight into such a folder means two independent sync engines
	// fight over the same files, and this tool's atomic
	// download-then-rename is exactly the pattern that makes the OTHER
	// client mistake an unrelated-but-identical rewrite for a conflicting
	// edit and spawn "name-2.ext", "name-3.ext"... duplicates that grow by
	// one every run (real incident, see README "Không mirror thẳng vào
	// thư mục cloud-sync sống").
	AllowCloudSyncPath bool

	// Incremental turns on the Drive Changes API delta-sync path (see
	// incremental.go): once a folder has completed one full listing under
	// --incremental, subsequent runs ask Drive "what changed since last
	// time" instead of re-listing the entire tree, and only re-list the
	// handful of folders that actually changed. Self-bootstrapping — the
	// very first run for a folder (no saved manifest yet) is always a
	// normal full listing, which also saves the manifest + a Changes API
	// page token for the next run to use.
	Incremental bool
}

// Stats accumulates the end-of-run summary — mutated under Syncer.mu.
type Stats struct {
	Processed   int
	Updated     int
	Skipped     int
	Errors      int
	Folders     int
	Collisions  int
	TotalListed int
	FailedFiles []report.FailedItem

	// Aborted is true when this Run()/RunRetry() call stopped early instead
	// of completing a normal pass — either the shrink guard rejected a
	// suspiciously small listing (AbortReason "shrink-guard") or the local
	// filesystem circuit breaker tripped mid-download (AbortReason
	// "local-fs-circuit-breaker"). Exposed on Stats (not just logged) so a
	// multi-folder caller (main.go) can decide whether to keep processing
	// the remaining folders — a shrink-guard abort is folder-specific and
	// safe to move past, but a circuit-breaker abort means the destination
	// disk itself is likely dead, so trying the next folder is pointless.
	Aborted     bool
	AbortReason string
}
