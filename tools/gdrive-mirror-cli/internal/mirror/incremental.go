// incremental.go implements the --incremental delta-sync path: once a
// folder has one full listing under its belt, later runs ask the Drive
// Changes API "what changed since last time" instead of re-listing the
// whole tree, and only re-list the folders that actually need it.
//
// The pure decision logic (computeDirtyFolders, mergeManifest) is kept
// separate from the network-touching orchestration (refreshDirtyFolders,
// fetchChangesSince, tryRunIncremental) specifically so the tricky part —
// "given these Changes API events and what we knew before, which folders
// need a fresh listing, and how do old+fresh entries merge" — is
// unit-testable without a live/mocked Drive API (see incremental_test.go).
package mirror

import (
	"context"
	"fmt"
	"path/filepath"
	"time"

	"google.golang.org/api/drive/v3"

	"github.com/vswb/gdrive-mirror/internal/report"
)

// driveChange is a Change event reduced to the fields computeDirtyFolders
// actually needs — decoupled from *drive.Change so the pure logic below has
// no dependency on the live API types at all.
type driveChange struct {
	fileID  string
	removed bool // covers both Change.Removed and File.Trashed
	parents []string
}

// entryFromItem converts one listed Item into the manifest shape persisted
// for --incremental. Pure.
func entryFromItem(it Item) report.ManifestEntry {
	return report.ManifestEntry{
		ParentID:     it.ParentID,
		Path:         it.Path,
		IsFolder:     it.Type == "dir",
		MimeType:     it.MimeType,
		MD5Checksum:  it.MD5Checksum,
		ModifiedTime: it.ModifiedTime,
		Size:         it.Size,
	}
}

// computeDirtyFolders decides, from a batch of Changes API events and what
// the manifest already knew, which folder IDs need their DIRECT children
// re-listed. A folder is dirty when:
//   - a change's file is already a known item — its (old) parent might no
//     longer contain it (removed, or moved elsewhere), so that parent must
//     be re-checked; and
//   - a change's file (when not removed) lists a KNOWN folder among its
//     current parents — that parent may have gained (or renamed) a child.
//
// rootFolderID is always considered "known" even though the manifest only
// stores its CHILDREN, never an entry for the root itself.
//
// This intentionally only reacts to what Drive reported changing — it does
// NOT detect a renamed folder's cascading effect on its own descendants'
// cached paths (a separate, rarer concern handled by refreshDirtyFolders'
// path-mismatch cascade at network-call time, since that needs to compare
// against a FRESH listing, not just the change events themselves).
func computeDirtyFolders(rootFolderID string, manifest map[string]report.ManifestEntry, changes []driveChange) map[string]bool {
	isKnownFolder := func(id string) bool {
		if id == rootFolderID {
			return true
		}
		e, ok := manifest[id]
		return ok && e.IsFolder
	}

	dirty := map[string]bool{}
	for _, ch := range changes {
		if old, ok := manifest[ch.fileID]; ok && old.ParentID != "" {
			dirty[old.ParentID] = true
		}
		if ch.removed {
			continue
		}
		for _, p := range ch.parents {
			if isKnownFolder(p) {
				dirty[p] = true
			}
		}
	}
	return dirty
}

// mergeManifest combines the OLD manifest with freshly re-listed entries:
// any old entry whose parent was among the visited (freshly re-listed)
// folders is dropped — its parent's current child set has just been
// re-derived from scratch and either still contains it (present again via
// fresh) or genuinely doesn't anymore (removed/moved away, correctly
// absent). Every old entry whose parent was NEVER touched passes through
// unchanged, which is the entire point of incremental sync: most of a large
// tree needs zero network calls to carry forward. Pure.
func mergeManifest(old map[string]report.ManifestEntry, visited map[string]bool, fresh map[string]report.ManifestEntry) map[string]report.ManifestEntry {
	merged := make(map[string]report.ManifestEntry, len(old)+len(fresh))
	for id, e := range old {
		if visited[e.ParentID] {
			continue
		}
		merged[id] = e
	}
	for id, e := range fresh {
		merged[id] = e
	}
	return merged
}

