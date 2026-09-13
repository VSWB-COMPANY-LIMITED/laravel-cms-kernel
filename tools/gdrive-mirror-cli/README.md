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
| `--limit`              | `0` (all)                                                        | Only process the first N **items** (folders + files mixed, depth-first order — folders just get mkdir, so actual downloads ≤ N, possibly 0). Applied AFTER the full recursive listing finishes — it does NOT speed up the listing phase. For a quick "download a few files" test, point the tool at a small subfolder ID instead. See RUN-SAMPLES §0.5 |
| `--concurrency`        | `4`                                                               | Concurrent file downloads (goroutine pool, download phase only). SSD + fast network: `8`; slow HDD/USB or weak network: `2`–`4` |
| `--list-concurrency`   | `8`                                                               | Concurrent folder-listing requests (`files.list`) during the recursive listing phase — independent from `--concurrency` (downloads). Listing calls are cheap metadata-only round-trips, so this can usually run higher than the download concurrency. A big tree (thousands of folders) that used to take minutes to just LIST, before a single byte downloaded, now does so in roughly `1/N` the time |
| `--incremental`        | `false`                                                           | Use the Drive Changes API for delta sync — see "Incremental sync" below. Self-bootstrapping: the first run for a folder is always a full listing (which also saves the state this needs); every run after that only re-lists folders Drive reports as changed |
| `--retry-failed`       | *(unset)*                                                        | Path to a `failed-*.json` report (or its directory — newest picked) — retries only its items, no fresh listing |
| `--include-permanent`  | `false`                                                          | With `--retry-failed`, also retry items marked `permanent` (default: skipped — they cannot self-heal) |
| `--ignore-shrink`      | `false`                                                          | Skip the listing shrink-guard abort — only when you deliberately deleted a lot on Drive (PHP source's equivalent flag is `--allow-shrink`) |
| `--allow-cloud-sync-path` | `false`                                                        | Allow `--path` to be inside a folder a live cloud-sync client (OneDrive/Google Drive/Dropbox/iCloud) is watching. OFF by default — see "Không mirror thẳng vào thư mục cloud-sync sống" below for why this is refused by default and what to do instead |
| `--push-to`            | *(unset)*                                                        | After the run, push `--path`'s current contents into this SECOND directory (typically a live OneDrive/Google Drive/Dropbox folder) as one bounded, non-destructive, only-what-changed copy — the recommended way to end up with a mirror inside a cloud-sync folder. See "Không mirror thẳng vào thư mục cloud-sync sống" below |

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

## 🔴 KHÔNG mirror thẳng vào thư mục cloud-sync sống (OneDrive/Google Drive/Dropbox/iCloud)

**Sự cố thật đã xảy ra:** `--path` được trỏ thẳng vào `C:\Users\<user>\OneDrive - <org>` (gốc
thư mục OneDrive đang đồng bộ trên máy Windows chạy tool này). Cùng những file đó, cùng kích
thước, cùng ngày sửa, cứ mỗi lần tool chạy lại xuất hiện thêm MỘT bản trùng tên tăng dần trên
SharePoint (`file.docx`, `file-2.docx`, `file-3.docx`, … tới `file-21.docx` sau ~20 lần chạy) —
dù nội dung Drive chưa hề đổi.

**Vì sao:** tool này tải-vào-file-tạm-rồi-`rename`-đè-lên-đích (atomic write — đúng cách cho
một thư mục KHÔNG ai khác đang canh). Nhưng khi `--path` nằm trong một thư mục một client
cloud-sync KHÁC (OneDrive/Google Drive/Dropbox desktop app) cũng đang theo dõi sống, MỖI lần
`rename` đó là một cú ghi từ bên ngoài giao thức của client kia — kể cả khi nội dung giống hệt.
OneDrive đặc biệt phản ứng bằng cách coi đó là "sửa xung đột đồng thời" và **tự đẻ một bản mới
đánh số** thay vì hoà giải — một bản trùng mới mỗi lần chạy, mãi mãi, dù file chưa từng thực sự
đổi.

**Vì vậy: mặc định tool TỪ CHỐI chạy** (preflight fail trước khi đụng byte nào) nếu `--path` rơi
vào một thư mục như vậy — nhận diện qua biến môi trường client tự đặt (`OneDrive`,
`OneDriveConsumer`, `OneDriveCommercial` trên Windows) hoặc qua tên đường dẫn
(`~/Library/CloudStorage/...` trên macOS, `Dropbox`, `iCloud Drive`…). Xem
`internal/cloudsync/cloudsync.go`.

### ✅ Cách được khuyến nghị — `--push-to` (một lệnh, backup vào OneDrive vẫn NHANH)

`--path` vẫn là một thư mục LOCAL THUẦN (giữ nguyên toàn bộ máy state/incremental của tool và
qua được preflight ở trên), nhưng thêm `--push-to=<thư mục OneDrive>` — tool tự động đẩy phần
vừa tải sang đó ngay sau khi mirror xong, **trong cùng một lệnh, cùng một lần chạy**:

```powershell
.\gdrive-mirror.exe @folderIds `
  --path="D:\GDrive-Mirror" `
  --push-to="C:\Users\KUN\OneDrive - VISUAL WEBER COMPANY LIMITED" `
  --creds="C:\Tools\gdrive\creds.json" --concurrency=8
```

**Vì sao cách này AN TOÀN HƠN mirror thẳng, mà vẫn nhanh:**
- Bước tải (Drive → `--path`) hoàn toàn KHÔNG đụng tới thư mục OneDrive — mọi rename/ghi-đè dồn
  dập của giai đoạn tải xảy ra ở ổ local thuần, chỗ không ai canh.
- Bước đẩy (`--path` → `--push-to`) là MỘT thao tác gọn, chạy SAU khi tải xong hẳn — trên Windows
  dùng `robocopy` (có sẵn hệ điều hành, đa luồng `/MT:8`), nơi khác dùng bộ copy Go tích hợp sẵn —
  **cả hai đều chỉ ghi file THẬT SỰ mới/đổi** (so size+mtime trước, giống hệt delta-check của
  chính tool). Lần chạy lặp lại mà không có gì đổi → bước đẩy gần như tức thời.
- **Không xoá gì ở `--push-to`** (không `/MIR`, không `/PURGE`) — chỉ thêm/cập nhật, khớp triết lý
  "không bao giờ xoá file local" của cả tool.
- Vẫn có rủi ro dư (không thể loại bỏ 100% khi OneDrive desktop client còn sống canh thư mục đó —
  đây là giới hạn của CHÍNH cơ chế OneDrive, không riêng tool nào), nhưng đã loại bỏ đúng nguyên
  nhân đã đo được trong sự cố thật: ghi lặp lại liên tục dồn dập vào thư mục sống, rải suốt một
  phiên `--concurrency` cao, thay bằng MỘT đợt ghi gọn chỉ đúng phần thay đổi.

### Cách thủ công (không dùng `--push-to`) — vẫn hoạt động, để tham khảo/troubleshoot

```powershell
# 1) Mirror Drive -> thư mục LOCAL THUẦN (không phải OneDrive)
.\gdrive-mirror.exe @folderIds --path="D:\GDrive-Mirror" --creds="C:\Tools\gdrive\creds.json" --concurrency=8

# 2) Đẩy một-chiều sang OneDrive — chạy SAU khi bước 1 xong hẳn (không song song)
robocopy "D:\GDrive-Mirror" "C:\Users\KUN\OneDrive - VISUAL WEBER COMPANY LIMITED" /E /MT:8 /R:2 /W:2
```

`--push-to` ở trên chính là bước này, tự động hoá — dùng nó thay vì lệnh `robocopy` tay, trừ khi
đang debug/cần kiểm soát chi tiết.

**Nếu bạn CHẮC CHẮN muốn `--path` CHÍNH LÀ thư mục cloud-sync (bỏ qua cả `--push-to`) bất chấp rủi
ro ở trên** (vd đã tắt hẳn tính năng đồng bộ của client đó cho thư mục này), thêm
`--allow-cloud-sync-path`.

## Incremental sync (Drive Changes API) — `--incremental`

Mặc định mỗi lần chạy đều liệt kê lại **toàn bộ** cây trên Drive rồi so delta từng file — đúng
nhưng tốn, nhất là cho một cây hàng nghìn item chạy định kỳ (cron) mà đa số không đổi gì giữa
hai lần chạy.

`--incremental` chuyển sang dùng [Drive Changes API](https://developers.google.com/workspace/drive/api/guides/manage-changes):
lần chạy đầu tiên cho một folder vẫn là liệt kê đầy đủ như bình thường (không có gì để so sánh),
nhưng nó ĐỒNG THỜI lưu lại một *manifest* (toàn bộ cây đã biết) + một *page token* của Drive
Changes API. Từ lần chạy sau, tool hỏi Drive "có gì đổi kể từ token này" thay vì liệt kê lại từ
đầu, rồi **chỉ liệt kê lại đúng những folder Drive báo có thay đổi** — phần còn lại của cây được
giữ nguyên từ manifest, không tốn một request nào.

```bash
# Lần đầu: liệt kê đầy đủ như bình thường + tự lưu manifest/token cho lần sau
gdrive-mirror <folderID> --path=/Volumes/WD-DATA1/GDrive-Mirror --incremental

# Các lần sau: chỉ hỏi "có gì đổi" rồi liệt kê lại đúng phần đó
gdrive-mirror <folderID> --path=/Volumes/WD-DATA1/GDrive-Mirror --incremental
```

Trạng thái lưu tại `<path>/../gdrive-mirror-reports/incremental/<folderTag>.json` (cạnh
`state/<folderTag>.json` của shrink-guard, xem "Reports" bên dưới). Manifest/token thiếu, hỏng,
hoặc Changes API lỗi → tool tự quay về liệt kê đầy đủ lần đó (không bao giờ coi là lỗi fatal),
và tự bootstrap lại state cho lần sau — an toàn để bật/tắt `--incremental` tuỳ ý giữa các lần
chạy.

**Giới hạn đã biết:** một folder ĐANG ĐƯỢC THEO DÕI mà chính nó bị đổi tên/di chuyển sẽ được xử
lý đúng (tool tự phát hiện đường dẫn cũ trong manifest không khớp nữa và liệt kê lại đúng những
folder bị ảnh hưởng theo tầng, không phải toàn cây) — nhưng cơ chế này thêm một số request phụ so
với trường hợp chỉ thêm/xoá/sửa file thường. Với một cây ít khi tự đổi cấu trúc thư mục gốc (phổ
biến cho mirror backup định kỳ), chi phí này không đáng kể.

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
- `incremental/<folderTag>.json` — only with `--incremental` (see above):
  the full known-item manifest + Drive Changes API page token the NEXT
  `--incremental` run needs. Same "never written when the guard aborts"
  rule as `state/` above.

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
internal/cloudsync/           Pure detection of a --path inside a live OneDrive/Google Drive/
                               Dropbox/iCloud-watched folder (unit tested) — see "KHÔNG mirror
                               thẳng vào thư mục cloud-sync sống" above
                               (push.go in internal/mirror/ is the --push-to companion — see below)
internal/mirror/               List (concurrent, list.go)/delta/download/retry/circuit-breaker/
                               shrink-guard/worker-pool/incremental (Drive Changes API, incremental.go)
internal/report/              Failed-item JSON/CSV/XLSX report writers, unexportable manifest,
                               --retry-failed loader, listing-state + incremental-manifest read/write
```
