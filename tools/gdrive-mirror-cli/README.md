# gdrive-mirror

Standalone Go CLI that mirrors a Google Drive folder to a local directory,
one-way (Drive → local, never deletes local files). Runs on macOS/Linux/
Windows without PHP — a port of `Dev\Kernel\Commands\GDriveMirrorSync`
(`GDriveMirrorSync.php`) in this kernel package.

## Prebuilt binaries (committed in `dist/` — no Go toolchain needed)

| OS | File |
| --- | --- |
| Windows x64 | `dist/gdrive-mirror-windows-amd64.exe` |
| Linux x64 (static, mọi distro) | `dist/gdrive-mirror-linux-amd64` |
| Linux ARM64 (Pi/ARM server, static) | `dist/gdrive-mirror-linux-arm64` |
| macOS Apple Silicon | `dist/gdrive-mirror-darwin-arm64` |
| macOS Intel | `dist/gdrive-mirror-darwin-amd64` |

Checksums: `dist/SHA256SUMS`. macOS/Linux cần `chmod +x` sau khi copy; macOS
tải qua trình duyệt thì thêm `xattr -d com.apple.quarantine <file>`.

## Build from source

```bash
go build -o gdrive-mirror .
```

Cross-compile (đúng lệnh dùng để build `dist/`):

```bash
for t in darwin/arm64 darwin/amd64 linux/amd64 linux/arm64 windows/amd64; do
  out="dist/gdrive-mirror-${t%/*}-${t#*/}"
  [ "${t%/*}" = "windows" ] && out="${out}.exe"
  CGO_ENABLED=0 GOOS=${t%/*} GOARCH=${t#*/} go build -trimpath -ldflags="-s -w" -o "$out" .
done
(cd dist && shasum -a 256 gdrive-mirror-* > SHA256SUMS)
```

## Auth

Service-account JSON only (read-only Drive scope). The account has no "My
Drive" — share the target folder with its `client_email` (Viewer) first, then
use the folder ID (from the Drive URL `.../folders/<ID>`).

## Usage

```bash
gdrive-mirror <folderID> [<folderID> ...] --path=<local_dir> [flags]
gdrive-mirror --retry-failed=<report.json|dir> --path=<local_dir> [flags]
```