// refreshDirtyFolders re-lists every folder in initialDirty's DIRECT
// children fresh, and cascades to any additional folder whose freshly
// resolved path no longer matches what the old manifest recorded — a
// renamed/moved TRACKED folder invalidates every path cached under its old
// name, even though Drive's Changes API only ever reports the rename event
// itself, not "and therefore re-check everything below it". Queuing the
// affected child (rather than eagerly recursing) keeps this a flat
// worklist that naturally terminates (bounded by tree depth) and touches
// each folder's direct children exactly once no matter how many times it
// gets queued.
//
// A folder discovered here that is NOT already in oldManifest is brand
// new — full recursive listing (ListFolderRecursive) is the only way to
// discover ITS children, since incremental has no prior state for it at
// any depth.
//
// Returns every folder ID whose direct children were actually re-listed
// (visited) — the caller needs this to know which old manifest entries to
// drop in mergeManifest.
func (s *Syncer) refreshDirtyFolders(ctx context.Context, rootFolderID string, oldManifest map[string]report.ManifestEntry, initialDirty map[string]bool) (fresh map[string]report.ManifestEntry, freshItems []Item, visited map[string]bool, err error) {
	fresh = map[string]report.ManifestEntry{}
	visited = map[string]bool{}

	pathOf := func(folderID string) (string, bool) {
		if folderID == rootFolderID {
			return "", true
		}
		if e, ok := fresh[folderID]; ok {
			return e.Path, true
		}
		if e, ok := oldManifest[folderID]; ok {
			return e.Path, true
		}
		return "", false
	}

	queue := make([]string, 0, len(initialDirty))
	for id := range initialDirty {
		queue = append(queue, id)
	}

	for len(queue) > 0 {
		folderID := queue[0]
		queue = queue[1:]
		if visited[folderID] {
			continue
		}
		visited[folderID] = true

		relPath, ok := pathOf(folderID)
		if !ok {
			s.logWarn("Incremental: bỏ qua folder dirty không rõ đường dẫn cũ (id=%s) — sẽ được liệt kê đầy đủ ở lần chạy không --incremental kế tiếp.", folderID)
			continue
		}

		resolved, ferr := s.fetchOneLevel(ctx, folderID, relPath)
		if ferr != nil {
			return nil, nil, nil, ferr
		}

		for _, c := range resolved {
			if c.collided {
				s.mu.Lock()
				s.stats.Collisions++
				s.mu.Unlock()
				label := relPath
				if label == "" {
					label = "(root)"
				}
				s.logWarn("Trùng tên trong '%s': '%s' (id=%s) phải đổi thành '%s' để không ghi đè/gộp với anh em cùng cấp", label, c.name, c.id, c.localName)
			}

			childPath := c.localName
			if relPath != "" {
				childPath = relPath + "/" + c.localName
			}
			item := Item{
				Type:         itemType(c.isFolder),
				Path:         childPath,
				ID:           c.id,
				ParentID:     folderID,
				MimeType:     c.mimeType,
				MD5Checksum:  c.md5Checksum,
				ModifiedTime: c.modifiedTime,
				Size:         c.size,
			}
			freshItems = append(freshItems, item)
			fresh[c.id] = entryFromItem(item)

			if !c.isFolder {
				continue
			}

			oldEntry, known := oldManifest[c.id]
			switch {
			case !known:
				subItems, serr := s.ListFolderRecursive(ctx, c.id, childPath)
				if serr != nil {
					return nil, nil, nil, serr
				}
				for _, si := range subItems {
					freshItems = append(freshItems, si)
					fresh[si.ID] = entryFromItem(si)
				}
			case oldEntry.Path != childPath:
				queue = append(queue, c.id)
			}
		}
	}

	return fresh, freshItems, visited, nil
}

// fetchChangesSince pages through Changes.List starting at pageToken,
// reducing every event to the driveChange shape computeDirtyFolders needs,
// and returns the new page token to persist for the FOLLOWING run (Drive's
// contract: the last page of a changes.list response carries
// NewStartPageToken instead of NextPageToken).
func (s *Syncer) fetchChangesSince(ctx context.Context, pageToken string) ([]driveChange, string, error) {
	var out []driveChange
	token := pageToken

	for {
		var resp *drive.ChangeList
		outcome := s.withRetry(ctx, "changes.list", func() error {
			r, err := s.srv.Changes.List(token).
				PageSize(1000).
				SupportsAllDrives(true).
				IncludeItemsFromAllDrives(true).
				Fields("nextPageToken, newStartPageToken, changes(fileId,removed,file(id,mimeType,parents,trashed))").
				Context(ctx).
				Do()
			if err != nil {
				return err
			}
			resp = r
			return nil
		})
		if outcome.errMsg != "" {
			return nil, "", fmt.Errorf("%s", outcome.errMsg)
		}

		for _, c := range resp.Changes {
			removed := c.Removed
			var parents []string
			if c.File != nil {
				parents = c.File.Parents
				removed = removed || c.File.Trashed
			}
			out = append(out, driveChange{fileID: c.FileId, removed: removed, parents: parents})
		}

		if resp.NewStartPageToken != "" {
			return out, resp.NewStartPageToken, nil
		}
		if resp.NextPageToken == "" {
			// Shouldn't happen per the API's contract (one of the two must
			// be set), but never spin forever on an unexpected response —
			// degrade to "no new token", which just means the NEXT
			// --incremental run replays from the same point (safe, just
			// redundant work, never data loss).
			return out, "", nil
		}
		token = resp.NextPageToken
	}
}

// incrementalStateDir/FolderTag are the on-disk location for one folder's
// IncrementalManifest — a sibling of state/<folderTag>.json (see
// applyShrinkGuard) under the same reportDir.
func (s *Syncer) incrementalStateDir() string {
	return filepath.Join(s.reportDir(), "incremental")
}

