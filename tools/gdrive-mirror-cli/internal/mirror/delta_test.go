package mirror

import "testing"

func TestShouldDownload(t *testing.T) {
	cases := []struct {
		name string
		c    DeltaCheck
		want bool
	}{
		{
			name: "force always downloads even if md5 matches",
			c:    DeltaCheck{Force: true, LocalExists: true, HasRemoteMD5: true, RemoteMD5: "abc", LocalMD5: "abc"},
			want: true,
		},
		{
			name: "local file missing downloads",
			c:    DeltaCheck{LocalExists: false},
			want: true,
		},
		{
			name: "regular file size mismatch forces re-download",
			c:    DeltaCheck{LocalExists: true, RemoteSize: 100, LocalSize: 50},
			want: true,
		},
		{
			name: "regular file size match + md5 match skips",
			c:    DeltaCheck{LocalExists: true, RemoteSize: 100, LocalSize: 100, HasRemoteMD5: true, RemoteMD5: "abc", LocalMD5: "abc"},
			want: false,
		},
		{
			name: "regular file size match + md5 differs downloads",
			c:    DeltaCheck{LocalExists: true, RemoteSize: 100, LocalSize: 100, HasRemoteMD5: true, RemoteMD5: "abc", LocalMD5: "def"},
			want: true,
		},
		{
			name: "export file: size never compared even if it differs, md5 absent, mtime newer local skips",
			c:    DeltaCheck{LocalExists: true, IsExport: true, RemoteSize: 99999, LocalSize: 10, RemoteMTime: 100, LocalMTime: 200},
			want: false,
		},
		{
			name: "export file: mtime local older than remote downloads",
			c:    DeltaCheck{LocalExists: true, IsExport: true, RemoteMTime: 200, LocalMTime: 100},
			want: true,
		},
		{
			name: "export file: mtime equal skips (>=)",
			c:    DeltaCheck{LocalExists: true, IsExport: true, RemoteMTime: 100, LocalMTime: 100},
			want: false,
		},
		{
			name: "no md5, no export flag (edge case), mtime fallback still applies",
			c:    DeltaCheck{LocalExists: true, RemoteSize: 0, LocalSize: 0, RemoteMTime: 500, LocalMTime: 100},
			want: true,
		},
		{
			name: "zero remote size skips size pre-check, falls through to md5",
			c:    DeltaCheck{LocalExists: true, RemoteSize: 0, LocalSize: 100, HasRemoteMD5: true, RemoteMD5: "x", LocalMD5: "x"},
			want: false,
		},
		{
			name: "zero local size (empty local file) skips size pre-check, falls through to md5 match",
			c:    DeltaCheck{LocalExists: true, RemoteSize: 100, LocalSize: 0, HasRemoteMD5: true, RemoteMD5: "x", LocalMD5: "x"},
			want: false,
		},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			if got := ShouldDownload(tc.c); got != tc.want {
				t.Errorf("ShouldDownload(%+v) = %v, want %v", tc.c, got, tc.want)
			}
		})
	}
}
