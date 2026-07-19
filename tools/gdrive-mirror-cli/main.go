// Command gdrive-mirror mirrors a Google Drive folder to a local directory,
// one way (Drive → local, never deletes local files). Standalone Go port of
// Dev\Kernel\Commands\GDriveMirrorSync (GDriveMirrorSync.php) — no PHP/CMS
// runtime required.
package main

import (
	"context"
	"errors"
	"flag"
	"fmt"
	"os"
	"os/signal"
	"path/filepath"
	"strings"
	"syscall"

	"github.com/vswb/gdrive-mirror/internal/mirror"
	"github.com/vswb/gdrive-mirror/internal/report"
)

func main() {
	os.Exit(run())
}

func run() int {
	fs := flag.NewFlagSet("gdrive-mirror", flag.ContinueOnError)
	pathFlag := fs.String("path", "", "Local destination directory (required)")
	credsFlag := fs.String("creds", "", "Service-account JSON credentials file (default: $GOOGLE_APPLICATION_CREDENTIALS or ./google-service-account-credentials.json)")
	retryFlag := fs.Int("retry", 3, "Number of retries per file operation on network failure")
	forceFlag := fs.Bool("force", false, "Force re-download/overwrite all files (safe: never deletes)")
	dryRunFlag := fs.Bool("dry-run", false, "List remote items only (first 20), do not download")
	limitFlag := fs.Int("limit", 0, "Only process the first N items (0 = all). Useful for testing")
	concurrencyFlag := fs.Int("concurrency", 4, "Number of files to download concurrently")
	retryFailedFlag := fs.String("retry-failed", "", "Path to a failed-*.json report (or the directory containing it — newest is picked) to re-run instead of listing Drive again")
	includePermanentFlag := fs.Bool("include-permanent", false, "With --retry-failed, also retry items marked permanent (default: skipped — they cannot self-heal)")
	ignoreShrinkFlag := fs.Bool("ignore-shrink", false, "Skip the listing shrink-guard abort — use only when you deliberately deleted a lot of files/folders on Drive")

	fs.Usage = func() {
		fmt.Fprintf(os.Stderr, "Usage: %s <folderID> [<folderID> ...] --path=<dir> [flags]\n", os.Args[0])
		fmt.Fprintf(os.Stderr, "       %s --retry-failed=<report.json|dir> --path=<dir> [flags]\n\n", os.Args[0])
		fs.PrintDefaults()
	}

	// flag.FlagSet.Parse stops at the first non-flag token, but the CLI
	// contract puts <folderID> BEFORE the flags (mirrors the PHP artisan
	// command's `{folders?*} {--opt=}` signature). Pull every folder ID out
	// up front so they can appear anywhere among the raw args, and hand the
	// flag package only the remaining "--flag[=value]" tokens.
	folderIDs, flagArgs := extractFolderIDs(os.Args[1:])

	if err := fs.Parse(flagArgs); err != nil {
		if errors.Is(err, flag.ErrHelp) {
			return 0
		}
		return 2
	}

	if *retryFailedFlag == "" && len(folderIDs) == 0 {
		fmt.Fprintln(os.Stderr, "error: missing <folderID> argument (or use --retry-failed=<report>)")
		fs.Usage()
		return 2
	}

	if *pathFlag == "" {
		fmt.Fprintln(os.Stderr, "error: --path is required")
		return 2
	}
	absPath, err := filepath.Abs(*pathFlag)
	if err != nil {
		fmt.Fprintf(os.Stderr, "error: invalid --path: %v\n", err)
		return 2
	}

	credsFile := resolveCredsFile(*credsFlag)
	if credsFile == "" {
		fmt.Fprintln(os.Stderr, "error: no credentials file found — pass --creds, set GOOGLE_APPLICATION_CREDENTIALS, or place ./google-service-account-credentials.json")
		return 2
	}

	if *concurrencyFlag < 1 {
		*concurrencyFlag = 1
	}

	baseCfg := mirror.Config{
		Path:         absPath,
		CredsFile:    credsFile,
		Retry:        *retryFlag,
		Force:        *forceFlag,
		DryRun:       *dryRunFlag,
		Limit:        *limitFlag,
		Concurrency:  *concurrencyFlag,
		IgnoreShrink: *ignoreShrinkFlag,
	}

	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()

	if *retryFailedFlag != "" {
		return runRetryFailed(ctx, baseCfg, *retryFailedFlag, *includePermanentFlag)
	}
	return runFolders(ctx, baseCfg, folderIDs)
}

