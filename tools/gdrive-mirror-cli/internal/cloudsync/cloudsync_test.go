package cloudsync

import (
	"path/filepath"
	"testing"
)

// TestDetect_OneDriveEnvVar reproduces the real incident: --path set to
// exactly the OneDrive env var's tracked root (as PowerShell's
// `--path="C:\Users\KUN\OneDrive - VISUAL WEBER COMPANY LIMITED"` did) must
// be caught via the authoritative env-var check, not just the substring
// fallback.
func TestDetect_OneDriveEnvVar(t *testing.T) {
	root := `C:\Users\KUN\OneDrive - VISUAL WEBER COMPANY LIMITED`
	t.Setenv("OneDrive", root)

	m, ok := Detect(root)
	if !ok {
		t.Fatalf("expected a match for the exact OneDrive root")
	}
	if m.Provider != "OneDrive" || !m.FromEnv {
		t.Fatalf("got %+v, want OneDrive match FromEnv=true", m)
	}
}

// TestDetect_OneDriveEnvVar_NestedSubfolder covers the more common case:
// --path is a subfolder INSIDE the tracked root, not the root itself.
func TestDetect_OneDriveEnvVar_NestedSubfolder(t *testing.T) {
	root := `C:\Users\KUN\OneDrive - VISUAL WEBER COMPANY LIMITED`
	t.Setenv("OneDrive", root)

	nested := filepath.Join(root, "GDrive-Mirror", "SomeFolder")
	m, ok := Detect(nested)
	if !ok || m.Provider != "OneDrive" {
		t.Fatalf("expected OneDrive match for nested path, got %+v ok=%v", m, ok)
	}
}

// TestDetect_UnrelatedEnvValueDoesNotFalsePositive: a machine where the env
// var happens to be set but --path is somewhere else entirely must not
// match on that var (only the independent substring fallback could still
// catch it, and only if the path itself mentions the provider name).
func TestDetect_UnrelatedEnvValueDoesNotFalsePositive(t *testing.T) {
	t.Setenv("OneDrive", `C:\Users\KUN\OneDrive - VISUAL WEBER COMPANY LIMITED`)

	m, ok := Detect(`D:\Backups\GDriveMirror`)
	if ok {
		t.Fatalf("expected no match for an unrelated plain path, got %+v", m)
	}
}

// TestDetect_PlainLocalPath_NoMatch is the sanity baseline: an ordinary
// local destination with none of the env vars set must never be flagged.
func TestDetect_PlainLocalPath_NoMatch(t *testing.T) {
	t.Setenv("OneDrive", "")
	t.Setenv("OneDriveConsumer", "")
	t.Setenv("OneDriveCommercial", "")

	m, ok := Detect(filepath.FromSlash("/Volumes/WD-DATA1/GDrive-Mirror"))
	if ok {
		t.Fatalf("expected no match for a plain local path, got %+v", m)
	}
}

// TestDetect_MacOSCloudStorageSubstring covers the substring fallback for
// macOS's fixed cloud-sync mount point, used when an env var isn't
// available/applicable (e.g. Google Drive desktop on macOS sets no such
// var).
func TestDetect_MacOSCloudStorageSubstring(t *testing.T) {
	t.Setenv("OneDrive", "")
	t.Setenv("OneDriveConsumer", "")
	t.Setenv("OneDriveCommercial", "")

	m, ok := Detect("/Users/eugene/Library/CloudStorage/GoogleDrive-me@example.com/My Drive/Mirror")
	if !ok || m.Provider != "Google Drive" {
		t.Fatalf("expected Google Drive substring match, got %+v ok=%v", m, ok)
	}
}

// TestDetect_DropboxSubstring covers the plain-substring fallback for a
// client with no dedicated env var this package checks.
func TestDetect_DropboxSubstring(t *testing.T) {
	t.Setenv("OneDrive", "")
	m, ok := Detect(filepath.FromSlash("/Users/eugene/Dropbox/GDrive-Mirror"))
	if !ok || m.Provider != "Dropbox" {
		t.Fatalf("expected Dropbox substring match, got %+v ok=%v", m, ok)
	}
}

func TestDetect_CaseInsensitive(t *testing.T) {
	root := `C:\Users\KUN\OneDrive - VISUAL WEBER COMPANY LIMITED`
	t.Setenv("OneDrive", root)

	m, ok := Detect(`c:\users\kun\onedrive - visual weber company limited\sub`)
	if !ok || m.Provider != "OneDrive" {
		t.Fatalf("expected case-insensitive match, got %+v ok=%v", m, ok)
	}
}
