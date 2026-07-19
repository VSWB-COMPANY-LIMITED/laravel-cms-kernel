// state.go persists one folder's previous-run item count so the shrink
// guard (internal/classify.ShouldAbortOnShrink) has something to compare
// this run's listing against. Ports GDriveMirrorSync::readListingState()/
// writeListingState() (PHP source ~L1798/~L1815) — field names kept
// identical (folder_id/item_count/listed_at/run_id) so a state file written
// by either implementation is readable by the other.
//
// The PHP source writes to storage_path('app/gdrive-sync/state') — a
// Laravel path this standalone CLI doesn't have. This port writes next to
// the failed-item reports instead: <reportDir>/state/<folderTag>.json (see
// mirror.Syncer.applyShrinkGuard), keyed the same way as the JSON/CSV/XLSX
// reports and the unexportable manifest (report.FolderTag).
package report

import (
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"time"
)

// ListingState is one folder's previous-run item count. Field names/JSON
// tags match GDriveMirrorSync::writeListingState()'s PHP payload 1:1.
type ListingState struct {
	FolderID  string `json:"folder_id"`
	ItemCount int    `json:"item_count"`
	ListedAt  string `json:"listed_at"`
	RunID     string `json:"run_id"`
}

// ParseListingState parses raw state JSON bytes. Pure — no I/O — so a
// corrupt/malformed state file can be exercised in tests without touching a
// filesystem.
func ParseListingState(data []byte) (ListingState, error) {
	var st ListingState
	if err := json.Unmarshal(data, &st); err != nil {
		return ListingState{}, fmt.Errorf("parse listing state JSON: %w", err)
	}
	return st, nil
}

// ReadListingState reads stateDir/<folderTag>.json. A missing file,
// unreadable file, or corrupt JSON all collapse to nil — no prior state is a
// normal first-run condition, and a damaged state file must never fail the
// whole sync over what is purely a safety-net optimization for the NEXT
// run. Mirrors readListingState()'s "return null on any failure" contract.
func ReadListingState(stateDir string, folderTag string) *ListingState {
	data, err := os.ReadFile(filepath.Join(stateDir, folderTag+".json"))
	if err != nil {
		return nil
	}
	st, err := ParseListingState(data)
	if err != nil {
		return nil
	}
	return &st
}

// WriteListingState writes the current run's item count for folderTag.
// Callers must only invoke this once a listing has been ACCEPTED (not
// rejected by the shrink guard) — mirrors writeListingState()'s call site in
// GDriveMirrorSync::handle(), which never overwrites state with a count the
// guard just refused to trust. A write failure is returned to the caller to
// log-and-continue (state is a safety net for next time, not data this run
// depends on).
func WriteListingState(stateDir string, folderTag string, folderID string, itemCount int, runID string) error {
	if err := os.MkdirAll(stateDir, 0o755); err != nil {
		return fmt.Errorf("create state dir: %w", err)
	}

	payload := ListingState{
		FolderID:  folderID,
		ItemCount: itemCount,
		ListedAt:  time.Now().Format(time.RFC3339),
		RunID:     runID,
	}
	data, err := json.MarshalIndent(payload, "", "  ")
	if err != nil {
		return fmt.Errorf("marshal listing state: %w", err)
	}

	if err := os.WriteFile(filepath.Join(stateDir, folderTag+".json"), data, 0o644); err != nil {
		return fmt.Errorf("write listing state: %w", err)
	}
	return nil
}
