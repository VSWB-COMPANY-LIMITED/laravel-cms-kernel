package mirror

// DeltaCheck holds every input the delta-sync decision needs. IsExport marks
// a Google-native file (Docs/Sheets/Slides/Drawing/Script) — its "remote
// size" is the native size, not the exported-format size, so size can't be
// compared for those (would falsely force a re-download every run).
// HasRemoteMD5 mirrors "regular file with an md5Checksum from Drive" — native
// files never have one, hence the mtime fallback branch.
type DeltaCheck struct {
	Force        bool
	LocalExists  bool
	IsExport     bool
	RemoteSize   int64
	LocalSize    int64
	HasRemoteMD5 bool
	RemoteMD5    string
	LocalMD5     string
	RemoteMTime  int64
	LocalMTime   int64
}

// ShouldDownload ports the delta-sync decision from GDriveMirrorSync::handle()
// (~L895-941) 1:1:
//
//	--force                                    → download
//	local file doesn't exist yet               → download
//	regular file & size(local) ≠ size(remote)  → download (catches truncation)
//	has MD5: md5 matches                       → skip; differs → download
//	no MD5 (Google Native): mtime(local) >= mtime(remote) → skip; else → download
func ShouldDownload(c DeltaCheck) bool {
	if c.Force || !c.LocalExists {
		return true
	}
	if !c.IsExport && c.RemoteSize > 0 && c.LocalSize > 0 && c.LocalSize != c.RemoteSize {
		return true
	}
	if c.HasRemoteMD5 {
		return c.LocalMD5 != c.RemoteMD5
	}
	return c.LocalMTime < c.RemoteMTime
}
