package mirror

import (
	"context"
	"fmt"
	"path/filepath"
	"sort"
	"strings"
	"time"

	"google.golang.org/api/drive/v3"

	"github.com/vswb/gdrive-mirror/internal/localpath"
)

// ListFolderRecursive lists folderID's contents recursively via the Drive
// API, in a shape used throughout this package (Item). Ports
// GDriveMirrorSync::listFolderRecursiveViaApi() including the "verify on
// zero" guard (BUG A in the PHP source): Drive's files.list occasionally
// returns an empty page for a folder that genuinely has children (observed
// under API pressure/rate-limiting) — a naive "0 items" reading silently
// drops the whole subtree with zero trace. One extra request to double-check
// a reported-empty result is far cheaper than losing a subtree silently.
func (s *Syncer) ListFolderRecursive(ctx context.Context, folderID string, relativePath string) ([]Item, error) {
	items, err := s.fetchFolderChildren(ctx, folderID, relativePath)
	if err != nil {
		return nil, err
	}
	if len(items) > 0 {
		return items, nil
	}

	select {
	case <-time.After(time.Second):
	case <-ctx.Done():
		return nil, ctx.Err()
	}

	second, err := s.fetchFolderChildren(ctx, folderID, relativePath)
	if err != nil {
		return nil, err
	}
	if len(second) == 0 {
		// Confirmed empty — a folder with genuinely 0 children is normal, not a bug.
		return nil, nil
	}

	s.listingRetryHits.Add(1)
	logPath := relativePath
	if logPath == "" {
		logPath = "(root)"
	}
	s.logWarn("Listing trả 0 nhưng verify lại có item — Drive API cụt (path=%s, folder_id=%s, second_count=%d)", logPath, folderID, len(second))

	return second, nil
}

// rawChild is one direct child of a folder exactly as Drive reported it,
// before any name-safety processing. Kept separate from Item so the pure
// dedup/sanitize pass (resolveSiblingNames) is unit-testable without a live
// Drive API call.
type rawChild struct {
	id           string
	name         string
	mimeType     string
	md5Checksum  string
	modifiedTime int64
	size         int64
	isFolder     bool
}

// resolvedChild is a rawChild plus its final, collision-safe local path
// component. See resolveSiblingNames for how localName is derived.
type resolvedChild struct {
	rawChild
	localName string // sanitized + truncated + deduped; a single path component, no separators
	collided  bool   // true when a same-name sibling forced this entry to take a suffix
}

// fetchFolderChildren lists one level (paginated) of folderID, resolves every
// direct child's final local name as ONE shared namespace (files+folders
// together — fixes C2/C3), then recurses into any subfolders using the
// already-resolved path. Separated from ListFolderRecursive so the
// verify-on-zero retry above can call it again without re-recursing into
// itself.
//
// Restructured (2026-07-18 name-collision audit) from the previous
// list-while-recursing design: resolving names while still paging meant a
// later page's clashing name could never be seen, so any dedup decision was
// necessarily unstable. Gathering the full sibling set FIRST is what makes
// deterministic dedup (see resolveSiblingNames) possible at all.
func (s *Syncer) fetchFolderChildren(ctx context.Context, folderID string, relativePath string) ([]Item, error) {
	label := relativePath
	if label == "" {
		label = "(root)"
	}

	raw := s.collectRawChildren(ctx, folderID, label)
	resolved := resolveSiblingNames(raw)

	items := make([]Item, 0, len(resolved))
	for _, c := range resolved {
		if c.collided {
			s.mu.Lock()
			s.stats.Collisions++
			s.mu.Unlock()
			s.logWarn("Trùng tên trong '%s': '%s' (id=%s) phải đổi thành '%s' để không ghi đè/gộp với anh em cùng cấp", label, c.name, c.id, c.localName)
		}

		childPath := c.localName
		if relativePath != "" {
			childPath = relativePath + "/" + c.localName
		}

		items = append(items, Item{
			Type:         itemType(c.isFolder),
			Path:         childPath,
			ID:           c.id,
			MimeType:     c.mimeType,
			MD5Checksum:  c.md5Checksum,
			ModifiedTime: c.modifiedTime,
			Size:         c.size,
		})

		if c.isFolder {
			children, err := s.ListFolderRecursive(ctx, c.id, childPath)
			if err != nil {
				return items, err
			}
			items = append(items, children...)
		}
	}

	return items, nil
}

