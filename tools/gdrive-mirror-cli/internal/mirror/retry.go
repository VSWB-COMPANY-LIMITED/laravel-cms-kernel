package mirror

import (
	"context"
	"errors"
	"time"

	"google.golang.org/api/googleapi"

	"github.com/vswb/gdrive-mirror/internal/classify"
)

// retryOutcome reports how a withRetry call ended. errMsg == "" means
// success. attempts follows the PHP convention: 0 = succeeded on the first
// try, N = succeeded/failed after N retries.
type retryOutcome struct {
	attempts     int
	errMsg       string
	localFsError bool
}

// errorMessage extracts the string withRetry/classify.DriveError should
// classify. A *googleapi.Error's Body is the raw JSON response — the same
// contract classify.DriveError expects (equivalent to
// Google\Service\Exception::getMessage() in the PHP client). Anything else
// (network errors, *fs.PathError…) uses its normal Error() string.
func errorMessage(err error) string {
	if err == nil {
		return ""
	}
	var gerr *googleapi.Error
	if errors.As(err, &gerr) && gerr.Body != "" {
		return gerr.Body
	}
	return err.Error()
}

// backoffDelay ports `min(2 ** $attempts, 30)` seconds.
func backoffDelay(attempts int) time.Duration {
	if attempts < 1 {
		attempts = 1
	}
	sec := int64(1) << uint(attempts) // 2^attempts
	if sec > 30 {
		sec = 30
	}
	return time.Duration(sec) * time.Second
}

// withRetry ports GDriveMirrorSync::withRetry() 1:1: local-filesystem errors
// and classified-permanent errors are NOT retried (see classify package doc);
// everything else retries with exponential backoff up to cfg.Retry times.
func (s *Syncer) withRetry(ctx context.Context, label string, fn func() error) retryOutcome {
	attempts := 0
	for {
		err := fn()
		if err == nil {
			return retryOutcome{attempts: attempts}
		}
		attempts++
		msg := errorMessage(err)

		// MUST check local-fs BEFORE classify.DriveError: a message like
		// "mkdir(): Permission denied" would otherwise fall into
		// classify.DriveError's "permission"/"forbidden" fallback branch and be
		// marked retryable=true (correct for a real Drive error, wrong for a
		// dead local disk) — see classify.IsLocalFsError doc.
		if classify.IsLocalFsError(msg) {
			s.consecutiveLocalFsErrors.Add(1)
			s.logWarn("Local filesystem error for %s — không retry (ổ đích có thể hỏng/unmount): %s", label, msg)
			return retryOutcome{attempts: attempts, errMsg: msg, localFsError: true}
		}

		cls := classify.DriveError(msg)
		if !cls.Retryable {
			s.logWarn("Permanent error (%s) for %s — skipping, no retry.", cls.Category, label)
			return retryOutcome{attempts: attempts, errMsg: msg}
		}

		if attempts > s.cfg.Retry {
			return retryOutcome{attempts: attempts, errMsg: msg}
		}

		delay := backoffDelay(attempts)
		s.logf("Attempt %d failed for %s, retrying in %s...", attempts, label, delay)
		select {
		case <-time.After(delay):
		case <-ctx.Done():
			return retryOutcome{attempts: attempts, errMsg: ctx.Err().Error()}
		}
	}
}
