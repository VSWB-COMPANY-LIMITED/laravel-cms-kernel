package mirror

import (
	"context"
	"net/http"
	"net/http/httptest"
	"path/filepath"
	"strings"
	"sync"
	"testing"

	"github.com/vswb/gdrive-mirror/internal/report"
	"google.golang.org/api/drive/v3"
	"google.golang.org/api/option"
)

// TestRun_IncrementalTokenTakenBeforeListing: the Changes API start page
// token must be requested BEFORE the first files.list call, and that exact
// token is what gets persisted. Taking it after the listing would lose any
// change made to an already-listed folder while a large tree is listed.
func TestRun_IncrementalTokenTakenBeforeListing(t *testing.T) {
	var mu sync.Mutex
	var calls []string
	tokenServed := 0

	ts := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		mu.Lock()
		defer mu.Unlock()
		w.Header().Set("Content-Type", "application/json")
		switch {
		case strings.HasSuffix(r.URL.Path, "/changes/startPageToken"):
			tokenServed++
			calls = append(calls, "token")
			if tokenServed == 1 {
				_, _ = w.Write([]byte(`{"startPageToken":"before-listing"}`))
			} else {
				_, _ = w.Write([]byte(`{"startPageToken":"after-listing"}`))
			}
		case strings.HasSuffix(r.URL.Path, "/files"):
			calls = append(calls, "list")
			_, _ = w.Write([]byte(`{"files":[{"id":"f1","name":"a.txt","mimeType":"text/plain","size":"1","md5Checksum":"x"}]}`))
		case strings.Contains(r.URL.Path, "/files/"):
			calls = append(calls, "get")
			_, _ = w.Write([]byte(`{"name":"Root"}`))
		default:
			http.NotFound(w, r)
		}
	}))
	defer ts.Close()

	ctx := context.Background()
	srv, err := drive.NewService(ctx, option.WithEndpoint(ts.URL+"/drive/v3/"), option.WithHTTPClient(ts.Client()))
	if err != nil {
		t.Fatal(err)
	}

	root := t.TempDir()
	s := &Syncer{
		cfg: Config{
			FolderID:        "root-id",
			Path:            filepath.Join(root, "mirror"),
			Incremental:     true,
			DryRun:          false,
			Concurrency:     1,
			ListConcurrency: 1,
			Limit:           -1,
		},
		srv:   srv,
		runID: "t",
	}
	// Only the ordering of the listing vs the token matters here; the
	// download of a.txt against the fake server may fail and is irrelevant.
	_, _ = s.Run(ctx)

	mu.Lock()
	defer mu.Unlock()
	firstToken, firstList := -1, -1
	for i, c := range calls {
		if c == "token" && firstToken < 0 {
			firstToken = i
		}
		if c == "list" && firstList < 0 {
			firstList = i
		}
	}
	if firstToken < 0 || firstList < 0 {
		t.Fatalf("expected both token and list calls, got %v", calls)
	}
	if firstToken > firstList {
		t.Fatalf("start page token requested after listing began: %v", calls)
	}

	m := report.ReadIncrementalManifest(s.incrementalStateDir(), report.FolderTag([]string{"root-id"}))
	if m == nil {
		t.Fatalf("manifest not written; calls=%v", calls)
	}
	if m.StartPageToken != "before-listing" {
		t.Fatalf("persisted token = %q, want the one taken before listing", m.StartPageToken)
	}
}
