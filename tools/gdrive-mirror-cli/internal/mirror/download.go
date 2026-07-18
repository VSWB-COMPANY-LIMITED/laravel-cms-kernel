package mirror

import (
	"context"
	"crypto/md5"
	"crypto/sha256"
	"encoding/hex"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"time"
)

// downloadItem fetches one Drive file (export for Google-native mimeTypes,
// raw media otherwise) and writes it to targetPath. Downloads go to a
// temp file first and are renamed into place only on full success, so a
// crash/network-drop mid-transfer never leaves a truncated file at the real
// path (self-heals via delta-check next run either way, but atomic rename
// avoids ever exposing a partial file in the meantime).
func (s *Syncer) downloadItem(ctx context.Context, targetPath string, item Item, exportSpec *ExportSpec) error {
	if err := os.MkdirAll(filepath.Dir(targetPath), 0o755); err != nil {
		return err
	}

	var resp *http.Response
	var err error
	if exportSpec != nil {
		resp, err = s.srv.Files.Export(item.ID, exportSpec.Mime).Context(ctx).Download()
	} else {
		resp, err = s.srv.Files.Get(item.ID).SupportsAllDrives(true).Context(ctx).Download()
	}
	if err != nil {
		return err
	}
	defer resp.Body.Close()

	tmpPath := tempDownloadPath(targetPath, s.runID)
	f, err := os.Create(tmpPath)
	if err != nil {
		return err
	}

	_, copyErr := io.Copy(f, resp.Body)
	closeErr := f.Close()
	if copyErr != nil {
		os.Remove(tmpPath)
		return copyErr
	}
	if closeErr != nil {
		os.Remove(tmpPath)
		return closeErr
	}

	if err := os.Rename(tmpPath, targetPath); err != nil {
		os.Remove(tmpPath)
		return err
	}

	if item.ModifiedTime > 0 {
		mtime := time.Unix(item.ModifiedTime, 0)
		_ = os.Chtimes(targetPath, mtime, mtime)
	}
	return nil
}

// tempDownloadPath returns the atomic-write scratch path for targetPath.
// Its filename has a FIXED byte length regardless of targetPath's own
// length — a hash of targetPath, not targetPath itself, is what makes the
// name unique. The previous scheme (targetPath + ".tmp-" + runID) added 13+
// bytes on TOP OF an already-255-byte-limited component, which meant a
// target name already near the limit failed outright the moment a download
// was attempted (finding L1). Hashing sidesteps the problem entirely: no
// budget needs to be reserved for this suffix anymore (see
// localpath.TruncateComponent's reserve=0 call sites).
func tempDownloadPath(targetPath string, runID string) string {
	sum := sha256.Sum256([]byte(targetPath))
	name := "." + hex.EncodeToString(sum[:])[:8] + "-" + runID + ".tmp"
	return filepath.Join(filepath.Dir(targetPath), name)
}

// evaluateLocal reads whatever local filesystem state ShouldDownload() needs
// to decide skip-vs-download for t. A local read error (I/O error on a
// disconnected drive, etc.) is returned as-is — the caller treats it as
// "could not verify the local copy" and forces a re-download (self-heal),
// mirroring the try/catch around the delta-check in GDriveMirrorSync.php
// (~L910-940): 1 unreadable file must never abort the whole run.
func (s *Syncer) evaluateLocal(t fileTask) (DeltaCheck, error) {
	dc := DeltaCheck{
		Force:        s.cfg.Force,
		IsExport:     t.exportSpec != nil,
		RemoteSize:   t.item.Size,
		HasRemoteMD5: t.item.MD5Checksum != "",
		RemoteMD5:    t.item.MD5Checksum,
		RemoteMTime:  t.item.ModifiedTime,
	}
	if s.cfg.Force {
		return dc, nil
	}

	fi, err := os.Stat(t.targetPath)
	if err != nil {
		if os.IsNotExist(err) {
			return dc, nil // LocalExists stays false → ShouldDownload → true
		}
		return dc, err
	}
	dc.LocalExists = true
	dc.LocalSize = fi.Size()
	dc.LocalMTime = fi.ModTime().Unix()

	if !dc.IsExport && dc.RemoteSize > 0 && dc.LocalSize > 0 && dc.LocalSize != dc.RemoteSize {
		return dc, nil // size mismatch already decides the outcome — skip hashing
	}
	if dc.HasRemoteMD5 {
		sum, err := md5File(t.targetPath)
		if err != nil {
			return dc, err
		}
		dc.LocalMD5 = sum
	}
	return dc, nil
}

func md5File(path string) (string, error) {
	f, err := os.Open(path)
	if err != nil {
		return "", err
	}
	defer f.Close()

	h := md5.New()
	if _, err := io.Copy(h, f); err != nil {
		return "", err
	}
	return hex.EncodeToString(h.Sum(nil)), nil
}