| Flag                  | Default                                                       | Meaning                                        |
| ---------------------- | -------------------------------------------------------------- | ----------------------------------------------- |
| `--path`               | *(required)*                                                    | Local destination directory                     |
| `--creds`              | `$GOOGLE_APPLICATION_CREDENTIALS` or `./google-service-account-credentials.json` | Service-account JSON key file |
| `--retry`              | `3`                                                              | Retries per file operation on network failure   |
| `--force`              | `false`                                                          | Re-download everything (safe: never deletes)    |
| `--dry-run`            | `false`                                                          | List first 20 items only, no download           |
| `--limit`              | `0` (all)                                                        | Only process the first N items — testing        |
| `--concurrency`        | `4`                                                               | Concurrent file downloads (goroutine pool)       |
| `--retry-failed`       | *(unset)*                                                        | Path to a `failed-*.json` report (or its directory — newest picked) — retries only its items, no fresh listing |
| `--include-permanent`  | `false`                                                          | With `--retry-failed`, also retry items marked `permanent` (default: skipped — they cannot self-heal) |
| `--ignore-shrink`      | `false`                                                          | Skip the listing shrink-guard abort — only when you deliberately deleted a lot on Drive (PHP source's equivalent flag is `--allow-shrink`) |

One or more `<folderID>` arguments may be given — each folder is synced
independently, sequentially, with its own local subfolder and its own
report files. A single `<folderID>` behaves exactly as before (no change).

Example — single folder:

```bash
gdrive-mirror 0Bw6yYZTQJcm3aGoxTGJuY1p1ZU0 \
  --path=/Volumes/WD-DATA1/GDrive-Mirror \
  --retry=10 --concurrency=8
```

Example — multiple folders in one invocation:

```bash
gdrive-mirror 0Bw6yYZTQJcm3aGoxTGJuY1p1ZU0 0Bw6yYZTQJcm3bGV4RVJESGxYUmc \
  --path=/Volumes/WD-DATA1/GDrive-Mirror \
  --retry=10 --concurrency=8
```

Example — retry only what failed last time:

```bash
gdrive-mirror --retry-failed=/Volumes/WD-DATA1/gdrive-mirror-reports \
  --path=/Volumes/WD-DATA1/GDrive-Mirror
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
- **Downloads are atomic**: written to a fixed-length hidden temp file next
  to the real target (`.` + 8 hex chars of `sha256(targetPath)` + `-<runID>`
  + `.tmp`), renamed into place only on full success — a crash mid-transfer
  never leaves a truncated file at the real path. The temp filename's length
  never depends on the target's own name length, so it can never itself blow
  past the 255-byte component limit below.
- **Local names are sanitized on EVERY OS**, not just when running on
  Windows — a mirror built on macOS/Linux has to stay openable if the disk
  is later plugged into a Windows machine. For every file/folder name Drive
  returns (`internal/localpath.SanitizeComponent`):
  - Characters NTFS refuses (`< > : " | ? * \ /`) and control characters are
    replaced with `_`.
  - Trailing dots/spaces are trimmed (Windows silently drops these).
  - A name that becomes empty after the above (including bare `.` or `..`)
    falls back to `_`.
  - Windows-reserved device names (`CON`, `PRN`, `AUX`, `NUL`, `COM1-9`,
    `LPT1-9`) get `_` appended, e.g. `CON.txt` → `CON_.txt`.
  - The result is capped at **255 UTF-8 bytes** per path component
    (`internal/localpath.TruncateComponent`), cutting only on a rune
    boundary (never splits a multi-byte character) and appending a 6-hex-char
    hash of the original name when a real cut happens, so two different long
    names never collapse onto the same truncated one.
- **Name collisions are resolved at LISTING time**, as one shared namespace
  across files AND folders in the same parent (a folder and a file with the
  same Drive name collide just as much as two files do). Decision order is
  by **Drive file ID, ascending** — never by `files.list` response order,
  which Drive does not guarantee stable between two calls — so the same set
  of Drive items always produces the same local names regardless of which
  order Drive happens to return them in. The loser of a collision gets the
  first 8 chars of its file ID appended before the extension (e.g.
  `report.pdf` → `report_1JP7CIBW.pdf`); the dedup key is lower-cased because
  the destination filesystem may be NTFS/APFS, both case-insensitive by
  default, so `Report.pdf` and `report.pdf` are the same collision even
  though Drive treats them as two distinct files. Every collision increments
  `Stats.Collisions` and logs a warning with the old/new name and file ID.
- **Every resolved local path is re-validated against `--path`**
  (`internal/localpath.SafeJoin`) immediately before any directory/file is
  created — defense-in-depth against a Drive item name containing `/` or
  `..` that could otherwise write outside the destination directory. A
  rejection is a permanent failure (goes to the failed-items report) and
  never counts toward the local-fs circuit breaker below — a hostile or
  malformed remote name is not evidence the destination disk is failing.
- **Listing "verify on zero"**: a folder reported empty is re-checked once
  after a 1s pause before being trusted — Drive's `files.list` has been
  observed to return an empty page for a folder that actually has children
  under API load.
- **Listing shrink-guard**: after a normal (non-`--dry-run`) listing
  completes, this folder's item count is compared against the previous
  run's — stored in `<path>/../gdrive-mirror-reports/state/<folderTag>.json`
  (`folder_id`/`item_count`/`listed_at`/`run_id`, same field names as the
  PHP source's state file so either implementation can read the other's).
  If the count dropped below **80%** of last time, the run **aborts that
  folder** (no download, state left untouched) instead of risking a mirror
  written over what might be a truncated/permission-revoked listing — a
  drop this size is far more likely to mean "Drive API hiccup / access
  revoked" than "someone really deleted 20%+ of the files". Pass
  `--ignore-shrink` when you know the drop is real (you just deleted a lot
  on Drive yourself). No prior state (first sync of a folder) or a
  corrupt/unreadable state file never aborts — treated as "nothing to
  compare against yet". Skipped entirely in `--dry-run` (a throwaway
  listing must never become the baseline). Ports `shouldAbortOnShrink()` /
  `readListingState()` / `writeListingState()` from the PHP source
  (`GDriveMirrorSync.php`).
