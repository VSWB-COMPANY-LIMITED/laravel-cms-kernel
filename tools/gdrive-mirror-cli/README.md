# gdrive-mirror

Standalone Go CLI that mirrors a Google Drive folder to a local directory,
one-way (Drive → local, never deletes local files). Runs on macOS/Linux/
Windows without PHP — a port of `Dev\Kernel\Commands\GDriveMirrorSync`
(`GDriveMirrorSync.php`) in this kernel package.

> Đây là **tài liệu duy nhất** của tool (gộp từ README + RUN-SAMPLES + OPERATIONS-ONEDRIVE cũ).
> Mục lục: [1. Cài đặt](#1-cài-đặt) · [2. Chuẩn bị](#2-chuẩn-bị) · [3. Flags](#3-flags) ·
> [4. Chạy mẫu](#4-chạy-mẫu-dry-run--thử--chạy-thật) · [5. Đọc log](#5-đọc-log-đúng-cách) ·
> [6. Cloud-sync / `--push-to`](#6--mirror-vào-onedrivegoogle-drivedropbox--dùng---push-to) ·
> [7. Incremental / retry / scan-duplicates](#7-incremental-retry-failed-scan-duplicates) ·
> [8. Chạy định kỳ](#8-chạy-định-kỳ) · [9. Behavior](#9-behavior) · [10. Reports](#10-reports) ·
> [11. Vận hành thật: Windows → OneDrive](#11-vận-hành-thật-windows--onedrive) ·
> [12. Troubleshooting](#12-troubleshooting) · [13. Package layout](#13-package-layout)

> Nguyên tắc an toàn: **một chiều Drive → local, KHÔNG BAO GIỜ xoá file local.** `--force` chỉ
> ghi đè, không xoá. Luôn chạy `--dry-run` trước ở một đích mới.

---

## 1. Cài đặt

### Prebuilt binaries (committed in `dist/` — no Go toolchain needed)

| OS | File |
| --- | --- |
| Windows x64 | `dist/gdrive-mirror-windows-amd64.exe` |
| Linux x64 (static, mọi distro) | `dist/gdrive-mirror-linux-amd64` |
| Linux ARM64 (Pi/ARM server, static) | `dist/gdrive-mirror-linux-arm64` |
| macOS Apple Silicon | `dist/gdrive-mirror-darwin-arm64` |
| macOS Intel | `dist/gdrive-mirror-darwin-amd64` |

Checksums: `dist/SHA256SUMS`.

- **macOS/Linux:** `chmod +x` sau khi copy. macOS tải qua trình duyệt bị Gatekeeper chặn →
  `xattr -d com.apple.quarantine <file>`. Cài vào PATH: `sudo install -m 0755 dist/gdrive-mirror-darwin-arm64 /usr/local/bin/gdrive-mirror`.
- **Linux server:** `scp dist/gdrive-mirror-linux-amd64 user@server:/tmp/gdrive-mirror` rồi
  `sudo install -m 0755 /tmp/gdrive-mirror /usr/local/bin/gdrive-mirror`.
- **Windows:** đổi tên thành `gdrive-mirror.exe` cho gọn. SmartScreen chặn → chuột phải → Properties → **Unblock**.

### Build from source

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

Máy Windows chưa có Go: `winget install --id GoLang.Go -e` rồi mở lại PowerShell. Quy trình
build + deploy sau khi dev push code mới: xem [§11.5](#115-build-lại--deploy-khi-có-code-mới).

---

## 2. Chuẩn bị

### 2.1 Lấy `<folderID>`

Mở thư mục trên Google Drive, copy đoạn ID trong URL: `https://drive.google.com/drive/folders/<folderID>`.

### 2.2 Auth — service-account JSON

Tool **chỉ** dùng service-account (scope Drive read-only). Service account không có "My Drive" →
phải **share thư mục đích cho `client_email` của nó (quyền Viewer)** trước, nếu không sẽ nhận lỗi
permanent `insufficientFilePermissions` hoặc liệt kê rỗng.

```bash
grep client_email creds.json                 # macOS / Linux
```
```powershell
Select-String client_email .\creds.json      # Windows
```

Thứ tự tìm creds (ưu tiên từ trên xuống): `--creds=<path>` → biến môi trường
`GOOGLE_APPLICATION_CREDENTIALS` → `./google-service-account-credentials.json`.

Bảo vệ creds trên server: `sudo chown root:root /etc/gdrive/creds.json && sudo chmod 600 /etc/gdrive/creds.json`.

### 2.3 Exit code

| Code | Ý nghĩa |
| --- | --- |
| `0` | Chạy xong, không có item lỗi |
| `1` | Chạy xong nhưng **có item lỗi** (xem report), hoặc lỗi fatal (auth/preflight/đĩa) |
| `2` | Sai tham số (thiếu `<folderID>`, thiếu `--path`, không tìm thấy creds) |

Dùng cho cron/scheduler để cảnh báo.

---

## 3. Flags

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
| `--limit`              | `0` (all)                                                        | Only process the first N **items** (folders + files mixed, depth-first order — folders just get mkdir, so actual downloads ≤ N, possibly 0). Applied AFTER the full recursive listing finishes — it does NOT speed up the listing phase. Xem [§5.2](#52---limitn-đếm-item-không-phải-n-file) |
| `--concurrency`        | `4`                                                               | Concurrent file downloads (goroutine pool, download phase only). Bảng chọn: [§5.4](#54---concurrency-và---list-concurrency) |
| `--list-concurrency`   | `8`                                                               | Concurrent folder-listing requests (`files.list`) during the recursive listing phase — independent from `--concurrency`. Metadata-only round-trips, so can run higher than download concurrency. Cây hàng nghìn folder liệt kê trong khoảng `1/N` thời gian |
| `--incremental`        | `false`                                                           | Use the Drive Changes API for delta sync — see [§7.1](#71-incremental-sync---incremental) |
| `--retry-failed`       | *(unset)*                                                        | Path to a `failed-*.json` report (or its directory — newest picked) — retries only its items, no fresh listing |
| `--include-permanent`  | `false`                                                          | With `--retry-failed`, also retry items marked `permanent` (default: skipped — they cannot self-heal) |
| `--ignore-shrink`      | `false`                                                          | Skip the listing shrink-guard abort — only when you deliberately deleted a lot on Drive (PHP source's equivalent flag is `--allow-shrink`) |
| `--forbid-cloud-sync-path` | `false` | Refuse to run when `--path` is inside a folder a live cloud-sync client (OneDrive/Google Drive/Dropbox/iCloud) is watching. Default: only log a warning and continue. `--allow-cloud-sync-path` is a deprecated no-op kept for old scripts — see [§6](#6--mirror-vào-onedrivegoogle-drivedropbox--dùng---push-to) |
| `--push-to`            | *(unset)*                                                        | After the run, push `--path`'s current contents into this SECOND directory (typically a live OneDrive/Google Drive/Dropbox folder) as one bounded, non-destructive, only-what-changed copy — see [§6](#6--mirror-vào-onedrivegoogle-drivedropbox--dùng---push-to) |
| `--scan-duplicates`    | `false`                                                          | Read-only: runs instead of a normal sync. Lists local files under `--path` the current Drive listing no longer accounts for. Never deletes/moves anything — see [§7.3](#73-phát-hiện-duplicate-đã-có-từ-trước---scan-duplicates) |

Nhiều `<folderID>` trong một lệnh: mỗi folder sync độc lập, tuần tự, có subfolder local + report riêng.
Một `<folderID>` hoạt động như bình thường.

---

## 4. Chạy mẫu: dry-run → thử → chạy thật

Luôn đi theo 3 bước. Ví dụ dưới dùng ổ ngoài macOS/Linux (bash) và `D:\` (PowerShell) — đổi
`--path`/`--creds` theo máy. **Windows:** backtick `` ` `` nối dòng PowerShell (`^` cho cmd.exe);
đường dẫn có dấu cách **bắt buộc bọc nháy kép** `--path="D:\Sao luu\GDrive"`.

**Bước 1 — dry-run** (KHÔNG tải, chỉ liệt kê 20 item đầu; bỏ qua preflight write-probe nên không cần ổ đích sẵn sàng). Mục đích: xác nhận auth OK + thấy đúng thư mục.

```bash
./gdrive-mirror 0Bw6yYZTQJcm3aGoxTGJuY1p1ZU0 --path=/Volumes/WD-DATA1/GDrive-Mirror --creds=./creds.json --dry-run
```
```powershell
.\gdrive-mirror.exe 0Bw6yYZTQJcm3aGoxTGJuY1p1ZU0 --path="D:\GDrive-Mirror" --creds="C:\Tools\gdrive\creds.json" --dry-run
```

**Bước 2 — chạy thử** (`--limit=5` = 5 **ITEM** đầu, không phải 5 file — xem [§5.2](#52---limitn-đếm-item-không-phải-n-file)). Kiểm tra file tải về mở được thật (Word/PDF/Excel) rồi mới chạy full.

```bash
./gdrive-mirror 0Bw6yYZTQJcm3aGoxTGJuY1p1ZU0 --path=/Volumes/WD-DATA1/GDrive-Mirror --creds=./creds.json --limit=5 --concurrency=2
```

**Bước 3 — chạy thật:**

```bash
./gdrive-mirror 0Bw6yYZTQJcm3aGoxTGJuY1p1ZU0 \
  --path=/Volumes/WD-DATA1/GDrive-Mirror --creds=/Users/eugene/secure/creds.json \
  --retry=10 --concurrency=8
```
```powershell
$stamp = Get-Date -Format "yyyyMMdd-HHmmss"
.\gdrive-mirror.exe 0Bw6yYZTQJcm3aGoxTGJuY1p1ZU0 `
  --path="D:\GDrive-Mirror" --creds="C:\Tools\gdrive\creds.json" `
  --retry=10 --concurrency=8 *>&1 | Tee-Object -FilePath "C:\Tools\gdrive\sync-$stamp.log"
Write-Host "Exit code: $LASTEXITCODE"
```

- `--retry=10`: chịu mạng chập chờn (backoff 2s/4s/8s… tối đa 30s).
- **Chạy lại (delta-sync):** đúng lệnh trên, không cần flag gì thêm — file khớp size + MD5 (mtime với Google-native) được skip.
- **Ghi đè toàn bộ** khi nghi file local hỏng: thêm `--force` (không xoá gì).
- **Nhiều folder, một lệnh** (không lặp `foreach` gọi N tiến trình — mỗi tiến trình tự auth + dựng HTTP pool riêng, tốn hơn):

  ```powershell
  .\gdrive-mirror.exe @folderIds --path="D:\GDrive-Mirror" --creds="C:\Tools\gdrive\creds.json" --concurrency=8 --list-concurrency=8
  ```
  ```bash
  gdrive-mirror 0Bw6yYZTQJcm3aGoxTGJuY1p1ZU0 0Bw6yYZTQJcm3bGV4RVJESGxYUmc --path=/Volumes/WD-DATA1/GDrive-Mirror --retry=10 --concurrency=8
  ```
- **Dùng env thay `--creds`:** `export GOOGLE_APPLICATION_CREDENTIALS=...` (bash) · `$env:GOOGLE_APPLICATION_CREDENTIALS = "..."` hoặc `setx` để lưu vĩnh viễn (PowerShell).
- **Ổ mạng NAS/UNC** (`--path="\\NAS01\backup\gdrive"`): giữ `--concurrency` thấp 2–4 để tránh timeout.
- **Chạy nền macOS/Linux:** `nohup ./gdrive-mirror ... > sync-$(date +%Y%m%d-%H%M%S).log 2>&1 &` rồi `tail -f`. Chạy lâu qua SSH → dùng `tmux`/`screen` để không đứt khi rớt mạng.

---

## 5. Đọc log đúng cách

### 5.1 Tool chạy 2 GIAI ĐOẠN

```
Giai đoạn 1: LIỆT KÊ toàn bộ cây trên Drive (đệ quy, mỗi thư mục ≥1 API request,
             CHẠY SONG SONG theo --list-concurrency, mặc định 8 folder cùng lúc)
             → cây to (hàng nghìn thư mục) vẫn có thể mất VÀI PHÚT
             → KHÔNG tải/ghi file nào; chỉ in các dòng WARN "Trùng tên"
               (thứ tự WARN có thể xen kẽ do chạy song song — kết quả cuối vẫn đúng depth-first)
             → kết thúc bằng dòng:  Found N item(s).
Giai đoạn 2: TẢI file (lúc này mới đụng ổ đĩa)
             → mở màn bằng dòng:   Starting synchronization of X file(s)...
```

**Chưa thấy `Found N item(s).` = còn đang liệt kê — cứ để chạy.** Đừng tưởng tool treo khi im lặng
lâu / chỉ toàn WARN. Cây rất to + chạy định kỳ → cân nhắc `--incremental` ([§7.1](#71-incremental-sync---incremental)).

### 5.2 `--limit=N` đếm ITEM, không phải N file

- `--limit` áp **SAU khi liệt kê xong toàn bộ cây** (cố ý — để shrink-guard không hiểu nhầm cây bị teo). Không làm giai đoạn 1 nhanh hơn.
- "N item đầu" = N mục đầu theo **thứ tự duyệt sâu (depth-first), trộn cả thư mục lẫn file**. Thư mục chỉ mkdir. Ví dụ cây `folder-a / sub-1 / file-x, file-y` → `--limit=5` lấy `folder-a`, `sub-1`, `file-x`, `file-y`, … — có thể ít hơn N file, thậm chí 0.
- Số file THẬT sẽ tải nằm ở dòng `Starting synchronization of X file(s)...` — nhìn X, đừng nhìn N.
- **Muốn test "tải thử vài file" đúng nghĩa:** đừng dùng `--limit` trên root to — vào một **thư mục con nhỏ** có sẵn file, copy ID từ URL và chạy với ID đó.

### 5.3 WARN `Trùng tên trong '...'`

- So sánh **các file trên Drive với nhau trong cùng 1 thư mục** — KHÔNG liên quan file đã tải ở lần trước (giai đoạn 1 không nhìn ổ đĩa).
- Bắn khi **2 file KHÁC NHAU trên Drive** cho ra **cùng 1 tên local**: (1) Drive cho phép 2 file trùng tên y hệt cùng thư mục, filesystem thì không; (2) tên chỉ khác ở ký tự bị sanitize (`Th09-Th10:2021.pdf` vs `Th09-Th10_2021.pdf`).
- Nếu không đổi tên thì một trong hai bị ghi đè mất im lặng → tool gắn hậu tố `_<8 ký tự đầu Drive ID>` cho bản đến sau (deterministic, lần nào cũng ra đúng tên đó) + in WARN.
- WARN lặp lại mỗi run chừng nào cặp trùng còn trên Drive. Muốn hết: dọn gốc trên Drive (so md5/nội dung trước khi xoá). Chạy lại tool KHÔNG bao giờ tự sinh "file (2)".

### 5.4 `--concurrency` và `--list-concurrency`

`--concurrency` = số file **tải song song** (chỉ giai đoạn 2). To hơn = nhanh hơn nếu mạng/ổ chịu được; quá to trên HDD/USB chậm hoặc mạng yếu → lỗi I/O, timeout, Drive throttle.

| Tình huống | `--concurrency` |
| --- | --- |
| SSD nội bộ + mạng cáp quang | `8` |
| Ổ cứng ngoài USB / HDD | `4` (mặc định) |
| Ổ mạng NAS / SMB | `2`–`4` |
| Mạng yếu, hay rớt | `2` + `--retry=10` |
| Đang test | `1`–`2` + `--limit=5` |

`--list-concurrency` (mặc định `8`) là tham số RIÊNG, chỉ gọi API metadata nhẹ — thường để mặc định;
chỉ hạ xuống nếu thấy rate-limit (`429`/`userRateLimitExceeded`) dồn dập ở giai đoạn 1.

---

## 6. 🔴 Mirror vào OneDrive/Google Drive/Dropbox → dùng `--push-to`

**Sự cố thật:** `--path` được trỏ thẳng vào `C:\Users\<user>\OneDrive - <org>` (gốc thư mục OneDrive
đang đồng bộ). Cùng những file đó, cùng kích thước, cùng ngày sửa, cứ mỗi lần tool chạy lại xuất hiện
thêm MỘT bản trùng tên tăng dần trên SharePoint (`file.docx`, `file-2.docx`, … `file-21.docx` sau
~20 lần chạy) — dù nội dung Drive chưa đổi.

**Vì sao:** tool tải-vào-file-tạm-rồi-`rename`-đè-lên-đích (atomic write — đúng cho thư mục KHÔNG ai
khác canh). Nhưng khi `--path` nằm trong thư mục một client cloud-sync KHÁC đang theo dõi sống, MỖI
`rename` là một cú ghi ngoài giao thức của client kia, dù nội dung giống hệt. OneDrive coi đó là
"sửa xung đột đồng thời" và **tự đẻ một bản đánh số** mỗi lần chạy.

**Hành vi hiện tại:** mặc định tool CHỈ CẢNH BÁO (`CẢNH BÁO: ...`) rồi chạy tiếp nếu `--path` rơi vào
thư mục như vậy — nhận diện qua biến môi trường (`OneDrive`, `OneDriveConsumer`,
`OneDriveCommercial`) hoặc tên đường dẫn (`~/Library/CloudStorage/...`, `Dropbox`, `iCloud Drive`…).
`--forbid-cloud-sync-path` bật lại chế độ chặn (preflight fail trước khi đụng byte nào). Xem
`internal/cloudsync/cloudsync.go`. **Lệnh cũ vẫn "chạy được" nhưng rủi ro nhân bản file còn nguyên — đừng dùng.**

### ✅ Cách khuyến nghị — `--push-to` (một lệnh, vẫn NHANH)

`--path` vẫn là thư mục LOCAL THUẦN (giữ nguyên state/incremental, qua được preflight), thêm
`--push-to=<thư mục OneDrive>` — tool tự đẩy phần vừa tải sang đó ngay sau khi mirror xong, trong
cùng một lệnh:

```powershell
.\gdrive-mirror.exe @folderIds `
  --path="D:\GDrive-Mirror" `
  --push-to="C:\Users\KUN\OneDrive - VISUAL WEBER COMPANY LIMITED" `
  --creds="C:\Tools\gdrive\creds.json" --concurrency=8 --list-concurrency=8
```

**Vì sao an toàn hơn mà vẫn nhanh:**
- Bước tải (Drive → `--path`) KHÔNG đụng OneDrive — mọi rename/ghi-đè dồn dập xảy ra ở ổ local thuần.
- Bước đẩy (`--path` → `--push-to`) là MỘT thao tác gọn SAU khi tải xong hẳn — Windows dùng `robocopy /E /MT:8`, nơi khác dùng bộ copy Go tích hợp — **chỉ ghi file THẬT SỰ mới/đổi** (so size+mtime). Không có gì đổi → đẩy gần như tức thời.
- **Không xoá gì ở `--push-to`** (không `/MIR`, không `/PURGE`).
- Vẫn có rủi ro dư (giới hạn của chính cơ chế OneDrive khi client còn sống canh thư mục), nhưng đã loại bỏ nguyên nhân đo được: ghi lặp liên tục dồn dập vào thư mục sống.
- **Lưu ý:** `--push-to` chỉ chạy MỘT LẦN sau khi TẤT CẢ folder trong lệnh tải xong (`internal/mirror/push.go`, `main.go::maybePush`).

<details><summary>Cách thủ công 2 bước (không dùng --push-to) — để tham khảo/troubleshoot</summary>

```powershell
# 1) Mirror Drive -> ổ LOCAL THUẦN
.\gdrive-mirror.exe @folderIds --path="D:\GDrive-Mirror" --creds="C:\Tools\gdrive\creds.json" --concurrency=8
# 2) Đẩy một-chiều sang OneDrive, SAU KHI bước 1 xong hẳn (không song song, không /MIR)
robocopy "D:\GDrive-Mirror" "C:\Users\KUN\OneDrive - VISUAL WEBER COMPANY LIMITED" /E /MT:8 /R:2 /W:2
Write-Host "robocopy exit code: $LASTEXITCODE"   # 0-7 = thành công, >=8 = lỗi
```
</details>

---

## 7. Incremental, retry-failed, scan-duplicates

### 7.1 Incremental sync — `--incremental`

Mặc định mỗi lần chạy liệt kê lại **toàn bộ** cây rồi so delta từng file — đúng nhưng tốn cho cây
hàng nghìn item chạy cron mà đa số không đổi.

`--incremental` dùng [Drive Changes API](https://developers.google.com/workspace/drive/api/guides/manage-changes):
lần đầu vẫn liệt kê đầy đủ nhưng ĐỒNG THỜI lưu một *manifest* (toàn bộ cây đã biết) + *page token*.
Từ lần sau, tool hỏi Drive "có gì đổi kể từ token này" rồi **chỉ liệt kê lại đúng những folder Drive
báo có thay đổi**; phần còn lại giữ từ manifest, không tốn request.

```bash
gdrive-mirror <folderID> --path=/Volumes/WD-DATA1/GDrive-Mirror --incremental
```

State ở `<path>/../gdrive-mirror-reports/incremental/<folderTag>.json`. Manifest/token thiếu, hỏng,
hoặc Changes API lỗi → tự quay về liệt kê đầy đủ (không bao giờ fatal) và tự bootstrap lại — an toàn
bật/tắt tuỳ ý. Cùng file này cũng được **ghi ở mọi lần chạy** để phục vụ rename-tracking (§9).

**Giới hạn đã biết:** một folder đang được theo dõi mà chính nó bị đổi tên/di chuyển vẫn được xử lý
đúng (tool phát hiện đường dẫn cũ không khớp và liệt kê lại đúng các folder bị ảnh hưởng theo tầng,
không phải toàn cây) nhưng tốn thêm vài request phụ — không đáng kể với cây ít đổi cấu trúc gốc.

### 7.2 Chạy lại item lỗi — `--retry-failed`

Sau 1 lần chạy có item lỗi, report nằm ở `<path>/../gdrive-mirror-reports/` (§10). Trỏ `--retry-failed`
vào **thư mục report** để tool tự chọn `failed-*.json` **mới nhất**, hoặc vào 1 file cụ thể:

```bash
gdrive-mirror --retry-failed=/Volumes/WD-DATA1/gdrive-mirror-reports --path=/Volumes/WD-DATA1/GDrive-Mirror --creds=./creds.json --retry=10 --concurrency=4
gdrive-mirror --retry-failed=/Volumes/WD-DATA1/gdrive-mirror-reports/failed-a1b2c3d4-20260719-020000.json --path=... --creds=...
```
```powershell
.\gdrive-mirror.exe --retry-failed="D:\GDrive-Mirror-Reports" --path="D:\GDrive-Mirror" --creds="C:\Tools\gdrive\creds.json" --retry=10 --concurrency=4
```

Chế độ này **không liệt kê lại Drive** — chỉ tải đúng item trong report. Item `permanent: true`
(khoá, quá lớn để export, thiếu quyền) **mặc định bị bỏ qua**; thêm `--include-permanent` để thử lại cả chúng.

### 7.3 Phát hiện duplicate đã có từ trước — `--scan-duplicates`

Rename-tracking (§9) chỉ ngăn duplicate **từ nay về sau**. Cây đã mirror trước đó (hoặc từng lỗi giữa
đường) có thể có sẵn file local mồ côi — vd 1 Google Doc đổi tên, local còn `OldName.docx` VÀ `NewName.docx`.

```bash
gdrive-mirror <folderID> --path=/Volumes/WD-DATA1/GDrive-Mirror --scan-duplicates
```

Chạy **thay cho** sync bình thường — chỉ liệt kê Drive + quét `--path`, **không tải/di chuyển/xoá gì**.
Kết quả 2 nhóm:

- **XÁC NHẬN TRÙNG**: MD5 file local khớp đúng MD5 Drive báo cho 1 item hiện tại — chỉ với file thường; an toàn xoá tay.
- **NGHI NGỜ — CẦN TỰ SOÁT**: không so được bằng MD5 (file Google-native không có MD5 từ Drive — nhóm này gần như luôn là nơi duplicate `.docx`/`.xlsx`/`.pptx` rơi vào). Có gợi ý (cùng thư mục + cùng đuôi) nhưng KHÔNG khẳng định — tự mở so sánh trước khi xoá.

Exit code `1` khi có phát hiện, `0` khi sạch.

---

## 8. Chạy định kỳ

**macOS/Linux cron** (2h sáng; macOS cần cấp **Full Disk Access** cho `/usr/sbin/cron` để ghi ổ ngoài):

```cron
0 2 * * * /usr/local/bin/gdrive-mirror 0Bw6yYZTQJcm3aGoxTGJuY1p1ZU0 --path=/Volumes/WD-DATA1/GDrive-Mirror --creds=/Users/eugene/secure/creds.json --retry=10 --concurrency=6 >> /Users/eugene/gdrive-sync/cron.log 2>&1
```

**Linux systemd timer** (log qua journald) — `/etc/systemd/system/gdrive-mirror.service`:

```ini
[Unit]
Description=Mirror Google Drive folder to local
After=network-online.target

[Service]
Type=oneshot
Environment=GOOGLE_APPLICATION_CREDENTIALS=/etc/gdrive/creds.json
ExecStart=/usr/local/bin/gdrive-mirror 0Bw6yYZTQJcm3aGoxTGJuY1p1ZU0 --path=/mnt/backup/gdrive --retry=10 --concurrency=6
```

`/etc/systemd/system/gdrive-mirror.timer`:

```ini
[Unit]
Description=Run gdrive-mirror daily

[Timer]
OnCalendar=*-*-* 03:00:00
Persistent=true

[Install]
WantedBy=timers.target
```

```bash
sudo systemctl daemon-reload && sudo systemctl enable --now gdrive-mirror.timer
systemctl list-timers gdrive-mirror.timer && journalctl -u gdrive-mirror.service -f
```

**Windows Task Scheduler** (2h sáng):

```powershell
$action  = New-ScheduledTaskAction -Execute "C:\Tools\gdrive\gdrive-mirror.exe" `
  -Argument '0Bw6yYZTQJcm3aGoxTGJuY1p1ZU0 --path="D:\GDrive-Mirror" --creds="C:\Tools\gdrive\creds.json" --retry=10 --concurrency=6' `
  -WorkingDirectory "C:\Tools\gdrive"
$trigger = New-ScheduledTaskTrigger -Daily -At 2:00AM
Register-ScheduledTask -TaskName "GDriveMirror" -Action $action -Trigger $trigger -RunLevel Highest `
  -Description "Dong bo mot chieu Google Drive -> D:\GDrive-Mirror"
```

**Windows `.bat` double-click** (`C:\Tools\gdrive\sync.bat`):

```bat
@echo off
setlocal
set FOLDER=0Bw6yYZTQJcm3aGoxTGJuY1p1ZU0
set DEST=D:\GDrive-Mirror
set CREDS=C:\Tools\gdrive\creds.json
"C:\Tools\gdrive\gdrive-mirror.exe" %FOLDER% --path="%DEST%" --creds="%CREDS%" --retry=10 --concurrency=6
if %ERRORLEVEL% EQU 0 ( echo [OK] Dong bo hoan tat, khong co loi.
) else if %ERRORLEVEL% EQU 1 ( echo [CANH BAO] Co file loi - xem thu muc gdrive-mirror-reports.
) else ( echo [LOI] Sai tham so hoac thieu creds. )
pause
```

---

## 9. Behavior

(Ported from the PHP source — see its docblock for the full story.)

- **Delta sync**: `--force` always re-downloads; regular files compare size then MD5; Google-native files (no MD5) fall back to mtime. Fast path: when a local file's size AND mtime both match exactly what the last successful download recorded (mtime is set to Drive's own `modifiedTime` on every successful download — see `downloadItem`), the MD5 comparison is answered from that equality alone instead of re-hashing the whole file. On a large, mostly-unchanged tree this removes nearly all local disk I/O/CPU; any file whose mtime doesn't match (first download, content changed, filesystem without `Chtimes`) still gets a real hash, so correctness is never weakened.
- **Google-native → Office**: Docs→docx, Sheets→xlsx, Slides→pptx, Drawings→png, Apps Script→json. Shortcuts are skipped (pointers only). Any other native type (Forms/Sites/Maps/Jamboard…) fails permanent `fileNotDownloadable`. A native file's export name colliding with a sibling's literal name (Doc "Report" → `Report.docx` next to a real `Report.docx`) is caught by the SAME shared-namespace dedup as every other sibling collision (`list.go::resolveSiblingNames`) — the extension is appended BEFORE the collision key is computed.
- **Rename/move tracking** (mọi loại file): `buildPlan` giữ chỉ mục Drive-ID → đường-dẫn-local-lần-trước (`<reportDir>/incremental/<folderTag>.json`, duy trì ở **mọi** lần chạy). Khi một item đổi tên/di chuyển trên Drive (cùng ID, path mới), bản local cũ còn tồn tại và đích mới chưa có gì, tool **di chuyển tại chỗ** (`os.Rename`) thay vì tải bản mới và để lại bản cũ — tránh duplicate điển hình của Google-native (đổi tên → 2 file `.docx` cùng nội dung). Đổi tên **folder** dời nguyên cây con trong một lệnh. Mọi tình huống không chắc chắn (bản cũ mất, đích đã có file, 2 path trên 2 ổ khác nhau) rơi về hành vi cũ (tải mới, giữ bản cũ) — không bao giờ ghi đè/xoá. Summary hiện `Renamed (dời theo Drive, không tải lại): N`.
- **Error classification** (`internal/classify`): permanent errors (403 reasons like `exportSizeLimitExceeded`, `cannotExportFile`, `insufficientFilePermissions`…) never retried; everything else retries with exponential backoff (2s/4s/8s/…, capped 30s).
- **Local filesystem circuit breaker**: local I/O errors (disk unmounted/read-only/dead) are never retried and trip an abort after 20 consecutive hits (atomic counter, reset on any success).
- **Preflight write-probe**: before listing, a real file is written/read/deleted at the destination — catches a disk that reports writable but errors on real I/O. Skipped in `--dry-run`.
- **Downloads are atomic**: written to a fixed-length hidden temp file next to the target (`.` + 8 hex of `sha256(targetPath)` + `-<runID>` + `.tmp`), renamed into place only on full success — a crash mid-transfer never leaves a truncated file. Temp name length never depends on the target's name length.
- **Local names are sanitized on EVERY OS** (a mirror built on macOS/Linux must stay openable on Windows) — `internal/localpath.SanitizeComponent`:
  - Characters NTFS refuses (`< > : " | ? * \ /`) and control chars → `_`.
  - Unicode form is **left as Drive gave it** (NFC/NFD are NOT rewritten) — rewriting every name renamed 200+ existing files and pushed them to OneDrive as new files next to their old copies. The NFC/NFD problem is handled in the collision pass below instead.
  - Leading/trailing spaces and trailing dots are trimmed (SharePoint/OneDrive reject leading spaces; Windows drops trailing ones).
  - A name empty after the above (including bare `.`/`..`) → `_`.
  - Windows-reserved device names (`CON`, `PRN`, `AUX`, `NUL`, `COM1-9`, `LPT1-9`) get `_` appended (`CON.txt` → `CON_.txt`).
  - Capped at **255 UTF-8 bytes** per component (`TruncateComponent`), cutting only on a rune boundary and appending a 6-hex hash of the original name when a real cut happens, so two different long names never collapse onto one.
- **Name collisions resolved at LISTING time**, one shared namespace across files AND folders in the same parent. Decision order is by **Drive file ID ascending** — never `files.list` response order (not stable) — so the same Drive items always produce the same local names. The loser gets the first 8 chars of its file ID before the extension (`report.pdf` → `report_1JP7CIBW.pdf`); the dedup key (`localpath.CollisionKey`) is lower-cased (NTFS/APFS case-insensitive) **and folded to Unicode NFC**: Drive keeps names byte-for-byte, so the same Vietnamese text can exist as two items — one NFC (typed on Windows), one NFD (uploaded from macOS). NTFS stores them as two files but OneDrive/SharePoint treats them as ONE name, so each `--push-to` wrote that name from two sources and OneDrive minted a `-<machine>-N` conflict copy every run (real incident 2026-10-27, 183+ copies). Keyed in NFC the pair collides, one gets a `_<id>` suffix and `name-duplicates/` reports it; a name with no partner is never touched. Every collision increments `Stats.Collisions` and logs a warning with old/new name and file ID.
- **Every resolved local path is re-validated against `--path`** (`internal/localpath.SafeJoin`) right before any directory/file is created — defense-in-depth against a Drive name containing `/` or `..`. A rejection is a permanent failure (goes to the failed-items report) and never counts toward the circuit breaker.
- **Listing "verify on zero"**: a folder reported empty is re-checked once after 1s — `files.list` has been observed to return an empty page for a folder that has children under API load.
- **Listing shrink-guard**: after a normal (non-`--dry-run`) listing, the folder's item count is compared with the previous run's (`<path>/../gdrive-mirror-reports/state/<folderTag>.json`: `folder_id`/`item_count`/`listed_at`/`run_id`, same fields as the PHP source so either implementation can read the other's). If the count dropped below **80%**, the run **aborts that folder** (no download, state untouched) — far more likely "Drive API hiccup / access revoked" than a real 20%+ deletion. `--ignore-shrink` when the drop is real. No prior/corrupt state never aborts. Skipped in `--dry-run`. Ports `shouldAbortOnShrink()`/`readListingState()`/`writeListingState()`.
- **Multiple folder IDs in one invocation**: each synced independently and sequentially with own subfolder, `runID`, report files. One folder erroring (or its shrink guard tripping) does **not** stop the rest — except when the circuit breaker trips (a dead destination disk would fail every remaining folder too). Exit `1` if **any** folder had item errors or was aborted.
- **`--retry-failed`**: skips listing entirely and downloads only items from a prior `failed-*.json`. Permanent items skipped unless `--include-permanent`. Every item still goes through `SafeJoin` — a hand-edited report is not trusted input.

### Upgrading an existing mirror (one-time, read this first)

Name sanitizing changes what some files are *called* on disk. Because this tool **never deletes local
files**, the first run after upgrading leaves the old copy and downloads the new name alongside it — a
duplicate, not a loss. Only files whose Drive name contained something unsafe are affected (forbidden
chars, leading/trailing dots/spaces, reserved device name, over 255 bytes, case-only clash, or an NFC/NFD
twin pair). A mirror
of ordinary names re-runs as a normal no-op delta sync. Recommended: run once, diff against the
previous run (or check `Collisions` in the summary), delete stale originals by hand.

### Known gaps vs. the PHP source

- Path-based folder identifiers ("Parent/Child") — service-account mode never supported these in the PHP source either (Folder ID only).

---

## 10. Reports

When any item fails, reports are written to `<path>/../gdrive-mirror-reports/` (vd `--path=/Volumes/WD-DATA1/GDrive-Mirror` → `/Volumes/WD-DATA1/gdrive-mirror-reports/`):

- `failed-<folderTag>-<YYYYMMDD-HHMMSS>.json` — full detail per item (path/id/mimeType/md5/size/reason/permanent/category/error_reason), the exact shape `--retry-failed` reads back in. Only the 10 most recent kept.
- `<ddmmYYYY_HHMMSS>.csv` — Excel-safe (UTF-8 BOM, `;` delimiter — plain `,` is misread as a decimal separator under VN locale), columns `file;path;size;category;error_reason;permanent;drive_link`, sorted permanent-first, then category, then size descending. `permanent=true` = retry cũng vô ích (Forms/Sites không export được, quá lớn, thiếu quyền) → xử lý tay qua `drive_link`.
- `<ddmmYYYY_HHMMSS>.xlsx` — same columns/sort as the CSV, as a real `.xlsx` (Go stdlib only: `archive/zip` + `encoding/xml`). Only the 10 most recent kept.
- `unexportable/<folderTag>.md` — Markdown manifest of every **permanent** failure for that folder, grouped by error reason, with human-readable size and manual-download link. **Always overwritten**, reflects the folder's *current* state; deleted once no permanent failures remain.
- `name-duplicates/<folderTag>.md` — triage list of **direct siblings that are real duplicates on Drive**: (1) identical original (pre-sanitize) Drive name (different IDs, often different content — a human "Save As"-ing the same name twice), or (2) **a Google-native Sheet/Doc/Slide whose export name equals a regular file's name** in the same folder (see [§11.7](#117-duplicate-thật-trên-drive--nguyên-nhân-đã-xác-nhận)). Both sides are still mirrored safely (the collision suffix never overwrites/merges) — the report only helps a human compare and decide. **Deliberately excludes** collisions caused only by sanitization (`a:b.pdf` vs `a_b.pdf`) — two genuinely different files. Same lifecycle as `unexportable/`.
- `state/<folderTag>.json` — shrink-guard baseline for the NEXT run. Written after a normal (non-`--dry-run`, non-`--retry-failed`) listing the guard accepted; never when it aborts, so a bad listing can't poison the next comparison.
- `incremental/<folderTag>.json` — known-item manifest + Drive Changes API page token (§7.1, and rename-tracking §9). Same "never written when the guard aborts" rule.

---

## 11. Vận hành thật: Windows → OneDrive

Cấu hình cụ thể đã chốt và đang chạy: mirror 20 folder Google Drive của VisualWeber vào
`OneDrive - VISUAL WEBER COMPANY LIMITED` trên một máy Windows chỉ có ổ `C:`. Mục này ghi **lý do
từng quyết định** để không phải suy luận lại.

### 11.1 Staging local — giữ vĩnh viễn, không xoá (đổi chiến lược 2026-10-26)

Chiến lược BAN ĐẦU (tiết kiệm đĩa): lặp từng folder ID và **xoá `C:\GDrive-Mirror` ngay sau khi push
xong mỗi folder** để đĩa ở mức ~1x thay vì ~2x.

**Sự cố thật khiến chiến lược này bị HUỶ:** xoá staging làm mất baseline delta-sync → MỖI lần chạy
phải tải/export lại TOÀN BỘ file. Với file Google-native, **export của Google KHÔNG ổn định
byte-for-byte giữa các lần export** — xác nhận thật trên 1 file `.pptx` (cùng 19 slide, cùng nội
dung, byte khác nhau mỗi lần — chỉ khác cách đánh số lại ảnh/font nhúng). `robocopy` coi là "file đã
đổi" và ghi đè OneDrive → OneDrive sinh bản `<tên>-DESKTOP-<tên-máy>-N.ext` mỗi lần — rác xung đột
thật tăng dần trên dữ liệu khách hàng.

**Quyết định hiện hành: KHÔNG xoá staging.** `C:\GDrive-Mirror` giữ vĩnh viễn làm baseline. Drive
không đổi → delta-sync (mtime cho Google-native, size+MD5 cho file thường) tự skip → không có gì để
push lại → hết xung đột giả. Đánh đổi: ~**15 GB** cho 19 folder (đo 2026-10-26; máy còn 370+ GB). Script
vẫn **lặp từng folder ID** để 1 folder lỗi fatal không cản các folder khác và thấy tiến độ theo folder.

Nếu THẬT SỰ cần tiết kiệm đĩa tối đa: có thể khôi phục cách xoá staging cũ (xem lịch sử git file
này) nhưng phải **chấp nhận xung đột OneDrive lặp lại cho mọi file Google-native** mỗi lần chạy — không khuyến nghị.

### 11.2 Menu vận hành — `run-mirror.bat` + `run-mirror.ps1`

**Mở `C:\Tools\gdrive\run-mirror.bat` (double-click) → hiện menu → gõ số → Enter.** Script tự lặp từng
folder, ghi log, in tổng kết. Không phải nhớ flag.

| Mục | Làm gì | Push OneDrive? | Ghi trạng thái resume? |
| --- | --- | --- | --- |
| `[1]` Chạy tiếp | Chỉ folder **chưa xong** (resume) | có | có |
| `[2]` Chạy lại TẤT CẢ | Bỏ qua resume; rẻ nhờ delta-sync | có | có |
| `[3]` Chọn folder | Chạy riêng vài folder (`1,3,5-7` / `all`) | có | có |
| `[4]` Dry-run | `--dry-run`: liệt kê thử 20 item, không tải | không | không |
| `[5]` Test nhanh | `--limit=N` (N **item**, không phải N file — §5.2) | không | không |
| `[6]` Chạy lại item LỖI | `--retry-failed` từ report lần trước (hỏi có thêm `--include-permanent`) | có | không |
| `[7]` Tải lại toàn bộ | `--force`, phải gõ `yes` xác nhận | có | có |
| `[8]` Quét duplicate | `--scan-duplicates`, chỉ đọc | không | không |
| `[9]` / `[C]` / `[L]` / `[R]` | Trạng thái folder / xem cấu hình đang dùng / 40 dòng cuối log mới nhất / mở thư mục report | — | — |
| `[X]` | Đổi tên `completed-folders.txt` thành `.bak` (staging + OneDrive không bị đụng) | — | — |

**Chọn `[2]` hay `[7]`?** Muốn sync lại dữ liệu → **`[2]`**: file đã khớp Drive được bỏ qua (so size/MD5, mtime với
Google-native), chỉ tải phần mới hoặc đổi, rẻ và an toàn. `[7]` (`--force`) tải lại **cả file không đổi**: tốn băng thông, và file
Google-native export ra byte khác nhau mỗi lần nên `robocopy` ghi đè OneDrive → OneDrive sinh bản xung đột `-DESKTOP-...` (§11.1).
Chỉ dùng `[7]` khi nghi file local hỏng, và chọn đúng folder nghi ngờ thay vì cả 20. Ghi chú này cũng hiện ngay trong menu.

Các mục `[1]`–`[3]`, `[5]`, `[7]`–`[8]` hỏi thêm **flag tuỳ ý** (vd `--incremental`, `--ignore-shrink`,
`--list-concurrency=16`, `--forbid-cloud-sync-path`) — Enter để bỏ qua. Chạy không cần menu (Task
Scheduler): `run-mirror.bat 1` hoặc `run-mirror.bat 2` (exit code `0` sạch / `1` có lỗi / `2` thiếu file).

**Các file trên máy vận hành** (`C:\Tools\gdrive\`):

| File | Trong git? | Vai trò |
| --- | --- | --- |
| `run-mirror.bat` | có | Cổng mở menu; tự tìm `run-mirror.ps1` (biến `GDRIVE_PS1` → cạnh `.bat` → `C:\Tools\gdrive\`) |
| `run-mirror.ps1` | có | Menu + logic chạy (đường dẫn staging/OneDrive/song song/retry là tham số ở đầu file) |
| `find-name-twins.ps1` | có | Quét/dọn cặp trùng tên NFC/NFD ở staging và OneDrive (mặc định chỉ báo cáo; `-Apply` mới chuyển vào archive) — §11.7 |
| `folders.txt` | **không** (chứa ID thật) | Danh sách folder, mỗi dòng 1 ID, ghi chú sau `#`. Mẫu: `folders.example.txt` |
| `settings.txt` | **không** (chứa tên user/tổ chức) | **Đường dẫn và thông số**: `Staging`, `PushTo`, `Concurrency`, `Retry`. Mẫu: `settings.example.txt`. Bấm `[C]` trong menu để xem cấu hình đang dùng |
| `gdrive-mirror.exe`, `creds.json` | exe có (`dist/`), creds **không** | Binary và service-account |
| `logs\` | không | `run-<timestamp>.log` mỗi lần + `completed-folders.txt` |

Dựng lại máy mới: copy `run-mirror.bat`, `run-mirror.ps1`, exe từ `dist/`; tạo `folders.txt` và
`settings.txt` từ các file `*.example.txt`; đặt `creds.json`.

**Đổi đường dẫn = sửa `settings.txt`** (không sửa code, không sửa `.bat`). Thứ tự ưu tiên: tham số
dòng lệnh của `run-mirror.ps1` (`-Staging`, `-PushTo`, `-Concurrency`, `-Retry`, `-Base`) > `settings.txt` > mặc định
(`Staging=C:\GDrive-Mirror`, `Concurrency=8`, `Retry=10`). **`PushTo` không có mặc định** (là đường dẫn OneDrive
của từng máy): thiếu hoặc trỏ tới thư mục không tồn tại thì script báo lỗi và thoát với exit code `2`.
`Staging` **giữ vĩnh viễn** (§11.1). `Base` mặc định là thư mục chứa `run-mirror.ps1` nếu có `folders.txt`/`settings.txt`
cạnh nó, không thì `C:\Tools\gdrive`.

### 11.3 Resume — `completed-folders.txt`

Script ghi mỗi folder ID có exit code 0/1 (đã PUSH xong, kể cả khi vài item lỗi permanent) vào
`C:\Tools\gdrive\logs\completed-folders.txt`; chạy lại tự **bỏ qua** các ID đó. Vì staging không bị
xoá, việc này chủ yếu để đỡ log trùng/tiết kiệm thời gian (delta-sync vốn rẻ) — không còn sống còn.

**Lỗi đã biết — "xong giả":** folder ID sai hoặc chưa share cho service account → tool liệt kê **0
item** nhưng vẫn **exit code 0** (Drive coi là listing rỗng hợp lệ) → script ghi ID vào
`completed-folders.txt` như đã xong, nên **share quyền xong chạy lại vẫn bị SKIP oan** (gặp thật
2026-10-26 với `Projects Data - Internal`). **Nhận biết:** trong log, khối `===== Folder <id> =====`
có `Found 0 item(s)` NGAY SAU `WARN: Could not resolve folder name ... 404`. **Xử lý:** xoá đúng dòng
ID khỏi `completed-folders.txt` ([§11.6](#116-lệnh-vận-hành-nhanh)) rồi chạy lại.

### 11.4 Path dài (MAX_PATH) và exit code của script

**MAX_PATH:** khi script cũ còn xoá staging bằng `Remove-Item -Recurse -Force`, nó chết giữa chừng ở
cây con sâu nhiều tầng tên tiếng Việt (path > 260 ký tự): `Remove-Item` đi qua .NET `System.IO`,
KHÔNG lách MAX_PATH như Go. **Đây KHÔNG phải bug của `gdrive-mirror.exe`** — Go trên Windows tự
prefix `\\?\` (xem comment `internal/localpath/localpath.go` dòng 38-41). Bug chỉ ở PowerShell
ad-hoc (`Remove-Item`/`Move-Item`) thao tác lại trên cùng cây.

Kỹ thuật xoá an toàn với path dài (cũng dùng khi archive duplicate, §11.7):

```powershell
$empty = Join-Path $env:TEMP "tmp-empty-$(Get-Random)"
New-Item -ItemType Directory -Path $empty -Force | Out-Null
robocopy $empty "<thư mục cần xoá sạch nội dung>" /purge /NFL /NDL /NJH /NJS /R:1 /W:1
# exit code robocopy 0-7 = thành công, >=8 = lỗi thật
Remove-Item "<thư mục đó>" -Force   # giờ rỗng, an toàn xoá vỏ
Remove-Item $empty -Recurse -Force -ErrorAction SilentlyContinue
```

`Move-Item`/`Rename-Item` một FILE LẺ vào `_duplicates_legacy\...` cũng có thể vượt 260 ký tự nếu tên
gốc đã dài (gặp thật với tên 86+ ký tự) → **dùng tên archive NGẮN** (vd `DUP_<ngày>_<tên-rút-gọn>.ext`).

**Exit code của script:** không đặt `exit` tường minh thì exit code của `powershell.exe` bị rò từ
lệnh native cuối (robocopy có thang riêng: 0-7 đều thành công) → một lần chạy ổn vẫn có thể báo `2`/`3`
cho Task Scheduler. Script đặt `exit` tường minh: `0` = mọi folder sạch, `1` = có folder lỗi item hoặc exception.

### 11.5 Build lại + deploy khi có code mới

```powershell
cd C:\Users\KUN\Desktop\laravel-cms-kernel
git pull --ff-only origin v7.x

cd tools\gdrive-mirror-cli
go vet ./...
go test ./...

# Cross-compile cả 5 target rồi ghi SHA256SUMS
$targets = @(
  @{os="darwin";  arch="arm64"; out="dist\gdrive-mirror-darwin-arm64"},
  @{os="darwin";  arch="amd64"; out="dist\gdrive-mirror-darwin-amd64"},
  @{os="linux";   arch="amd64"; out="dist\gdrive-mirror-linux-amd64"},
  @{os="linux";   arch="arm64"; out="dist\gdrive-mirror-linux-arm64"},
  @{os="windows"; arch="amd64"; out="dist\gdrive-mirror-windows-amd64.exe"}
)
foreach ($t in $targets) {
  $env:CGO_ENABLED = "0"; $env:GOOS = $t.os; $env:GOARCH = $t.arch
  go build -trimpath -ldflags="-s -w" -o $t.out .
}
Remove-Item Env:\GOOS, Env:\GOARCH, Env:\CGO_ENABLED
cd dist
Get-ChildItem "gdrive-mirror-*" | ForEach-Object {
  "$((Get-FileHash -Algorithm SHA256 -Path $_.FullName).Hash.ToLower())  $($_.Name)"
} | Out-File SHA256SUMS -Encoding ascii

cd ..\..\..
git add tools/gdrive-mirror-cli/dist/
git commit -m "build(gdrive-mirror-cli): rebuild dist/ binaries for <commit ngắn>"
git push origin v7.x
```

Copy binary Windows mới sang máy vận hành:

```powershell
$src = "C:\Users\KUN\Desktop\laravel-cms-kernel\tools\gdrive-mirror-cli\dist\gdrive-mirror-windows-amd64.exe"
Copy-Item $src "C:\Tools\gdrive\gdrive-mirror.exe" -Force
Copy-Item $src "C:\Tools\gdrive\gdrive-mirror-windows-amd64.exe" -Force
```

**Đừng rebuild/copy đè khi `run-mirror.ps1` đang chạy** — Windows không cho ghi đè exe đang mở. Đợi
script xong (xem log mới nhất trong `C:\Tools\gdrive\logs`).

### 11.6 Lệnh vận hành nhanh

**Chạy: double-click `C:\Tools\gdrive\run-mirror.bat` rồi chọn số trong menu** (xem [§11.2](#112-menu-vận-hành--run-mirrorbat--run-mirrorps1)). Không cần nhớ lệnh dưới đây — chúng chỉ là bản thủ công tương đương.

```powershell
# Thêm 1 folder mới: (1) lấy client_email, (2) share folder cho email đó (Viewer),
# (3) thêm 1 dòng ID vào C:\Tools\gdrive\folders.txt, (4) mở menu, chọn [1] — chỉ folder mới chạy
Select-String '"client_email"' C:\Tools\gdrive\creds.json

# Chạy lại 1 folder đang bị SKIP: menu [3] (chọn folder) — hoặc thủ công:
$stateFile = "C:\Tools\gdrive\logs\completed-folders.txt"
$idToRetry = "<folder-id-can-chay-lai>"
(Get-Content $stateFile | Where-Object { $_ -ne $idToRetry }) | Set-Content $stateFile

# Chạy lại TOÀN BỘ: menu [2] — hoặc menu [X] (đổi tên state thành .bak) rồi [1]. Vẫn RẺ nhờ delta-sync + staging còn nguyên.

# Log mới nhất: menu [L] — hoặc thủ công / tìm cảnh báo trùng tên THẬT / report duplicate:
Get-ChildItem C:\Tools\gdrive\logs\run-*.log | Sort-Object LastWriteTime -Descending | Select-Object -First 1
Select-String "Trùng tên THẬT" <đường-dẫn-log>
Get-ChildItem C:\gdrive-mirror-reports\name-duplicates\
```

### 11.7 Duplicate thật trên Drive — nguyên nhân đã xác nhận

**Tình huống điều tra 2026-10-26:** `Dịch Vụ Hosting - Domains.xlsx` trong `Accounting/Sổ Thu Chi`,
xoá trên OneDrive 3 lần vẫn quay lại. Nghi ngờ (1) lỗi Unicode NFC/NFD, (2) OneDrive tự phục hồi do
race condition, (3) thiết bị khác đồng bộ cùng thư viện — **cả 3 SAI cho file này** (NFC/NFD thì đúng cho
một trường hợp KHÁC, xem cuối mục). Nguyên nhân thật (xác nhận
bằng cách mở trực tiếp Google Drive): folder có **CẢ một Google Sheet native tên `Dịch Vụ Hosting -
Domains` (không đuôi) LẪN một file Excel thật `Dịch Vụ Hosting - Domains.xlsx`** — export Sheet tự
thêm `.xlsx`, trùng tên y hệt. Đây là **2 Drive item khác nhau, cùng tồn tại VĨNH VIỄN** — mirror nào
cũng tải cả hai, collision, push lại. Xoá trên OneDrive chỉ xoá TRIỆU CHỨNG.

**Đã fix trong code:** `internal/mirror/list.go::logicalName()` — report `name-duplicates/` bắt đúng
cả 2 dạng (tên gốc y hệt; Google-native trùng tên file thường sau khi thêm đuôi export). Vẫn LOẠI TRỪ
trùng do sanitize ký tự.

**Trường hợp thứ hai, 2026-10-27 — trùng do Unicode NFC/NFD (có thật):** OneDrive có 183+ bản
`<tên>-EugeneLe’s MacBook Pro-N`, tăng đúng +3 sau MỖI lần push, và robocopy copy lại đúng 3 file đó ở
mọi folder (`New File`, cột `Extras` tăng 3 mỗi lần). Staging có 2 file hiển thị cùng tên nhưng khác
kích thước: một tên NFC (37 ký tự), một NFD (42 ký tự) — hai item Drive khác nhau. NTFS tách được, OneDrive
coi là một tên nên xung đột lặp mãi. Cách nhận biết trên đĩa: `$name.IsNormalized([Text.NormalizationForm]::FormC)`.
**Đã fix trong code:** khoá so trùng (`localpath.CollisionKey`) và `logicalName()` gập về NFC — CHỈ khoá, không
đổi tên file (test: `TestCollisionKey_FoldsCaseAndNormalization`,
`TestResolveSiblingNames_UnicodeNormalizationCollision`, `TestResolveSiblingNames_LoneNFDNameIsNotRewritten`).
Mỗi cặp thành 1 file tên thường + 1 file `_<id>`; tên không có cặp giữ nguyên.
**Bài học (2026-10-27):** bản đầu chuẩn hoá MỌI tên về NFC → rename-tracking dời 212 item trong staging, robocopy
đẩy ~480 file "mới" lên OneDrive cạnh bản tên NFD cũ (`Extras` 201→410, xung đột 196→242). Đã bỏ cách đó.
**Dời qua lại tên trong staging cũng tự nó đẻ xung đột:** chạy bản đã sửa để dời 212 item về lại tên NFD lại đẩy
thêm ~150 file và xung đột 242→379 — OneDrive coi NFC/NFD là một tên nên mỗi lần ghi từ nguồn thứ hai là một bản
`-N`. Quy tắc: **đừng đổi hàng loạt tên trong staging khi staging đã được push lên OneDrive**; thử trên Drive thật
bằng `--dry-run` (ghi vào `--path` tạm) trước, không chạy thật rồi mới xem.
**Dọn dẹp đã làm (2026-10-27):** 412 bản xung đột của file có trong mirror được CHUYỂN (không xoá) sang
`C:\gdrive-archive\onedrive-conflicts-20261027\`, giữ nguyên cây thư mục; danh sách + đường dẫn gốc ở
`C:\gdrive-mirror-reports\archived-onedrive-conflicts-20261027.csv` (muốn khôi phục: chuyển ngược theo cột
`archived` → `original`). Không đụng `_duplicates_legacy` và 66 bản xung đột không thuộc mirror. Còn lại 3 cặp
(Google Sheet/Doc gốc + file `.xlsx`/`.docx` upload tay trùng tên) cần xử lý TRÊN DRIVE theo quy trình dưới. **Các bản `-N` đã sinh ra từ trước KHÔNG tự
mất** (tool không xoá) — danh sách nằm ở `C:\gdrive-mirror-reports\onedrive-conflict-copies-<ngày>.csv`,
soát rồi xoá tay qua web SharePoint/OneDrive. Nên đồng thời xoá/đổi tên một trong hai item trùng trên Drive.

**Lần dọn thứ hai (2026-10-27) — OneDrive vẫn báo 63 lỗi "same name as another item":** quét thấy 507 nhóm cùng
tên khác dạng dấu (phần lớn là file con của 8 thư mục trùng; OneDrive gộp còn 63 dòng lỗi). Lần dọn trước chỉ xử lý
cặp file cùng thư mục và để nguyên cặp thư mục. Cách làm đã chạy, theo thứ tự:
1. 505 bản thừa chỉ có ở OneDrive (nội dung đều có trong staging, 0 file riêng của người dùng) → chuyển sang
   `C:\gdrive-archive\onedrive-twins-20261027\`.
2. Diễn tập `robocopy /L` báo 4 file "New" mà OneDrive đã có bản trùng tên khác dạng dấu — **push thật sẽ sinh lại lỗi**.
   Nguyên nhân: **staging cũng chứa 7 cặp trùng** (do các lượt đổi tên hôm đó). Tool không bao giờ xoá file local nên cặp
   đã lọt vào staging thì ở lại và bị push lên mãi.
3. Chuyển mọi biến thể của 7 nhóm khỏi CẢ staging lẫn OneDrive → chạy lại `[2]`: tool tải lại đúng tên Drive đang dùng,
   push chỉ copy 3 file. Quét lại: 0 cặp ở cả hai bên, `robocopy /L` 0 file mới, không có bản xung đột mới.

**Quy tắc rút ra: staging phải sạch cặp NFC/NFD TRƯỚC khi push.** Chọn bản nào cũng không quan trọng bằng việc để chính
tool tải lại (nó dùng đúng tên Drive); tự giữ một bản mà Drive lại trả bản kia thì lần chạy sau cặp trùng quay lại.
Script `find-name-twins.ps1` làm đúng các bước trên:

```powershell
powershell -File find-name-twins.ps1 -OneDrive "C:\Users\X\OneDrive - Cong ty"          # chỉ báo cáo, exit 3 nếu còn cặp
powershell -File find-name-twins.ps1 -OneDrive "C:\Users\X\OneDrive - Cong ty" -Apply   # chuyển vào archive (không xoá)
```

- Cặp trùng ngay trong **staging** → chuyển mọi biến thể ở cả hai bên, rồi chạy lại `[2]` để tải lại.
- Cặp chỉ ở **OneDrive** → giữ bản khớp từng byte với staging, chuyển các bản còn lại. Nhóm không bản nào khớp staging
  thì chỉ liệt kê, không đụng.
- Archive mặc định `C:\gdrive-archive\name-twins-<ngày>\{staging,onedrive}\…` + `manifest.csv` (cột `original`/`archived`,
  muốn khôi phục thì chuyển ngược). Test: dựng cây giả lập có đủ 5 tình huống (OneDrive-only, trùng trong staging, thư mục
  trùng, file thường/file riêng của người dùng, nhóm không khớp staging) — đã chạy report → `-Apply` → report lại.
- Sau khi dọn, đợi OneDrive đồng bộ rồi kiểm tra màn hình **Sync issues**; script chỉ thấy đĩa, không đọc được trạng thái đó.

**Quy trình xử lý 1 cặp từ report:**
1. Mở `C:\gdrive-mirror-reports\name-duplicates\<folderTag>.md`, lấy 2 link Drive của cặp.
2. Mở Google Drive — icon loại file (Sheet/Doc/Slide native khác icon Excel/Word thường) cho biết đây là "khác định dạng" hay "trùng tên thật cùng định dạng".
3. **Xoá/đổi tên TRÊN GOOGLE DRIVE** (không phải OneDrive) — nơi duy nhất xoá được vĩnh viễn.
4. Chạy lại `run-mirror.ps1` cho đúng folder đó (xoá entry khỏi `completed-folders.txt` nếu cần) để xác nhận report không còn liệt kê cặp đó.

---

## 12. Troubleshooting

| Triệu chứng | Nguyên nhân & cách xử lý |
| --- | --- |
| `error: no credentials file found` | Sai đường dẫn `--creds`, hoặc env chưa set. Kiểm tra file tồn tại và không phải thư mục. |
| Auth OK nhưng liệt kê rỗng | Chưa share thư mục Drive cho `client_email` của service account (Viewer). Nhớ [§11.3](#113-resume--completed-folderstxt): exit 0 giả khiến script vận hành ghi "xong". |
| `preflight: --path (...) nằm trong thư mục do OneDrive/Google Drive/... đồng bộ SỐNG` | Chỉ xảy ra với `--forbid-cloud-sync-path`. Đổi `--path` ra ổ local thuần + `--push-to=<thư mục OneDrive>` ([§6](#6--mirror-vào-onedrivegoogle-drivedropbox--dùng---push-to)). |
| Dừng ngay ở preflight | Đích không ghi được thật (ổ chưa mount / read-only / hỏng I/O). Cắm lại ổ rồi chạy lại. |
| Chạy dừng giữa chừng sau nhiều lỗi | Circuit breaker cắt sau **20 lỗi I/O local liên tiếp** — ổ đĩa có vấn đề, không phải mạng. |
| Nhiều item `permanent` | Loại file Google không export được (Forms/Sites/Maps/Jamboard) hoặc vượt hạn mức export — xem cột `error_reason` trong CSV. |
| Chạy lại tải lại từ đầu | Đang bật `--force`, hoặc (vận hành OneDrive) đã xoá staging — xem [§11.1](#111-staging-local--giữ-vĩnh-viễn-không-xoá-đổi-chiến-lược-2026-10-26). |
| Tên local khác tên trên Drive (`_` thay ký tự lạ, hoặc đuôi `_<8 ký tự ID>`) | Bình thường — sanitize trên MỌI OS + tự đổi tên khi 2 item trùng (kể cả chỉ khác hoa/thường, hoặc file trùng tên folder). Xem WARN `Trùng tên...` và cột `Collisions` cuối run. |
| Tên rất dài bị cắt, có `~xxxxxx` trước phần mở rộng | Bình thường — giới hạn 255 byte/thành-phần-tên; hash 6 ký tự giữ 2 tên dài khác nhau không bị gộp. |
| `git push` bị từ chối `GH007: ... private email address` | Email commit không phải email noreply của tài khoản GitHub. Lấy email noreply tại github.com/settings/emails (`<ID>+<username>@users.noreply.github.com`) hoặc dùng `Claude <noreply@anthropic.com>` (đã xác nhận chạy với repo này), `git config user.email "..."` rồi commit lại (không amend commit đã push). |
| `git push` bị từ chối `(fetch first)` / `! [rejected]` | Remote có commit mới. LUÔN `git fetch origin <branch>` + `git log HEAD..origin/<branch> --oneline` trước khi push; có commit mới → `git pull --rebase origin <branch>` rồi push lại. |
| Ghi log/file trực tiếp vào gốc `C:\` bị "Access denied" dù PowerShell thường | Một số môi trường agent/automation chặn ghi/xoá ở gốc ổ hệ thống. Đặt log vào thư mục con (`C:\Tools\gdrive\logs\...`). |
| `go build` báo "file ... being used by another process" | `run-mirror.ps1` (hoặc chạy tay) đang chạy exe đó. Đợi xong rồi build lại ([§11.5](#115-build-lại--deploy-khi-có-code-mới)). |
| Thêm folder ID mới rồi chạy lại nhưng bị SKIP dù chưa từng thành công | Exit 0 giả ([§11.3](#113-resume--completed-folderstxt)). Xoá dòng ID khỏi `completed-folders.txt`; PHẢI share quyền Drive cho service account TRƯỚC. |
| OneDrive báo "Sync issues" với 1 file nhưng tên không có ký tự cấm/khoảng trắng đầu-cuối | Có thể là thông báo CŨ OneDrive chưa tự dọn UI (gặp thật 2026-10-26 với 3 file `{DEV}...`). Bấm "Rename"/"Resolve individually" trong dialog OneDrive — an toàn. |
| Archive 1 file trùng tên vào `_duplicates_legacy` lỗi "Could not find a part of the path" | Path đích > 260 ký tự — dùng tên archive NGẮN ([§11.4](#114-path-dài-max_path-và-exit-code-của-script)). |
| Đã archive/xoá 1 bản trùng tên trên OneDrive nhưng nó XUẤT HIỆN LẠI | **Kiểm tra trước: 2 file là 1 Google Sheet/Doc/Slide native + 1 file thường cùng tên gốc?** (nguyên nhân thật đã xác nhận, [§11.7](#117-duplicate-thật-trên-drive--nguyên-nhân-đã-xác-nhận)) → xoá trên OneDrive vô ích vĩnh viễn; xử lý tận gốc TRÊN GOOGLE DRIVE. Nếu không phải case này (cùng Drive ID/định dạng mà vẫn tái xuất hiện dù không mirror lại folder) — nghi thiết bị khác cùng đồng bộ thư viện còn giữ cache cũ, hoặc chính sách retention/compliance; xoá qua giao diện web SharePoint/OneDrive (server-side, propagate tới mọi thiết bị). |
| `--push-to` không xoá file đã bị xoá tay trên OneDrive — file "sống lại" sau lần mirror kế | Đúng thiết kế: `--push-to` đẩy MỘT CHIỀU, không nhận biết xoá (không `/MIR`/`/PURGE`). File còn trong staging/Drive thì lần push sau tạo lại. Muốn biến mất vĩnh viễn: xoá hẳn trên Drive gốc. |

---

## 13. Package layout

```
main.go                       CLI flag parsing + multi-folder/retry-failed orchestration entrypoint
internal/classify/            Pure error-classification + shrink-guard helpers (unit tested)
internal/localpath/           Pure name-sanitize/truncate/safe-join helpers (unit tested)
internal/cloudsync/           Pure detection of a --path inside a live OneDrive/Google Drive/
                               Dropbox/iCloud-watched folder (unit tested) — see §6
                               (push.go in internal/mirror/ is the --push-to companion)
internal/mirror/               List (concurrent, list.go)/delta/download/retry/circuit-breaker/
                               shrink-guard/worker-pool/incremental (Drive Changes API, incremental.go)
internal/report/              Failed-item JSON/CSV/XLSX report writers, unexportable manifest,
                               --retry-failed loader, listing-state + incremental-manifest read/write
run-mirror.bat / run-mirror.ps1  Menu vận hành Windows (§11.2)
find-name-twins.ps1           Quét/dọn cặp trùng tên NFC/NFD ở staging + OneDrive (§11.7)
dist/                         Binary dựng sẵn + SHA256SUMS
```

Lịch sử thay đổi code: `CHANGELOG.md` (gốc repo).
