// incremental.go persists the on-disk state --incremental depends on: a
// full index of every Drive item under one tracked folder (so a later run
// can tell, from a Drive Changes API event, whether a changed file belongs
// to our tree and under which already-known folder) plus the Changes API
// page token to resume from. Written to
// <reportDir>/incremental/<folderTag>.json — a sibling of state/<folderTag>.json
// (the shrink guard's much smaller item-count-only baseline), kept as a
// separate file/directory since the two serve different purposes and this
// one can grow much larger for a big tree.
package report

import (
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"time"
)

// ManifestEntry is one known Drive item (file or folder) somewhere under a
// tracked root, as of the last full or incremental run. Path is relative to
// the tracked folder's own local prefix (the same convention mirror.Item.Path
// already uses), '/' separators.
type ManifestEntry struct {
	ParentID     string `json:"parent_id"`
	Path         string `json:"path"`
	IsFolder     bool   `json:"is_folder"`
	MimeType     string `json:"mime_type,omitempty"`
	MD5Checksum  string `json:"md5_checksum,omitempty"`
	ModifiedTime int64  `json:"modified_time,omitempty"`
	Size         int64  `json:"size,omitempty"`
}

// IncrementalManifest is one tracked root folder's full known-item index
// plus the Drive Changes API page token to resume from next run.
type IncrementalManifest struct {
	FolderID       string                   `json:"folder_id"`
	LocalPrefix    string                   `json:"local_prefix"`
	StartPageToken string                   `json:"start_page_token"`
	Items          map[string]ManifestEntry `json:"items"` // Drive file ID -> entry
	SavedAt        string                   `json:"saved_at"`
}

// ReadIncrementalManifest reads dir/<folderTag>.json. A missing/unreadable/
// corrupt file all collapse to nil — no prior incremental state is a normal
// first-run condition (falls back to a full listing), same policy as
// ReadListingState for the shrink guard.
func ReadIncrementalManifest(dir string, folderTag string) *IncrementalManifest {
	data, err := os.ReadFile(filepath.Join(dir, folderTag+".json"))
	if err != nil {
		return nil
	}
	var m IncrementalManifest
	if err := json.Unmarshal(data, &m); err != nil {
		return nil
	}
	if m.Items == nil {
		m.Items = map[string]ManifestEntry{}
	}
	return &m
}

// WriteIncrementalManifest writes m to dir/<folderTag>.json, stamping
// SavedAt. Callers must only call this once a run's result has been
// ACCEPTED (not rejected by the shrink guard) — same "never poison the next
// baseline with a run we don't trust" contract as WriteListingState.
func WriteIncrementalManifest(dir string, folderTag string, m IncrementalManifest) error {
	if err := os.MkdirAll(dir, 0o755); err != nil {
		return fmt.Errorf("create incremental state dir: %w", err)
	}
	m.SavedAt = time.Now().Format(time.RFC3339)

	data, err := json.MarshalIndent(m, "", "  ")
	if err != nil {
		return fmt.Errorf("marshal incremental manifest: %w", err)
	}
	if err := os.WriteFile(filepath.Join(dir, folderTag+".json"), data, 0o644); err != nil {
		return fmt.Errorf("write incremental manifest: %w", err)
	}
	return nil
}