- **Multiple folder IDs in one invocation**: each `<folderID>` given is
  synced independently and sequentially, with its own local subfolder, own
  `runID`, and own report files. One folder erroring out (or its shrink
  guard tripping) does **not** stop the rest — except when the local
  filesystem circuit breaker trips, since a dead/unmounted destination disk
  would fail every remaining folder identically too, so the run stops there
  instead of wasting time. Exit code is `1` if **any** folder had item
  errors or was aborted.
- **`--retry-failed`**: re-run mode that skips listing Drive entirely and
  downloads only the items listed in a prior `failed-*.json` report — point
  it at the file directly, or at the reports directory to auto-pick the
  newest one. Permanent items (won't self-heal — locked file, export size
  limit, revoked permission…) are skipped by default; pass
  `--include-permanent` to retry them anyway. Every item still goes through
  the same `internal/localpath.SafeJoin` validation as a live listing — a
  hand-edited report JSON is not treated as trusted input.

## Reports

When any item fails, reports are written to
`<path>/../gdrive-mirror-reports/`:

- `failed-<folderTag>-<YYYYMMDD-HHMMSS>.json` — full detail per item
  (path/id/mimeType/md5/size/reason/permanent/category/error_reason), the
  exact shape `--retry-failed` reads back in. Only the 10 most recent are
  kept (older ones auto-pruned).
- `<ddmmYYYY_HHMMSS>.csv` — Excel-safe (UTF-8 BOM, `;` delimiter — plain
  `,` gets misread as a decimal separator under VN locale Excel), columns
  `file;path;size;category;error_reason;permanent;drive_link`, sorted
  permanent-first then by category then by size descending.
- `<ddmmYYYY_HHMMSS>.xlsx` — same columns/sort order as the CSV above, as
  a real `.xlsx` (built with only the Go standard library — `archive/zip` +
  `encoding/xml` — no OpenSpout/PhpSpreadsheet-equivalent dependency).
  Only the 10 most recent are kept (older ones auto-pruned), same as the
  JSON report.
- `unexportable/<folderTag>.md` — Markdown manifest of every **permanent**
  (non-retryable) failure for that folder, grouped by error reason, each
  item with a human-readable size and a manual-download link (Google-native
  files link to their web editor; regular files link to the generic Drive
  file view). Unlike the reports above, this file is **always overwritten**
  and reflects the folder's *current* state rather than one run's history —
  it's deleted automatically once the folder has no more permanent failures.
- `state/<folderTag>.json` — the listing shrink-guard's baseline for the
  NEXT run (`folder_id`/`item_count`/`listed_at`/`run_id`). Always written
  after a normal (non-`--dry-run`, non-`--retry-failed`) listing that the
  guard accepted; never written when the guard aborts, so a bad listing
  can't poison the next comparison.

## Upgrading an existing mirror (one-time, read this first)

Name sanitizing changes what some files are *called* on disk. Because this
tool **never deletes local files**, the first run after upgrading leaves the
old copy in place and downloads the new name alongside it — a duplicate, not
a loss. Only files whose Drive name actually contained something unsafe are
affected (forbidden characters, trailing dots/spaces, a reserved device name,
over 255 bytes, or a case-only clash with a sibling); a mirror of ordinary
names is completely unaffected and re-runs as a normal no-op delta sync.

Recommended: run once, then diff the destination against the previous run
(or check the `Collisions` count in the summary) and delete the stale
originals by hand. Deleting them automatically is deliberately not offered —
one-way-never-delete is the safety property this tool is built around.

## Known gaps vs. the PHP source (deferred, not in this MVP)

- Path-based folder identifiers ("Parent/Child") — service-account mode
  never supported these in the PHP source either (Folder ID only)

## Package layout

```
main.go                       CLI flag parsing + multi-folder/retry-failed orchestration entrypoint
internal/classify/            Pure error-classification + shrink-guard helpers (unit tested)
internal/localpath/           Pure name-sanitize/truncate/safe-join helpers (unit tested)
internal/mirror/              List/delta/download/retry/circuit-breaker/shrink-guard/worker-pool
internal/report/              Failed-item JSON/CSV/XLSX report writers, unexportable manifest,
                               --retry-failed loader, listing-state read/write
```
