package classify

import "testing"

func TestDriveError_PermanentReasons(t *testing.T) {
	cases := []struct {
		name         string
		body         string
		wantCategory string
		wantReason   string
	}{
		{
			name:         "exportSizeLimitExceeded",
			body:         `{"error":{"code":403,"message":"This file is too large to export.","errors":[{"domain":"global","reason":"exportSizeLimitExceeded","message":"This file is too large to export."}]}}`,
			wantCategory: "Export size limit",
			wantReason:   "exportSizeLimitExceeded",
		},
		{
			name:         "cannotDownloadFile",
			body:         `{"error":{"code":403,"message":"This file cannot be downloaded by the user.","errors":[{"domain":"global","reason":"cannotDownloadFile","message":"This file cannot be downloaded by the user."}]}}`,
			wantCategory: "Not downloadable (chủ tắt quyền tải)",
			wantReason:   "cannotDownloadFile",
		},
		{
			name:         "cannotExportFile",
			body:         `{"error":{"code":403,"message":"Export not supported.","errors":[{"domain":"global","reason":"cannotExportFile","message":"Export not supported."}]}}`,
			wantCategory: "Not exportable (locked/unsupported)",
			wantReason:   "cannotExportFile",
		},
		{
			name:         "insufficientFilePermissions",
			body:         `{"error":{"code":403,"message":"The user does not have sufficient permissions for this file.","errors":[{"domain":"global","reason":"insufficientFilePermissions"}]}}`,
			wantCategory: "Permission denied",
			wantReason:   "insufficientFilePermissions",
		},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			got := DriveError(tc.body)
			if got.Retryable {
				t.Errorf("expected non-retryable (permanent), got retryable=true")
			}
			if got.Category != tc.wantCategory {
				t.Errorf("category = %q, want %q", got.Category, tc.wantCategory)
			}
			if got.Reason != tc.wantReason {
				t.Errorf("reason = %q, want %q", got.Reason, tc.wantReason)
			}
		})
	}
}

func TestDriveError_RetryableRateLimit(t *testing.T) {
	cases := []struct {
		name string
		body string
	}{
		{
			name: "rateLimitExceeded 403",
			body: `{"error":{"code":403,"message":"Rate Limit Exceeded","errors":[{"domain":"usageLimits","reason":"rateLimitExceeded"}]}}`,
		},
		{
			name: "userRateLimitExceeded 403",
			body: `{"error":{"code":403,"message":"User Rate Limit Exceeded","errors":[{"domain":"usageLimits","reason":"userRateLimitExceeded"}]}}`,
		},
		{
			name: "429 no reason parsed",
			body: `{"error":{"code":429,"message":"Too Many Requests","errors":[]}}`,
		},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			got := DriveError(tc.body)
			if !got.Retryable {
				t.Errorf("expected retryable=true, got false (category=%q)", got.Category)
			}
		})
	}
}

func TestDriveError_Bare403NotPermanent(t *testing.T) {
	// 403 with a reason NOT in the permanent allowlist must stay retryable —
	// Drive overloads 403 for rate-limits too (dailyLimitExceeded etc.).
	got := DriveError(`{"error":{"code":403,"message":"Some new reason Google added later","errors":[{"domain":"global","reason":"someBrandNewReason"}]}}`)
	if !got.Retryable {
		t.Errorf("expected retryable=true for unknown 403 reason, got false")
	}
}

func TestDriveError_5xx(t *testing.T) {
	got := DriveError(`{"error":{"code":503,"message":"Backend Error"}}`)
	if !got.Retryable {
		t.Errorf("expected retryable=true for 503")
	}
	if got.Category != "Server error (5xx)" {
		t.Errorf("category = %q, want Server error (5xx)", got.Category)
	}
}

func TestDriveError_EmptyString(t *testing.T) {
	got := DriveError("")
	if !got.Retryable {
		t.Errorf("expected retryable=true (default/Other) for empty string")
	}
	if got.Category != "Other" {
		t.Errorf("category = %q, want Other", got.Category)
	}
}

func TestDriveError_FallbackStringMatch(t *testing.T) {
	cases := []struct {
		name          string
		msg           string
		wantRetryable bool
		wantCategory  string
	}{
		{"curl timeout", "cURL error 28: Operation timed out after 30000 milliseconds", true, "Network / timeout"},
		{"connection refused", "Connection refused by remote host", true, "Network / timeout"},
		{"plain 500", "Internal Server Error (HTTP 500)", true, "Server error (5xx)"},
		{"plain rate", "429 Too Many Requests: userRateLimitExceeded", true, "Quota / rate limit"},
		{"plain forbidden no path", "403 Forbidden", true, "Permission denied"},
		{"plain not found", "404 Not Found", false, "Not found"},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			got := DriveError(tc.msg)
			if got.Retryable != tc.wantRetryable {
				t.Errorf("retryable = %v, want %v", got.Retryable, tc.wantRetryable)
			}
			if got.Category != tc.wantCategory {
				t.Errorf("category = %q, want %q", got.Category, tc.wantCategory)
			}
		})
	}
}