// folderRunResult pairs one folder's identifier with the Stats its Run()
// call produced, so the multi-folder summary/exit-code logic below can stay
// pure and testable without re-deriving which folder a Stats came from.
type folderRunResult struct {
	folderID string
	stats    mirror.Stats
}

// runFolders syncs every folder ID sequentially, each with its own Syncer
// (own runID, own reports) — a folder erroring out does not stop the rest
// (matches the requirement that one bad folder must not kill the others),
// EXCEPT when the local filesystem circuit breaker trips: at that point the
// destination disk itself is presumed dead/unmounted, so attempting the
// next folder would fail identically and only waste time (same reasoning
// as the PHP source's `break 2` out of its folder loop on this condition —
// see GDriveMirrorSync.php ~L1204-1208).
func runFolders(ctx context.Context, baseCfg mirror.Config, folderIDs []string) int {
	var results []folderRunResult

	for _, id := range folderIDs {
		cfg := baseCfg
		cfg.FolderID = id

		syncer, err := mirror.New(ctx, cfg)
		if err != nil {
			fmt.Fprintf(os.Stderr, "fatal: %v\n", err)
			return 1
		}

		stats, err := syncer.Run(ctx)
		if err != nil {
			// preflight (shared --path across every folder) or context
			// cancellation (Ctrl-C) — identical for every remaining folder,
			// so there is nothing to gain by trying the next one.
			fmt.Fprintf(os.Stderr, "fatal: %v\n", err)
			results = append(results, folderRunResult{folderID: id, stats: stats})
			printMultiSummary(results)
			return 1
		}

		results = append(results, folderRunResult{folderID: id, stats: stats})

		if stats.AbortReason == "local-fs-circuit-breaker" {
			fmt.Fprintln(os.Stderr, "Dừng — ổ đích có vấn đề (circuit breaker cục bộ), các folder còn lại cũng sẽ thất bại giống vậy.")
			break
		}
	}

	printMultiSummary(results)
	return aggregateExitCode(results)
}

// printMultiSummary prints the combined-plus-per-folder breakdown described
// in the multi-folder requirement. A single-folder run already gets a full
// summary from Syncer.printSummary, so this stays silent for len(results)<=1
// to avoid a redundant near-duplicate block.
func printMultiSummary(results []folderRunResult) {
	if len(results) <= 1 {
		return
	}
	fmt.Print(formatMultiSummary(results))
}

// formatMultiSummary is the pure rendering half of printMultiSummary —
// split out so the exact text can be asserted on in tests without capturing
// stdout.
func formatMultiSummary(results []folderRunResult) string {
	var b strings.Builder
	sep := strings.Repeat("=", 50)

	fmt.Fprintln(&b)
	fmt.Fprintln(&b, sep)
	fmt.Fprintln(&b, "TỔNG KẾT NHIỀU FOLDER")
	fmt.Fprintln(&b, sep)

	var total mirror.Stats
	for _, r := range results {
		status := "OK"
		if r.stats.Aborted {
			status = "ABORTED (" + r.stats.AbortReason + ")"
		} else if r.stats.Errors > 0 {
			status = "CÓ LỖI"
		}
		fmt.Fprintf(&b, "- %s: updated=%d skipped=%d errors=%d folders=%d [%s]\n",
			r.folderID, r.stats.Updated, r.stats.Skipped, r.stats.Errors, r.stats.Folders, status)

		total.Updated += r.stats.Updated
		total.Skipped += r.stats.Skipped
		total.Errors += r.stats.Errors
		total.Folders += r.stats.Folders
		total.Collisions += r.stats.Collisions
		total.TotalListed += r.stats.TotalListed
	}

	fmt.Fprintf(&b, "TỔNG (%d folder): updated=%d skipped=%d errors=%d folders=%d collisions=%d listed=%d\n",
		len(results), total.Updated, total.Skipped, total.Errors, total.Folders, total.Collisions, total.TotalListed)
	fmt.Fprintln(&b, sep)

	return b.String()
}

// aggregateExitCode returns 1 if any folder had item errors or was aborted
// (shrink-guard or circuit-breaker) — an aborted folder produced zero
// "errors" in the usual sense but is exactly the kind of outcome a cron
// job's exit code exists to surface, so it must not silently read as 0.
func aggregateExitCode(results []folderRunResult) int {
	for _, r := range results {
		if r.stats.Errors > 0 || r.stats.Aborted {
			return 1
		}
	}
	return 0
}

