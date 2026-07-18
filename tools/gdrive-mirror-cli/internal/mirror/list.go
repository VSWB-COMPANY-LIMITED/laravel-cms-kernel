package mirror

import (
	"context"
	"fmt"
	"strings"
	"time"

	"google.golang.org/api/drive/v3"
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

// fetchFolderChildren lists one level (paginated) of folderID and recurses
// into any subfolders. Separated from ListFolderRecursive so the
// verify-on-zero retry above can call it again without re-recursing into
// itself.
func (s *Syncer) fetchFolderChildren(ctx context.Context, folderID string, relativePath string) ([]Item, error) {
	var items []Item
	pageToken := ""
	query := fmt.Sprintf("'%s' in parents and trashed = false", strings.ReplaceAll(folderID, "'", "\\'"))

	label := relativePath
	if label == "" {
		label = "(root)"
	}

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
			// Stop paginating and return whatever was gathered so far rather than
			// propagating a fatal error that would kill the entire sync (BUG B in
			// the PHP source: an uncaught listing error used to nuke the whole run,
			// including subtrees that had already synced fine).
			s.logError("Listing failed for %s after %d attempt(s): %s", label, outcome.attempts, outcome.errMsg)
			break
		}
		if resp == nil {
			break
		}

		for _, f := range resp.Files {
			isFolder := f.MimeType == FolderMimeType
			childPath := f.Name
			if relativePath != "" {
				childPath = relativePath + "/" + f.Name
			}

			var ts int64
			if f.ModifiedTime != "" {
				if t, err := time.Parse(time.RFC3339, f.ModifiedTime); err == nil {
					ts = t.Unix()
				}
			}

			items = append(items, Item{
				Type:         itemType(isFolder),
				Path:         childPath,
				ID:           f.Id,
				MimeType:     f.MimeType,
				MD5Checksum:  f.Md5Checksum,
				ModifiedTime: ts,
				Size:         f.Size,
			})

			if isFolder {
				children, err := s.ListFolderRecursive(ctx, f.Id, childPath)
				if err != nil {
					return items, err
				}
				items = append(items, children...)
			}
		}

		pageToken = resp.NextPageToken
		if pageToken == "" {
			break
		}
	}

	return items, nil
}

func itemType(isFolder bool) string {
	if isFolder {
		return "dir"
	}
	return "file"
}