func TestIsLocalFsError(t *testing.T) {
	cases := []struct {
		name string
		msg  string
		want bool
	}{
		{"mkdir php-style io error", "mkdir(): Input/output error", true},
		{"errno=5", "md5_file(/Volumes/WD-DATA1/foo.pdf): Read of 8192 bytes failed with errno=5 Input/output error", true},
		{"read-only fs", "fopen(/Volumes/WD-DATA1/x): failed to open stream: Read-only file system", true},
		{"no space left", "file_put_contents(): write failed: No space left on device", true},
		{"go PathError io error", "open /Volumes/WD-DATA1/foo.pdf: input/output error", true},
		{"go PathError read-only", "mkdir /Volumes/WD-DATA1/sub: read-only file system", true},
		{"go PathError permission with abs path", "mkdir /Volumes/WD-DATA1/sub: permission denied", true},
		{"drive 403 json body", `{"error":{"code":403,"message":"The user does not have sufficient permissions for this file.","errors":[{"reason":"insufficientFilePermissions"}]}}`, false},
		{"bare permission denied no path", "Permission denied", false},
		{"empty string", "", false},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			if got := IsLocalFsError(tc.msg); got != tc.want {
				t.Errorf("IsLocalFsError(%q) = %v, want %v", tc.msg, got, tc.want)
			}
		})
	}
}

func TestIsInvalidLocalName(t *testing.T) {
	cases := []struct {
		name string
		msg  string
		want bool
	}{
		{"go PathError ENAMETOOLONG", "open /some/very/long/path/name.docx: file name too long", true},
		{"bare enametoolong token", "mkdir failed: ENAMETOOLONG", true},
		{"go PathError EINVAL with abs path", "open /Volumes/WD-DATA1/foo: invalid argument", true},
		{"bare invalid argument no path", "invalid argument", false},
		{"safeJoin traversal rejection", `localpath.SafeJoin: path escapes root (root="/mnt/x" relPath="../../etc/passwd" resolved="/etc/passwd")`, true},
		{"unrelated io error stays false", "mkdir(): Input/output error", false},
		{"unrelated drive json stays false", `{"error":{"code":403,"message":"forbidden","errors":[{"reason":"insufficientFilePermissions"}]}}`, false},
		{"empty string", "", false},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			if got := IsInvalidLocalName(tc.msg); got != tc.want {
				t.Errorf("IsInvalidLocalName(%q) = %v, want %v", tc.msg, got, tc.want)
			}
		})
	}
}

func TestDriveError_InvalidLocalNameIsPermanent(t *testing.T) {
	// Direction 1: DriveError must classify these as permanent/invalidLocalName.
	cases := []string{
		"open /some/very/long/path/name.docx: file name too long",
		`localpath.SafeJoin: path escapes root (root="/mnt/x" relPath="../escape" resolved="/escape")`,
	}
	for _, msg := range cases {
		got := DriveError(msg)
		if got.Retryable {
			t.Errorf("DriveError(%q).Retryable = true, want false (permanent)", msg)
		}
		if got.Reason != "invalidLocalName" {
			t.Errorf("DriveError(%q).Reason = %q, want %q", msg, got.Reason, "invalidLocalName")
		}
	}
}

func TestIsLocalFsError_ExcludesInvalidLocalName(t *testing.T) {
	// Direction 2: these same messages must NOT be counted as local-fs/disk
	// errors — a badly-named Drive item is not evidence the disk is dying,
	// and must never feed the consecutiveLocalFsErrors circuit breaker.
	cases := []string{
		"open /some/very/long/path/name.docx: file name too long",
		"mkdir failed: ENAMETOOLONG",
		"open /Volumes/WD-DATA1/foo: invalid argument",
		`localpath.SafeJoin: path escapes root (root="/mnt/x" relPath="../escape" resolved="/escape")`,
	}
	for _, msg := range cases {
		if IsLocalFsError(msg) {
			t.Errorf("IsLocalFsError(%q) = true, want false (must be classified as invalidLocalName, not a disk error)", msg)
		}
	}
}

func TestShouldAbortOnLocalFsErrors(t *testing.T) {
	cases := []struct {
		consecutive int
		want        bool
	}{
		{0, false},
		{19, false},
		{20, true},
		{21, true},
	}
	for _, tc := range cases {
		if got := ShouldAbortOnLocalFsErrors(tc.consecutive, MaxConsecutiveLocalFsErrors); got != tc.want {
			t.Errorf("ShouldAbortOnLocalFsErrors(%d) = %v, want %v", tc.consecutive, got, tc.want)
		}
	}
}

func TestShouldAbortOnShrink(t *testing.T) {
	two := 2
	zero := 0
	hundred := 100
	cases := []struct {
		name    string
		prev    *int
		current int
		want    bool
	}{
		{"no prior state", nil, 5, false},
		{"prev zero", &zero, 5, false},
		{"tiny prev, no shrink", &two, 2, false},
		{"exactly at 80pct threshold, not below", &hundred, 80, false},
		{"just below threshold", &hundred, 79, true},
		{"drastic shrink to zero", &hundred, 0, true},
		{"grew", &hundred, 150, false},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			if got := ShouldAbortOnShrink(tc.prev, tc.current, ListingShrinkAbortRatio); got != tc.want {
				t.Errorf("ShouldAbortOnShrink(%v, %d) = %v, want %v", tc.prev, tc.current, got, tc.want)
			}
		})
	}
}