// runRetryFailed implements --retry-failed: load a prior run's failed-items
// JSON report and download only those items, skipping a fresh Drive
// listing entirely. Ports the "2b. --retry-failed" block of
// GDriveMirrorSync::handle() (PHP source ~L565-598).
func runRetryFailed(ctx context.Context, baseCfg mirror.Config, retryFailedPath string, includePermanent bool) int {
	resolvedPath, err := report.ResolveRetryFailedPath(retryFailedPath)
	if err != nil {
		fmt.Fprintf(os.Stderr, "error: %v\n", err)
		return 2
	}

	data, err := os.ReadFile(resolvedPath)
	if err != nil {
		fmt.Fprintf(os.Stderr, "error: cannot read --retry-failed file %s: %v\n", resolvedPath, err)
		return 2
	}

	fr, err := report.ParseFailedReport(data)
	if err != nil {
		fmt.Fprintf(os.Stderr, "error: %v\n", err)
		return 2
	}

	kept, skipped := report.FilterRetryItems(fr.Items, includePermanent)
	fmt.Fprintf(os.Stderr, "RETRY-FAILED mode: loaded %d item(s) from %s\n", len(fr.Items), filepath.Base(resolvedPath))
	if skipped > 0 {
		fmt.Fprintf(os.Stderr, "Skipped %d permanent item(s) (use --include-permanent to force retry).\n", skipped)
	}
	if len(kept) == 0 {
		fmt.Println("Nothing left to retry after filtering permanent errors.")
		return 0
	}

	// baseCfg.Path is always the user's --path (required + validated in
	// run() before this is ever called) — deliberately NOT falling back to
	// fr.BaseLocalPath from the JSON: trusting a destination directory out
	// of a file that "may have been edited by hand" (see ItemFromFailed
	// doc) is a much bigger foot-gun than requiring --path explicitly every
	// time, and --path is cheap to pass since it's needed for every other
	// mode anyway.
	syncer, err := mirror.New(ctx, baseCfg)
	if err != nil {
		fmt.Fprintf(os.Stderr, "fatal: %v\n", err)
		return 1
	}

	folderIDs := fr.FolderIDs
	if len(folderIDs) == 0 {
		folderIDs = []string{"__retry_failed__"}
	}

	// fr.LocalPrefix is the top-level dir the original run wrote under; without
	// it every retried file lands one directory too high (see RunRetry doc).
	stats, err := syncer.RunRetry(ctx, mirror.ItemsFromFailed(kept), folderIDs, fr.LocalPrefix)
	if err != nil {
		fmt.Fprintf(os.Stderr, "fatal: %v\n", err)
		return 1
	}

	if stats.Errors > 0 || stats.Aborted {
		return 1
	}
	return 0
}

// extractFolderIDs pulls every non-flag token out of args (the positional
// <folderID> arguments — one or more) and returns them alongside the
// remaining tokens, so callers can write
// `gdrive-mirror <id1> <id2> --path=x` (arguments first, like the source
// PHP artisan command's `{folders?*}`) instead of being forced into
// `gdrive-mirror --path=x <id1> <id2>` (Go's flag package only parses flags
// up to the first non-flag token). Order is preserved — folders sync in the
// order given.
func extractFolderIDs(args []string) (folderIDs []string, rest []string) {
	for _, a := range args {
		if a != "" && !strings.HasPrefix(a, "-") {
			folderIDs = append(folderIDs, a)
			continue
		}
		rest = append(rest, a)
	}
	return folderIDs, rest
}

// resolveCredsFile: --creds flag wins, then $GOOGLE_APPLICATION_CREDENTIALS,
// then ./google-service-account-credentials.json — matches the PHP source's
// resolveServiceAccountFile() priority order (env → config → default path).
func resolveCredsFile(flagVal string) string {
	candidates := []string{
		flagVal,
		os.Getenv("GOOGLE_APPLICATION_CREDENTIALS"),
		"google-service-account-credentials.json",
	}
	for _, c := range candidates {
		if c == "" {
			continue
		}
		if fi, err := os.Stat(c); err == nil && !fi.IsDir() {
			return c
		}
	}
	return ""
}