// collectRawChildren pages through folderID's direct children via the Drive
// API and returns everything gathered. A listing error stops pagination
// (logged) but still returns whatever was collected so far rather than
// propagating a fatal error that would kill the entire sync (BUG B in the
// PHP source: an uncaught listing error used to nuke the whole run,
// including subtrees that had already synced fine).
func (s *Syncer) collectRawChildren(ctx context.Context, folderID string, label string) []rawChild {
	var raw []rawChild
	pageToken := ""
	query := fmt.Sprintf("'%s' in parents and trashed = false", strings.ReplaceAll(folderID, "'", "\\'"))

	for {
		var resp *drive.FileList
		outcome := s.withRetry(ctx, label, func() error {
			call := s.srv.Files.List().
				Q(query).
				PageSize(1000).
				Fields("nextPageToken, files(id,name,mimeType,modifiedTime,md5Checksum,size)").
				SupportsAllDrives(true).
				IncludeItemsFromAllDrives(true).
				Context(ctx)
			if pageToken != "" {
				call = call.PageToken(pageToken)
			}
			r, err := call.Do()
			if err != nil {
				return err
			}
			resp = r
			return nil
		})

		if outcome.errMsg != "" {
			s.logError("Listing failed for %s after %d attempt(s): %s", label, outcome.attempts, outcome.errMsg)
			break
		}
		if resp == nil {
			break
		}

		for _, f := range resp.Files {
			var ts int64
			if f.ModifiedTime != "" {
				if t, err := time.Parse(time.RFC3339, f.ModifiedTime); err == nil {
					ts = t.Unix()
				}
			}
			raw = append(raw, rawChild{
				id:           f.Id,
				name:         f.Name,
				mimeType:     f.MimeType,
				md5Checksum:  f.Md5Checksum,
				modifiedTime: ts,
				size:         f.Size,
				isFolder:     f.MimeType == FolderMimeType,
			})
		}

		pageToken = resp.NextPageToken
		if pageToken == "" {
			break
		}
	}

	return raw
}

// resolveSiblingNames computes the final, on-disk-safe local name for every
// DIRECT child of one folder, as ONE shared namespace across files AND
// folders — a folder and a file with the same Drive-side name are just as
// much a collision as two files with the same name (fixes C2: file task
// pointed at what should have been its own directory; C3: two folders
// silently merging their contents).
//
// Decision order is BY DRIVE FILE ID (ascending), never by listing order:
// Drive does not guarantee stable ordering between two files.list calls. A
// first-come-first-served pass keyed on arrival order meant the loser of a
// collision flipped every time Drive reordered its response — and since this
// tool never deletes local files, every flip left a new orphaned duplicate
// behind, forever (fixes C4).
//
// The dedup key is lower-cased because the destination filesystem may be
// NTFS/APFS, both case-insensitive by default: two Drive names differing
// only by case are DIFFERENT files to Drive but the SAME file on disk there
// — silently overwriting one with the other otherwise (fixes C1).
func resolveSiblingNames(children []rawChild) []resolvedChild {
	sorted := make([]rawChild, len(children))
	copy(sorted, children)
	sort.Slice(sorted, func(i, j int) bool { return sorted[i].id < sorted[j].id })

	used := map[string]bool{}
	resolved := make([]resolvedChild, 0, len(sorted))

	for _, c := range sorted {
		name := localpath.SanitizeComponent(c.name)
		if !c.isFolder {
			if spec, ok := ExportMap[c.mimeType]; ok {
				name += "." + spec.Ext
			}
		}
		// reserve=0: the download-side temp file (see download.go) now has a
		// fixed-size name of its own, so nothing here needs to leave headroom
		// for it anymore.
		name = localpath.TruncateComponent(name, 0)

		key := strings.ToLower(name)
		collided := used[key]
		if collided {
			name = disambiguate(name, c.id, used)
		}
		used[strings.ToLower(name)] = true

		resolved = append(resolved, resolvedChild{rawChild: c, localName: name, collided: collided})
	}

	return resolved
}

// disambiguate appends (progressively more of) the colliding item's Drive
// file ID before the extension until the result is unique within used.
// Starts at 8 chars, matching the tool's pre-existing collision-suffix
// convention ("file.pdf" → "file_1JP7CIBW.pdf"); widens only in the
// astronomically unlikely case an 8-char ID prefix also collides, and falls
// back to a numeric counter if even the full ID does (Drive IDs are unique,
// so this branch is unreachable in practice — it exists only so the loop is
// provably terminating).
func disambiguate(name string, id string, used map[string]bool) string {
	for n := 8; n <= len(id); n += 4 {
		candidate := withIDSuffix(name, id, n)
		if !used[strings.ToLower(candidate)] {
			return candidate
		}
	}
	for i := 2; ; i++ {
		candidate := withIDSuffix(name, id, len(id)) + fmt.Sprintf("-%d", i)
		if !used[strings.ToLower(candidate)] {
			return candidate
		}
	}
}

func withIDSuffix(name, id string, n int) string {
	if n > len(id) {
		n = len(id)
	}
	ext := filepath.Ext(name)
	base := strings.TrimSuffix(name, ext)
	suffixed := base + "_" + id[:n] + ext
	return localpath.TruncateComponent(suffixed, 0)
}

func itemType(isFolder bool) string {
	if isFolder {
		return "dir"
	}
	return "file"
}
