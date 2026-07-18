// Package classify ports the pure-static error classification helpers from
// Dev\Kernel\Commands\GDriveMirrorSync (GDriveMirrorSync.php) 1:1. Keep the
// decision logic byte-for-byte equivalent to the PHP source — this is the
// battle-tested "brain" of the sync tool, not a place to improvise.
package classify

import (
	"encoding/json"
	"regexp"
	"strconv"
	"strings"
)

// MaxConsecutiveLocalFsErrors is the circuit-breaker threshold: this many
// consecutive local filesystem errors (no successful item in between) aborts
// the whole run — the destination disk is almost certainly unmounted,
// read-only, or dead.
const MaxConsecutiveLocalFsErrors = 20

// ListingShrinkAbortRatio: if a folder's current item count drops below this
// ratio of the previous run's count, abort that folder instead of mirroring
// over a possibly-truncated listing.
const ListingShrinkAbortRatio = 0.8

// Result mirrors classifyDriveError()'s return shape
// (array{category: string, retryable: bool, reason: ?string}).
// Reason is "" when unknown (PHP null).
type Result struct {
	Category  string
	Retryable bool
	Reason    string
}

type driveErrorBody struct {
	Error struct {
		Code   int `json:"code"`
		Errors []struct {
			Reason string `json:"reason"`
		} `json:"errors"`
	} `json:"error"`
}

// permanentReasons — ALLOWLIST of `reason` values known to NEVER self-heal.
// Everything NOT in this list defaults to retryable=true (asymmetric-risk
// principle: misclassifying "permanent" silently drops a file forever, which
// is far worse than a wasted ~14s of retry backoff on a misclassified
// "retryable"). Keep in sync with GDriveMirrorSync::classifyDriveError().
var permanentReasons = map[string]string{
	"exportSizeLimitExceeded":     "Export size limit",
	"cannotExportFile":            "Not exportable (locked/unsupported)",
	"fileNotExportable":           "Not exportable (locked/unsupported)",
	"notFound":                    "Not found",
	"insufficientFilePermissions": "Permission denied",
	"appNotAuthorizedToFile":      "Permission denied",
	"domainPolicy":                "Permission denied",
	"forbidden":                   "Permission denied",
	"fileNotDownloadable":         "Not downloadable (Docs Editors/shortcut)",
	"cannotDownloadFile":          "Not downloadable (chủ tắt quyền tải)",
}

var transientReasons = map[string]string{
	"rateLimitExceeded":        "Quota / rate limit",
	"userRateLimitExceeded":    "Quota / rate limit",
	"sharingRateLimitExceeded": "Quota / rate limit",
	"dailyLimitExceeded":       "Quota / rate limit",
	"quotaExceeded":            "Quota / rate limit",
	"backendError":             "Server error (5xx)",
	"internalError":            "Server error (5xx)",
}