// tryRunIncremental attempts the Drive-Changes-API delta path for the
// current folder. handled=false tells the caller (Run) there is no usable
// prior state — a normal full listing follows, which will also bootstrap
// this state for next time (see bootstrapIncrementalManifest). A missing,
// corrupt, or now-invalid (e.g. Drive says the token expired) manifest all
// fall back this way — --incremental never turns "no/bad prior state" into
// a fatal error, mirroring the shrink guard's identical policy for
// state/<folderTag>.json.
func (s *Syncer) tryRunIncremental(ctx context.Context, started time.Time) (handled bool, stats Stats, err error) {
	folderTag := report.FolderTag([]string{s.cfg.FolderID})
	dir := s.incrementalStateDir()
	manifest := report.ReadIncrementalManifest(dir, folderTag)
	if manifest == nil || manifest.StartPageToken == "" {
		return false, Stats{}, nil
	}

	s.logf("Incremental: dùng manifest cũ (%d item đã biết), hỏi Drive Changes API từ token đã lưu...", len(manifest.Items))

	changes, newToken, cerr := s.fetchChangesSince(ctx, manifest.StartPageToken)
	if cerr != nil {
		s.logWarn("Incremental: Changes API lỗi (%v) — quay về liệt kê đầy đủ lần này.", cerr)
		return false, Stats{}, nil
	}

	folderIDs := []string{s.cfg.FolderID}

	if len(changes) == 0 {
		s.logf("Incremental: 0 thay đổi kể từ lần trước — không tải gì thêm.")
		s.stats.TotalListed = len(manifest.Items)
		manifest.StartPageToken = newToken
		if werr := report.WriteIncrementalManifest(dir, folderTag, *manifest); werr != nil {
			s.logWarn("Incremental: không cập nhật được page token: %v", werr)
		}
		s.printSummary(time.Since(started))
		return true, s.stats, nil
	}

	dirty := computeDirtyFolders(s.cfg.FolderID, manifest.Items, changes)
	s.logf("Incremental: %d thay đổi liên quan tới %d folder trong cây đang theo dõi — chỉ liệt kê lại đúng các folder này (thay vì toàn bộ cây).", len(changes), len(dirty))

	freshEntries, freshItems, visited, rerr := s.refreshDirtyFolders(ctx, s.cfg.FolderID, manifest.Items, dirty)
	if rerr != nil {
		return true, s.stats, fmt.Errorf("incremental refresh: %w", rerr)
	}

	mergedItems := mergeManifest(manifest.Items, visited, freshEntries)
	s.stats.TotalListed = len(mergedItems)

	if aborted := s.applyShrinkGuard(len(mergedItems)); aborted {
		// Deliberately does NOT persist the manifest/token: the next
		// --incremental run will replay the SAME Changes API batch and
		// re-derive the same (suspicious) result, rather than silently
		// accepting a listing the guard just refused to trust. Matches the
		// full-listing path's identical "never poison the next baseline"
		// contract in applyShrinkGuard's own doc.
		reportDir := s.reportDir()
		s.writeReports(reportDir, folderIDs)
		s.printSummary(time.Since(started))
		return true, s.stats, nil
	}

	if !s.cfg.DryRun {
		newManifest := report.IncrementalManifest{
			FolderID:       s.cfg.FolderID,
			LocalPrefix:    manifest.LocalPrefix,
			StartPageToken: newToken,
			Items:          mergedItems,
		}
		if werr := report.WriteIncrementalManifest(dir, folderTag, newManifest); werr != nil {
			s.logWarn("Incremental: không ghi được manifest cập nhật: %v", werr)
		}
	}

	stats, err = s.runTasksAndReport(ctx, freshItems, manifest.LocalPrefix, folderIDs, started)
	return true, stats, err
}

// bootstrapIncrementalManifest builds and saves the FIRST IncrementalManifest
// for a folder right after a normal full listing completes (and the shrink
// guard accepted it) — the full listing already visited every item, so this
// is pure bookkeeping, one extra Changes API call (GetStartPageToken) to
// know where "now" is for the next --incremental run. Failure here is
// logged and otherwise ignored: it only means the NEXT run falls back to a
// full listing again (self-healing), never a reason to fail THIS run.
func (s *Syncer) bootstrapIncrementalManifest(ctx context.Context, items []Item, localPrefix string) {
	token, err := s.srv.Changes.GetStartPageToken().Context(ctx).SupportsAllDrives(true).Do()
	if err != nil {
		s.logWarn("Incremental: không lấy được start page token — lần chạy sau (nếu dùng --incremental) sẽ vẫn liệt kê đầy đủ: %v", err)
		return
	}

	entries := make(map[string]report.ManifestEntry, len(items))
	for _, it := range items {
		entries[it.ID] = entryFromItem(it)
	}

	m := report.IncrementalManifest{
		FolderID:       s.cfg.FolderID,
		LocalPrefix:    localPrefix,
		StartPageToken: token.StartPageToken,
		Items:          entries,
	}
	folderTag := report.FolderTag([]string{s.cfg.FolderID})
	if err := report.WriteIncrementalManifest(s.incrementalStateDir(), folderTag, m); err != nil {
		s.logWarn("Incremental: không ghi được manifest: %v", err)
		return
	}
	s.logf("Incremental: đã lưu manifest (%d item) + page token — lần chạy sau với --incremental sẽ chỉ hỏi Drive \"có gì đổi\".", len(entries))
}
