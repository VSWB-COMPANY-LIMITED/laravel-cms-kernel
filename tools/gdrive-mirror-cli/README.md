# gdrive-mirror

Standalone Go CLI that mirrors a Google Drive folder to a local directory,
one-way (Drive → local, never deletes local files). Runs on macOS/Linux/
Windows without PHP — a port of `Dev\Kernel\Commands\GDriveMirrorSync`
(`GDriveMirrorSync.php`) in this kernel package.

## Build

```bash
go build -o gdrive-mirror .
```

Cross-compile:

```bash
for t in darwin/arm64 darwin/amd64 linux/amd64 windows/amd64; do
  GOOS=${t%/*} GOARCH=${t#*/} out="dist/gdrive-mirror-${t%/*}-${t#*/}"
  [ "${t%/*}" = "windows" ] && out="${out}.exe"
  GOOS=${t%/*} GOARCH=${t#*/} go build -o "$out" .
done
```

## Auth

Service-account JSON only (read-only Drive scope). The account has no "My
Drive" — share the target folder with its `client_email` (Viewer) first, then
use the folder ID (from the Drive URL `.../folders/<ID>`).

## Usage

```bash
gdrive-mirror <folderID> --path=<local_dir> [flags]
```

| Flag            | Default                                                       | Meaning                                        |
| ---------------- | -------------------------------------------------------------- | ----------------------------------------------- |
| `--path`          | *(required)*                                                    | Local destination directory                     |
| `--creds`         | `$GOOGLE_APPLICATION_CREDENTIALS` or `./google-service-account-credentials.json` | Service-account JSON key file |
| `--retry`         | `3`                                                              | Retries per file operation on network failure   |
| `--force`         | `false`                                                          | Re-download everything (safe: never deletes)    |
| `--dry-run`       | `false`                                                          | List first 20 items only, no download           |
| `--limit`         | `0` (all)                                                        | Only process the first N items — testing        |
| `--concurrency`   | `4`                                                               | Concurrent file downloads (goroutine pool)       |

Example:

```bash
gdrive-mirror 0Bw6yYZTQJcm3aGoxTGJuY1p1ZU0 \
  --path=/Volumes/WD-DATA1/GDrive-Mirror \
  --retry=10 --concurrency=8
```

Step-by-step sample commands per OS (macOS / Linux / Windows) — including
dry-run → limited test → full run, scheduling (cron/launchd/systemd/Task
Scheduler), and troubleshooting: **[RUN-SAMPLES.md](RUN-SAMPLES.md)**.

## Behavior (ported from the PHP source — see its docblock for the full story)

- **Delta sync**: `--force` always re-downloads; regular files compare
  size then MD5; Google-native files (no MD5) fall back to mtime.
- **Google-native → Office**: Docs→docx, Sheets→xlsx, Slides→pptx,
  Drawings→png, Apps Script→json. Shortcuts are skipped (pointers only —
  their target is a separate listed item). Any other native type
  (Forms/Sites/Maps/Jamboard…) fails permanent `fileNotDownloadable`.
- **Error classification** (`internal/classify`): permanent errors (403
  reasons like `exportSizeLimitExceeded`, `cannotExportFile`,
  `insufficientFilePermissions`…) are never retried; everything else
  retries with exponential backoff (2s/4s/8s/…, capped at 30s).
- **Local filesystem circuit breaker**: local I/O errors (disk unmounted/
  read-only/dead) are never retried and trip an abort after 20 consecutive
  hits (thread-safe atomic counter, reset on any success).
- **Preflight write-probe**: before listing anything, a real file is
  written/read/deleted at the destination — catches a disk that reports
  writable but actually errors on real I/O. Skipped in `--dry-run`.
- **Downloads are atomic**: written to a `.tmp-<runID>` file, renamed into
  place only on full success — a crash mid-transfer never leaves a
  truncated file at the real path.
- **Name collisions** (two different Drive IDs mapping to the same local
  path within one run) get the first 8 chars of the file ID appended.
- **Listing "verify on zero"**: a folder reported empty is re-checked once
  after a 1s pause before being trusted — Drive's `files.list` has been
  observed to return an empty page for a folder that actually has children
  under API load.

## Reports

When any item fails, two reports are written to
`<path>/../gdrive-mirror-reports/`:

- `failed-<folderTag>-<YYYYMMDD-HHMMSS>.json` — full detail per item
  (path/id/mimeType/md5/size/reason/permanent/category/error_reason),
  shaped for a future `--retry-failed` re-run. Only the 10 most recent are
  kept (older ones auto-pruned).
- `<ddmmYYYY_HHMMSS>.csv` — Excel-safe (UTF-8 BOM, `;` delimiter — plain
  `,` gets misread as a decimal separator under VN locale Excel), columns
  `file;path;size;category;error_reason;permanent;drive_link`, sorted
  permanent-first then by category then by size descending.

## Known gaps vs. the PHP source (deferred, not in this MVP)

- `.xlsx` failed-report (CSV only — OpenSpout/PhpSpreadsheet dependency
  not ported)
- Unexportable manifest (`unexportable/<folderTag>.md`) with manual
  download links grouped by reason
- Listing item-count shrink guard (`shouldAbortOnShrink` logic exists and
  is unit-tested in `internal/classify`, but nothing persists prior-run
  state yet to call it from)
- `--retry-failed` / `--include-permanent` re-run mode (the JSON report
  shape is already compatible — this just needs a loader)
- Multiple folder IDs in one invocation (PHP took `{folders?*}`; this CLI
  takes exactly one `<folderID>`)
- Path-based folder identifiers ("Parent/Child") — service-account mode
  never supported these in the PHP source either (Folder ID only)

## Package layout

```
main.go                       CLI flag parsing + orchestration entrypoint
internal/classify/            Pure error-classification helpers (unit tested)
internal/mirror/              List/delta/download/retry/circuit-breaker/worker-pool
internal/report/              Failed-item JSON + Excel-safe CSV report writers
```
