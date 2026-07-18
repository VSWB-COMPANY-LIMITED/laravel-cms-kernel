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
	Type         string // "dir" | "file"
	Path         string // relative path from the sync root, using '/' separators
	ID           string
	MimeType     string
	MD5Checksum  string // "" when Drive doesn't provide one (Google-native files)
	ModifiedTime int64  // unix seconds
	Size         int64
}

// Config holds the resolved CLI options for one run.
type Config struct {
	FolderID    string
	Path        string
	CredsFile   string
	Retry       int
	Force       bool
	DryRun      bool
	Limit       int
	Concurrency int
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
}