// DriveError classifies a Drive API error message (or a raw filesystem/
// network error string) into a category + retryable/permanent verdict.
//
// msg is expected to be the raw JSON error body when it came from the Drive
// API (matches googleapi.Error.Body in the Go SDK — same contract as
// Google\Service\Exception::getMessage() in the PHP client), or a plain
// string for non-API errors (network/curl-equivalent, filesystem).
func DriveError(msg string) Result {
	if IsInvalidLocalName(msg) {
		// Checked before anything else: a garbage/oversized/traversal-attempt
		// NAME is a permanent, Drive-side data problem — must never be
		// misread as a Drive API failure (retried) or a disk-health failure
		// (fed to the local-fs circuit breaker). See IsInvalidLocalName doc.
		return Result{Category: "Invalid or too-long local name", Retryable: false, Reason: "invalidLocalName"}
	}

	var body driveErrorBody
	var reason string
	var code int
	if err := json.Unmarshal([]byte(msg), &body); err == nil {
		if len(body.Error.Errors) > 0 {
			reason = body.Error.Errors[0].Reason
		}
		code = body.Error.Code
	}

	if reason != "" {
		if cat, ok := permanentReasons[reason]; ok {
			return Result{Category: cat, Retryable: false, Reason: reason}
		}
		if cat, ok := transientReasons[reason]; ok {
			return Result{Category: cat, Retryable: true, Reason: reason}
		}
	}

	switch code {
	case 500, 502, 503, 504:
		return Result{Category: "Server error (5xx)", Retryable: true, Reason: reason}
	case 403:
		// 403 with a reason NOT in the permanent allowlist above (or no reason
		// parsed at all) is not proven permanent — Drive uses 403 for both
		// rate-limiting (dailyLimitExceeded, sharingRateLimitExceeded…) and real
		// permission errors, and Google may add new reasons at any time.
		return Result{Category: "Permission denied", Retryable: true, Reason: reason}
	}

	// Not valid JSON (network/curl-equivalent raw string) → fallback heuristic
	// string-match, same order as the PHP source (rate/quota MUST be checked
	// before the bare "403"/"forbidden" branch — Drive returns rate-limit under
	// both 429 and 403).
	m := strings.ToLower(msg)
	switch {
	case strings.Contains(m, "exportsizelimitexceeded") || strings.Contains(m, "too large to export"):
		return Result{Category: "Export size limit", Retryable: false, Reason: "exportSizeLimitExceeded"}
	case strings.Contains(m, "cannotexportfile") || strings.Contains(m, "not exportable"):
		return Result{Category: "Not exportable (locked/unsupported)", Retryable: false, Reason: "cannotExportFile"}
	case strings.Contains(m, "404") || strings.Contains(m, "not found") || strings.Contains(m, "notfound"):
		return Result{Category: "Not found", Retryable: false, Reason: "notFound"}
	case strings.Contains(m, "429") || strings.Contains(m, "rate") || strings.Contains(m, "quota") || strings.Contains(m, "userratelimit"):
		return Result{Category: "Quota / rate limit", Retryable: true}
	case strings.Contains(m, "403") || strings.Contains(m, "forbidden") || strings.Contains(m, "permission") || strings.Contains(m, "insufficient"):
		// retryable=true: a bare 403 string (no parseable reason) does not prove
		// permanent. Only the known allowlisted reasons above may skip outright.
		return Result{Category: "Permission denied", Retryable: true}
	case strings.Contains(m, "timeout") || strings.Contains(m, "timed out") || strings.Contains(m, "curl error") || strings.Contains(m, "connection"):
		return Result{Category: "Network / timeout", Retryable: true}
	case strings.Contains(m, "500") || strings.Contains(m, "502") || strings.Contains(m, "503") || strings.Contains(m, "internal error") || strings.Contains(m, "backenderror"):
		return Result{Category: "Server error (5xx)", Retryable: true}
	}

	// Unknown error → default retryable=true (safer than silently dropping a
	// file — most unknown errors in practice are transient network hiccups).
	return Result{Category: "Other", Retryable: true}
}

// invalidNameMarkers catch OS errors about a NAME itself being malformed —
// too long (ENAMETOOLONG) or otherwise rejected by the OS — as distinct from
// a disk-health problem. This distinction matters: a single Drive item with
// a garbage/oversized name must never be able to trip the local-fs circuit
// breaker and abort an otherwise-healthy run. Before this tool sanitized/
// truncated every name unconditionally (localpath package), a "dirty" Drive
// name could reach the OS raw and get misclassified as evidence the
// destination disk itself was failing.
var invalidNameMarkers = []string{
	"file name too long", // ENAMETOOLONG.Error() on macOS/Linux
	"name too long",
	"enametoolong",
}

// safeJoinMarker recognizes localpath.SafeJoin's own traversal-rejection
// error text (see localpath.SafeJoin) — a Drive item name containing "/" or
// ".." that would otherwise escape --path gets the same "permanent, not a
// disk problem" treatment as an invalid/too-long name.
const safeJoinMarker = "localpath.safejoin"

