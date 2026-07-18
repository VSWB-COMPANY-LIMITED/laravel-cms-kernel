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

	fs.Usage = func() {
		fmt.Fprintf(os.Stderr, "Usage: %s <folderID> --path=<dir> [flags]\n\n", os.Args[0])
		fs.PrintDefaults()
	}

	// flag.FlagSet.Parse stops at the first non-flag token, but the CLI
	// contract puts <folderID> BEFORE the flags (mirrors the PHP artisan
	// command's `{folder} {--opt=}` signature). Pull the folder ID out
	// up front so it can appear anywhere among the raw args, and hand the
	// flag package only the remaining "--flag[=value]" tokens.
	folderID, flagArgs := extractFolderID(os.Args[1:])

	if err := fs.Parse(flagArgs); err != nil {
		if errors.Is(err, flag.ErrHelp) {
			return 0
		}
		return 2
	}

	if folderID == "" {
		fmt.Fprintln(os.Stderr, "error: missing <folderID> argument")
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

	cfg := mirror.Config{
		FolderID:    folderID,
		Path:        absPath,
		CredsFile:   credsFile,
		Retry:       *retryFlag,
		Force:       *forceFlag,
		DryRun:      *dryRunFlag,
		Limit:       *limitFlag,
		Concurrency: *concurrencyFlag,
	}

	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()

	syncer, err := mirror.New(ctx, cfg)
	if err != nil {
		fmt.Fprintf(os.Stderr, "fatal: %v\n", err)
		return 1
	}

	stats, err := syncer.Run(ctx)
	if err != nil {
		fmt.Fprintf(os.Stderr, "fatal: %v\n", err)
		return 1
	}

	if stats.Errors > 0 {
		return 1
	}
	return 0
}

// extractFolderID pulls the first non-flag token out of args (the
// positional <folderID>) and returns it alongside the remaining tokens, so
// callers can write `gdrive-mirror <folderID> --path=x` (argument first,
// like the source PHP artisan command) instead of being forced into
// `gdrive-mirror --path=x <folderID>` (Go's flag package only parses flags
// up to the first non-flag token).
func extractFolderID(args []string) (folderID string, rest []string) {
	for _, a := range args {
		if folderID == "" && a != "" && !strings.HasPrefix(a, "-") {
			folderID = a
			continue
		}
		rest = append(rest, a)
	}
	return folderID, rest
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