// IsInvalidLocalName reports whether msg describes a Drive item whose NAME
// itself is unusable locally (too long, structurally invalid/EINVAL, or a
// rejected path-traversal attempt) — always permanent, and never treated as
// evidence of a failing local disk (mirrors the asymmetric-risk reasoning in
// IsLocalFsError's doc: this classification exists so ONE badly-named Drive
// item can never abort the whole run and get blamed on the hardware).
func IsInvalidLocalName(msg string) bool {
	if msg == "" {
		return false
	}
	m := strings.ToLower(msg)

	if strings.Contains(m, safeJoinMarker) {
		return true
	}
	for _, marker := range invalidNameMarkers {
		if strings.Contains(m, marker) {
			return true
		}
	}
	// Bare EINVAL ("invalid argument") is too generic to trust alone — only
	// counts when paired with an absolute path, same guard IsLocalFsError
	// applies to its bare "permission denied" check below.
	if strings.Contains(m, "invalid argument") && absPathAfterMarker.MatchString(msg) {
		return true
	}
	return false
}

var hardFsMarkers = []string{
	"input/output error",
	"errno=5",
	"read-only file system",
	"no space left",
}

var fsFunctionMarkers = []string{
	"mkdir(", "fopen(", "md5_file(", "filesize(", "file_put_contents(",
	"file_get_contents(", "rename(", "unlink(", "copy(", "chmod(", "touch(",
	"is_writable(", "is_readable(", "rmdir(", "stream_copy_to_stream(", "fwrite(",
}

// absPathAfterMarker matches a Unix absolute path preceded by start-of-string,
// whitespace, '(' or ':' — same pattern as the PHP regex
// `#(?:^|[\s(:])/[\w./\-]+#`.
var absPathAfterMarker = regexp.MustCompile(`(?:^|[\s(:])/[\w./\-]+`)

// IsLocalFsError recognizes LOCAL filesystem errors (mkdir/fopen/md5_file/
// filesize failures, disk full, read-only fs, I/O error from a disconnected
// external drive…) as distinct from Drive API errors. Asymmetric-risk
// principle: misclassifying a Drive error as local-fs aborts the whole run
// wrongly (severe); misclassifying local-fs as Drive only wastes a few retries
// (mild) — so when unsure, this always leans toward false.
func IsLocalFsError(msg string) bool {
	if msg == "" {
		return false
	}
	if IsInvalidLocalName(msg) {
		// Explicit guard (belt-and-suspenders): a malformed/too-long NAME is
		// never disk-health evidence, so it must never feed the local-fs
		// circuit breaker — even if hardFsMarkers/fsFunctionMarkers below is
		// later extended in a way that would otherwise overlap.
		return false
	}
	m := strings.ToLower(msg)

	for _, marker := range hardFsMarkers {
		if strings.Contains(m, marker) {
			return true
		}
	}
	for _, marker := range fsFunctionMarkers {
		if strings.Contains(m, marker) {
			return true
		}
	}

	// Bare "permission denied" (no PHP-style function-name marker) only counts
	// as local-fs when paired with an absolute path — a strong signal this is
	// an OS-level error, not a Drive 403 (which also uses this phrase).
	if strings.Contains(m, "permission denied") && absPathAfterMarker.MatchString(msg) {
		return true
	}

	return false
}

// ShouldAbortOnLocalFsErrors reports whether the consecutive local-fs error
// count has crossed the circuit-breaker threshold.
func ShouldAbortOnLocalFsErrors(consecutive int, threshold int) bool {
	return consecutive >= threshold
}

// ShouldAbortOnShrink reports whether a folder's current item count dropped
// too far below the previous run's count (suspected truncated listing).
// prevCount == nil (no prior state) or 0 (nothing to shrink from) never abort.
func ShouldAbortOnShrink(prevCount *int, currentCount int, ratio float64) bool {
	if prevCount == nil || *prevCount == 0 {
		return false
	}
	return float64(currentCount) < float64(*prevCount)*ratio
}

// HumanSize renders a byte count like PHP's humanSize() (e.g. "12.3 MB").
func HumanSize(bytes int64) string {
	if bytes <= 0 {
		return "-"
	}
	units := []string{"B", "KB", "MB", "GB", "TB"}
	i := 0
	f := float64(bytes)
	for f >= 1024 && i < len(units)-1 {
		f /= 1024
		i++
	}
	return strconv.FormatFloat(f, 'f', 1, 64) + " " + units[i]
}
