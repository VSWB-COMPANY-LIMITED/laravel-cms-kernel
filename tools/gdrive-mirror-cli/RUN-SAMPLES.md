# gdrive-mirror — Lệnh chạy mẫu chi tiết theo từng hệ điều hành

Tài liệu này đi từ **build → chuẩn bị creds → chạy thử an toàn → chạy thật → chạy định kỳ**
cho macOS, Linux và Windows. Tham chiếu flag đầy đủ: [README.md](README.md).

> Nguyên tắc an toàn của tool: **một chiều Drive → local, KHÔNG BAO GIỜ xoá file local.**
> `--force` chỉ ghi đè, không xoá. Nên luôn chạy `--dry-run` trước ở một đích mới.

---

## 0. Chuẩn bị chung (mọi OS)

### 0.1 Lấy `<folderID>`

Mở thư mục trên Google Drive, copy đoạn ID trong URL:

```
https://drive.google.com/drive/folders/0Bw6yYZTQJcm3aGoxTGJuY1p1ZU0
                                       └────────── folderID ──────────┘
```

### 0.2 Service-account JSON

Tool **chỉ** dùng service-account (scope Drive read-only). Service account không có
"My Drive" → phải **share thư mục đích cho `client_email` của nó (quyền Viewer)** trước,
nếu không sẽ nhận lỗi permanent `insufficientFilePermissions`.

Lấy `client_email` từ file JSON:

```bash
# macOS / Linux
grep client_email creds.json
```

```powershell
# Windows PowerShell
Select-String client_email .\creds.json
```

### 0.3 Thứ tự tìm creds (ưu tiên từ trên xuống)

1. `--creds=<đường dẫn>`
2. biến môi trường `GOOGLE_APPLICATION_CREDENTIALS`
3. `./google-service-account-credentials.json` (thư mục đang đứng)

### 0.4 Exit code

| Code | Ý nghĩa |
| --- | --- |
| `0` | Chạy xong, không có item lỗi |
| `1` | Chạy xong nhưng **có item lỗi** (xem report), hoặc lỗi fatal (auth/preflight/đĩa) |
| `2` | Sai tham số (thiếu `<folderID>`, thiếu `--path`, không tìm thấy creds) |

Dùng exit code này cho cron/scheduler để cảnh báo.

### 0.5 Tool chạy 2 GIAI ĐOẠN — đọc kỹ để không hiểu nhầm khi nhìn log

```
Giai đoạn 1: LIỆT KÊ toàn bộ cây trên Drive (đệ quy, mỗi thư mục ≥1 API request,
             CHẠY SONG SONG theo --list-concurrency, mặc định 8 folder cùng lúc)
             → cây to (hàng nghìn thư mục) vẫn có thể mất VÀI PHÚT — nhanh hơn hẳn
               so với liệt kê tuần tự trước đây, nhưng KHÔNG tức thời
             → trong lúc này KHÔNG tải/ghi file nào; chỉ in các dòng WARN "Trùng tên"
               (thứ tự các dòng WARN này có thể xen kẽ do chạy song song — không sao,
               kết quả liệt kê cuối cùng vẫn đúng thứ tự depth-first như trước)
             → kết thúc bằng dòng:  Found N item(s).
Giai đoạn 2: TẢI file (lúc này mới đụng ổ đĩa)
             → mở màn bằng dòng:   Starting synchronization of X file(s)...
```

**Chưa thấy `Found N item(s).` = còn đang liệt kê, chưa tới lúc tải — cứ để chạy tiếp.**
Đừng tưởng tool treo khi thấy im lặng lâu / chỉ toàn WARN.

**Cây rất to, chạy định kỳ (cron/Task Scheduler)?** Cân nhắc `--incremental` (xem README) — sau
lần chạy đầu, các lần sau CHỈ hỏi Drive "có gì đổi" rồi liệt kê lại đúng phần đó thay vì toàn bộ
giai đoạn 1 mỗi lần.

#### `--limit=N` đếm ITEM, không phải N file

- `--limit` áp **SAU khi liệt kê xong toàn bộ cây** (cố ý — để shrink-guard [README.md](README.md)
  không hiểu nhầm cây bị teo). Nó **không** làm giai đoạn 1 nhanh hơn.
- "N item đầu" = N mục đầu theo **thứ tự duyệt sâu (depth-first), trộn lẫn cả thư mục lẫn
  file**. Thư mục chỉ được mkdir, không tải gì. Ví dụ cây `folder-a / sub-1 / file-x,
  file-y` → `--limit=5` lấy: `folder-a`, `sub-1`, `file-x`, `file-y`, … — tải được ít
  file hơn N, thậm chí 0 file nếu đầu cây toàn thư mục lồng nhau.
- Số file THẬT sẽ tải nằm ở dòng `Starting synchronization of X file(s)...` — nhìn X,
  đừng nhìn N.
- **Muốn test "tải thử vài file" đúng nghĩa:** đừng dùng `--limit` trên root to — mở Drive,
  vào một **thư mục con nhỏ** có sẵn file, copy ID từ URL và chạy với ID đó (liệt kê xong
  trong vài giây, thấy file về ngay). `--limit` chỉ hữu ích để chặn khối lượng, không phải
  để chọn đúng N file.

#### WARN `Trùng tên trong '...'` nghĩa là gì (và vì sao lần chạy nào cũng thấy lại)

- Cảnh báo này so sánh **các file trên Drive với nhau trong cùng 1 thư mục** — hoàn toàn
  KHÔNG liên quan file đã tải/convert ở lần chạy trước (giai đoạn 1 không hề nhìn ổ đĩa).
- Nó bắn ra khi **2 file KHÁC NHAU trên Drive** sẽ cho ra **cùng 1 tên local** — 2 nguồn:
  1. Drive cho phép 2 file trùng tên y hệt nằm cùng thư mục (filesystem thì không);
  2. tên chỉ khác nhau ở ký tự bị sanitize (vd `Th09-Th10:2021.pdf` và
     `Th09-Th10_2021.pdf` — dấu `:` cấm trên Windows nên đổi thành `_` → đụng nhau).
- Nếu không đổi tên thì một trong hai file **bị ghi đè mất im lặng** → tool tự gắn hậu tố
  `_<8 ký tự đầu Drive ID>` cho bản đến sau (deterministic — lần nào chạy cũng ra đúng tên
  đó, không đẻ thêm bản mới) + in WARN để bạn biết.
- WARN lặp lại mỗi run chừng nào **cặp trùng còn tồn tại trên Drive** — nó tường thuật
  hiện trạng Drive. Muốn hết WARN: dọn gốc trên Drive (xoá bớt 1 bản trong cặp — nên so
  md5/nội dung trước khi xoá).
- Chạy lại tool KHÔNG bao giờ tự sinh cảnh báo trùng tên: file đã có trên đĩa thì so
  md5/mtime → skip hoặc ghi đè đúng file cũ, không tạo "file (2)".

#### `--concurrency=N` là gì

Số file được **tải song song cùng lúc** (worker pool). Chỉ áp cho giai đoạn 2 (tải),
không ảnh hưởng tốc độ giai đoạn 1 (liệt kê). To hơn = nhanh hơn nếu mạng/ổ đích chịu
được; quá to trên HDD/USB chậm hoặc mạng yếu → dễ lỗi I/O, timeout, bị Drive throttle.
Khuyến nghị: SSD + mạng nhanh `8`; HDD/USB hoặc mạng yếu `2`–`4`; đang test `1`–`2`.

---

## 1. macOS

### 1.1 Build (Apple Silicon — M1/M2/M3/M4)

```bash
cd ~/Sites/dev-extensions/laravel-cms-kernel/tools/gdrive-mirror-cli
GOOS=darwin GOARCH=arm64 go build -o dist/gdrive-mirror-darwin-arm64 .
```

Mac Intel:

```bash
GOOS=darwin GOARCH=amd64 go build -o dist/gdrive-mirror-darwin-amd64 .
```

### 1.2 Cài vào PATH (tuỳ chọn, cho gọn lệnh)

```bash
sudo install -m 0755 dist/gdrive-mirror-darwin-arm64 /usr/local/bin/gdrive-mirror
gdrive-mirror --help
```

Nếu copy binary từ máy khác về (qua AirDrop/download) và macOS chặn Gatekeeper:

```bash
chmod +x gdrive-mirror
xattr -d com.apple.quarantine gdrive-mirror   # gỡ cờ cách ly
```

### 1.3 Bước 1 — dry-run (KHÔNG tải gì, chỉ liệt kê 20 item đầu)

```bash
cd ~/gdrive-sync
./gdrive-mirror 0Bw6yYZTQJcm3aGoxTGJuY1p1ZU0 \
  --path=/Volumes/WD-DATA1/GDrive-Mirror \
  --creds=./creds.json \
  --dry-run
```

Mục đích: xác nhận **auth OK + thấy đúng thư mục** trước khi ghi bất cứ thứ gì.
Dry-run **bỏ qua preflight write-probe** nên không cần ổ đích sẵn sàng.

### 1.4 Bước 2 — chạy thử giới hạn (`--limit=5` = 5 ITEM đầu, không phải 5 file — xem §0.5)

```bash
./gdrive-mirror 0Bw6yYZTQJcm3aGoxTGJuY1p1ZU0 \
  --path=/Volumes/WD-DATA1/GDrive-Mirror \
  --creds=./creds.json \
  --limit=5 \
  --concurrency=2
```

Kiểm tra file tải về mở được thật (Word/PDF/Excel), rồi mới chạy full.

### 1.5 Bước 3 — chạy thật (full, ổ cứng ngoài)

```bash
./gdrive-mirror 0Bw6yYZTQJcm3aGoxTGJuY1p1ZU0 \
  --path=/Volumes/WD-DATA1/GDrive-Mirror \
  --creds=/Users/eugene/secure/creds.json \
  --retry=10 \
  --concurrency=8
```

- `--concurrency=8`: mạng nhanh, ổ SSD. **Ổ HDD/USB chậm hoặc mạng yếu → để `4` hoặc `2`.**
- `--retry=10`: chịu được mạng chập chờn (backoff 2s/4s/8s… tối đa 30s).

### 1.6 Chạy lại (delta-sync) — đúng lệnh trên, chạy lại

Không cần flag gì thêm. File đã khớp size + MD5 (hoặc mtime với file Google-native)
sẽ được **skip**, chỉ tải phần thay đổi.

### 1.7 Ghi đè toàn bộ (khi nghi ngờ file local hỏng)

```bash
./gdrive-mirror 0Bw6yYZTQJcm3aGoxTGJuY1p1ZU0 \
  --path=/Volumes/WD-DATA1/GDrive-Mirror \
  --creds=./creds.json \
  --force --retry=10 --concurrency=4
```

### 1.8 Dùng biến môi trường thay `--creds`

```bash
export GOOGLE_APPLICATION_CREDENTIALS=/Users/eugene/secure/creds.json
./gdrive-mirror 0Bw6yYZTQJcm3aGoxTGJuY1p1ZU0 --path=/Volumes/WD-DATA1/GDrive-Mirror
```

### 1.9 Chạy nền + ghi log

```bash
nohup ./gdrive-mirror 0Bw6yYZTQJcm3aGoxTGJuY1p1ZU0 \
  --path=/Volumes/WD-DATA1/GDrive-Mirror --creds=./creds.json \
  --retry=10 --concurrency=8 \
  > ~/gdrive-sync/sync-$(date +%Y%m%d-%H%M%S).log 2>&1 &

tail -f ~/gdrive-sync/sync-*.log
```

### 1.10 Chạy định kỳ (launchd hoặc cron)

Cron đơn giản — 2h sáng mỗi ngày (`crontab -e`):

```cron
0 2 * * * /usr/local/bin/gdrive-mirror 0Bw6yYZTQJcm3aGoxTGJuY1p1ZU0 --path=/Volumes/WD-DATA1/GDrive-Mirror --creds=/Users/eugene/secure/creds.json --retry=10 --concurrency=6 >> /Users/eugene/gdrive-sync/cron.log 2>&1
```

> ⚠️ macOS: tiến trình chạy từ cron cần được cấp **Full Disk Access** cho `/usr/sbin/cron`
> (System Settings → Privacy & Security) mới ghi được vào ổ ngoài / thư mục bảo vệ.

### 1.11 Chạy lại các item lỗi (`--retry-failed`)

Sau 1 lần chạy có item lỗi, tool ghi report tại `<path>/../gdrive-mirror-reports/`
(xem mục 4). Trỏ `--retry-failed` thẳng vào **thư mục report** để tool tự chọn file
`failed-*.json` **mới nhất** — không cần tìm tên file thủ công:

```bash
./gdrive-mirror --retry-failed=/Volumes/WD-DATA1/gdrive-mirror-reports \
  --path=/Volumes/WD-DATA1/GDrive-Mirror \
  --creds=./creds.json \
  --retry=10 --concurrency=4
```

Hoặc trỏ thẳng vào 1 file report cụ thể (vd muốn retry lại report của 1 lần chạy
trước, không phải lần gần nhất):

```bash
./gdrive-mirror --retry-failed=/Volumes/WD-DATA1/gdrive-mirror-reports/failed-a1b2c3d4-20260719-020000.json \
  --path=/Volumes/WD-DATA1/GDrive-Mirror --creds=./creds.json
```

Chế độ này **không liệt kê lại Drive** — chỉ tải đúng các item nằm trong report.
Item `permanent: true` (không tự khỏi, vd file bị khoá/quá lớn để export) **mặc định
bị bỏ qua**; muốn thử lại cả chúng thì thêm `--include-permanent`:

```bash
./gdrive-mirror --retry-failed=/Volumes/WD-DATA1/gdrive-mirror-reports \
  --path=/Volumes/WD-DATA1/GDrive-Mirror --creds=./creds.json \
  --include-permanent
```

---

## 2. Linux

### 2.1 Build (static, chạy mọi distro — không phụ thuộc glibc)

```bash
cd tools/gdrive-mirror-cli
CGO_ENABLED=0 GOOS=linux GOARCH=amd64 go build -o dist/gdrive-mirror-linux-amd64 .
```

ARM (Raspberry Pi / server ARM64):

```bash
CGO_ENABLED=0 GOOS=linux GOARCH=arm64 go build -o dist/gdrive-mirror-linux-arm64 .
```

### 2.2 Đưa lên server + cài

```bash
scp dist/gdrive-mirror-linux-amd64 user@server:/tmp/gdrive-mirror
ssh user@server 'sudo install -m 0755 /tmp/gdrive-mirror /usr/local/bin/gdrive-mirror'
```

### 2.3 Bước 1 — dry-run

```bash
gdrive-mirror 0Bw6yYZTQJcm3aGoxTGJuY1p1ZU0 \
  --path=/mnt/backup/gdrive \
  --creds=/etc/gdrive/creds.json \
  --dry-run
```

### 2.4 Bước 2 — chạy thử giới hạn (`--limit=5` = 5 ITEM đầu, không phải 5 file — xem §0.5)

```bash
gdrive-mirror 0Bw6yYZTQJcm3aGoxTGJuY1p1ZU0 \
  --path=/mnt/backup/gdrive \
  --creds=/etc/gdrive/creds.json \
  --limit=5 --concurrency=2
```

### 2.5 Bước 3 — chạy thật

```bash
gdrive-mirror 0Bw6yYZTQJcm3aGoxTGJuY1p1ZU0 \
  --path=/mnt/backup/gdrive \
  --creds=/etc/gdrive/creds.json \
  --retry=10 --concurrency=8
```

Bảo vệ file creds trên server:

```bash
sudo chown root:root /etc/gdrive/creds.json && sudo chmod 600 /etc/gdrive/creds.json
```

### 2.6 Chạy lâu qua SSH — dùng `screen`/`tmux` để không đứt khi rớt mạng

```bash
tmux new -s gdrive
gdrive-mirror 0Bw6yYZTQJcm3aGoxTGJuY1p1ZU0 --path=/mnt/backup/gdrive \
  --creds=/etc/gdrive/creds.json --retry=10 --concurrency=8 | tee /var/log/gdrive-sync.log
# Ctrl-B rồi D để detach; quay lại: tmux attach -t gdrive
```

### 2.7 Cron (3h sáng hằng ngày)

```cron
0 3 * * * /usr/local/bin/gdrive-mirror 0Bw6yYZTQJcm3aGoxTGJuY1p1ZU0 --path=/mnt/backup/gdrive --creds=/etc/gdrive/creds.json --retry=10 --concurrency=6 >> /var/log/gdrive-sync.log 2>&1
```

### 2.8 systemd timer (thay cron, có log qua journald)

`/etc/systemd/system/gdrive-mirror.service`:

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
sudo systemctl daemon-reload
sudo systemctl enable --now gdrive-mirror.timer
systemctl list-timers gdrive-mirror.timer
journalctl -u gdrive-mirror.service -f
```

### 2.9 Chạy lại các item lỗi (`--retry-failed`)

Trỏ thẳng vào thư mục report — tool tự chọn file `failed-*.json` mới nhất:

```bash
gdrive-mirror --retry-failed=/mnt/backup/gdrive-mirror-reports \
  --path=/mnt/backup/gdrive \
  --creds=/etc/gdrive/creds.json \
  --retry=10 --concurrency=4
```

Trỏ thẳng vào 1 file report cụ thể:

```bash
gdrive-mirror --retry-failed=/mnt/backup/gdrive-mirror-reports/failed-a1b2c3d4-20260719-030000.json \
  --path=/mnt/backup/gdrive --creds=/etc/gdrive/creds.json
```

Retry cả item `permanent: true` (mặc định bị bỏ qua vì không tự khỏi):

```bash
gdrive-mirror --retry-failed=/mnt/backup/gdrive-mirror-reports \
  --path=/mnt/backup/gdrive --creds=/etc/gdrive/creds.json \
  --include-permanent
```

---

## 3. Windows

### 3.1 Build (cross-compile TỪ máy Mac/Linux — không cần máy Windows)

```bash
GOOS=windows GOARCH=amd64 go build -o dist/gdrive-mirror-windows-amd64.exe .
```

Build ngay trên Windows (PowerShell):

```powershell
cd tools\gdrive-mirror-cli
go build -o gdrive-mirror.exe .
```

Chép `gdrive-mirror-windows-amd64.exe` sang máy Windows, đổi tên `gdrive-mirror.exe`
cho gọn. Nếu SmartScreen chặn: chuột phải → Properties → tick **Unblock**.

### 3.2 PowerShell — bước 1: dry-run

```powershell
cd C:\Tools\gdrive
.\gdrive-mirror.exe 0Bw6yYZTQJcm3aGoxTGJuY1p1ZU0 `
  --path="D:\GDrive-Mirror" `
  --creds="C:\Tools\gdrive\creds.json" `
  --dry-run
```

> Backtick `` ` `` là ký tự nối dòng của PowerShell. Đường dẫn có dấu cách → **bắt buộc
> bọc nháy kép**: `--path="D:\Sao luu\GDrive"`.

### 3.3 PowerShell — bước 2: chạy thử giới hạn (`--limit=5` = 5 ITEM đầu, không phải 5 file — xem §0.5)

```powershell
.\gdrive-mirror.exe 0Bw6yYZTQJcm3aGoxTGJuY1p1ZU0 `
  --path="D:\GDrive-Mirror" `
  --creds="C:\Tools\gdrive\creds.json" `
  --limit=5 --concurrency=2
```

### 3.4 PowerShell — bước 3: chạy thật + ghi log

```powershell
$stamp = Get-Date -Format "yyyyMMdd-HHmmss"
.\gdrive-mirror.exe 0Bw6yYZTQJcm3aGoxTGJuY1p1ZU0 `
  --path="D:\GDrive-Mirror" `
  --creds="C:\Tools\gdrive\creds.json" `
  --retry=10 --concurrency=8 `
  *>&1 | Tee-Object -FilePath "C:\Tools\gdrive\sync-$stamp.log"

Write-Host "Exit code: $LASTEXITCODE"
```

### 3.5 Command Prompt (cmd.exe) — dùng `^` để nối dòng

```bat
cd /d C:\Tools\gdrive
gdrive-mirror.exe 0Bw6yYZTQJcm3aGoxTGJuY1p1ZU0 ^
  --path="D:\GDrive-Mirror" ^
  --creds="C:\Tools\gdrive\creds.json" ^
  --retry=10 --concurrency=8

echo Exit code: %ERRORLEVEL%
```

### 3.6 Biến môi trường thay `--creds`

```powershell
# chỉ trong phiên hiện tại
$env:GOOGLE_APPLICATION_CREDENTIALS = "C:\Tools\gdrive\creds.json"

# lưu vĩnh viễn cho user (mở lại PowerShell mới để có hiệu lực)
setx GOOGLE_APPLICATION_CREDENTIALS "C:\Tools\gdrive\creds.json"
```

### 3.7 Ổ mạng (UNC path)

```powershell
.\gdrive-mirror.exe 0Bw6yYZTQJcm3aGoxTGJuY1p1ZU0 `
  --path="\\NAS01\backup\gdrive" `
  --creds="C:\Tools\gdrive\creds.json" `
  --retry=10 --concurrency=4
```

> Ổ mạng chậm hơn ổ nội bộ → giữ `--concurrency` thấp (2–4) để tránh timeout.

### 3.8 File `.bat` chạy được bằng double-click

`C:\Tools\gdrive\sync.bat`:

```bat
@echo off
setlocal
set FOLDER=0Bw6yYZTQJcm3aGoxTGJuY1p1ZU0
set DEST=D:\GDrive-Mirror
set CREDS=C:\Tools\gdrive\creds.json

"C:\Tools\gdrive\gdrive-mirror.exe" %FOLDER% --path="%DEST%" --creds="%CREDS%" --retry=10 --concurrency=6

if %ERRORLEVEL% EQU 0 (
  echo [OK] Dong bo hoan tat, khong co loi.
) else if %ERRORLEVEL% EQU 1 (
  echo [CANH BAO] Co file loi - xem thu muc gdrive-mirror-reports.
) else (
  echo [LOI] Sai tham so hoac thieu creds.
)
pause
```

### 3.9 Task Scheduler (chạy tự động 2h sáng)

```powershell
$action  = New-ScheduledTaskAction -Execute "C:\Tools\gdrive\gdrive-mirror.exe" `
  -Argument '0Bw6yYZTQJcm3aGoxTGJuY1p1ZU0 --path="D:\GDrive-Mirror" --creds="C:\Tools\gdrive\creds.json" --retry=10 --concurrency=6' `
  -WorkingDirectory "C:\Tools\gdrive"

$trigger = New-ScheduledTaskTrigger -Daily -At 2:00AM

Register-ScheduledTask -TaskName "GDriveMirror" -Action $action -Trigger $trigger `
  -Description "Dong bo mot chieu Google Drive -> D:\GDrive-Mirror" -RunLevel Highest
```

### 3.10 Chạy lại các item lỗi (`--retry-failed`)

Trỏ thẳng vào thư mục report — tool tự chọn file `failed-*.json` mới nhất:

```powershell
.\gdrive-mirror.exe --retry-failed="D:\GDrive-Mirror-Reports" `
  --path="D:\GDrive-Mirror" `
  --creds="C:\Tools\gdrive\creds.json" `
  --retry=10 --concurrency=4
```

Trỏ thẳng vào 1 file report cụ thể:

```powershell
.\gdrive-mirror.exe --retry-failed="D:\GDrive-Mirror-Reports\failed-a1b2c3d4-20260719-020000.json" `
  --path="D:\GDrive-Mirror" --creds="C:\Tools\gdrive\creds.json"
```

Retry cả item `permanent: true` (mặc định bị bỏ qua vì không tự khỏi):

```powershell
.\gdrive-mirror.exe --retry-failed="D:\GDrive-Mirror-Reports" `
  --path="D:\GDrive-Mirror" --creds="C:\Tools\gdrive\creds.json" `
  --include-permanent
```

### 3.11 Nhiều folder ID — MỘT lệnh, không lặp `foreach` gọi N lần

Tool nhận **nhiều `<folderID>` trong cùng một lệnh** — mỗi folder vẫn có report/state/runID
riêng, một folder lỗi/bị shrink-guard chặn không cản các folder còn lại. Cách này thay cho việc
lặp `foreach ($id in $ids) { .\gdrive-mirror.exe $id ... }` gọi N tiến trình riêng (mỗi tiến
trình tự auth lại + tự dựng transport/HTTP pool riêng — tốn hơn không cần thiết):

```powershell
$folderIds = @(
  "0Bw6yYZTQJcm3RlI4RU1VaDhSTnc", "14YEPqieYBfCtIIpQ2rakfm5E1G7VVuDl",
  "0Bw6yYZTQJcm3QXFPUmNaVmlKWWs", "1JG-IHOBrjCqzo-x5eN1LcDFMyPBATv0z"
  # ... toàn bộ danh sách folder ID
)
.\gdrive-mirror.exe @folderIds `
  --path="D:\GDrive-Mirror" --creds="C:\Tools\gdrive\creds.json" `
  --concurrency=8 --list-concurrency=8
```

### 3.12 🔴 KHÔNG trỏ `--path` vào thư mục OneDrive đang sync sống

**Sự cố thật:** `--path="C:\Users\KUN\OneDrive - VISUAL WEBER COMPANY LIMITED"` — tool mirror
Google Drive **thẳng vào gốc OneDrive đang đồng bộ**. Cùng file, cùng size, cùng ngày sửa, cứ mỗi
lần chạy lại thêm MỘT bản trùng tên tăng dần trên SharePoint (`file.docx` → `file-2.docx` →
`file-3.docx` → … → `file-21.docx`) — vì mỗi lần tool tải-lại-rồi-ghi-đè (dù nội dung giống hệt),
OneDrive coi đó là sửa xung đột và tự đẻ bản mới thay vì hoà giải. Từ bản này, tool **từ chối
chạy** với `--path` kiểu này (xem README "KHÔNG mirror thẳng vào thư mục cloud-sync sống").

**Sửa đúng — 2 bước tách biệt:**

```powershell
# 1) Mirror Drive -> ổ LOCAL THUẦN (không phải OneDrive)
.\gdrive-mirror.exe @folderIds `
  --path="D:\GDrive-Mirror" --creds="C:\Tools\gdrive\creds.json" `
  --concurrency=8 --list-concurrency=8

# 2) Đẩy một-chiều sang OneDrive bằng robocopy /MIR, SAU KHI bước 1 chạy xong hẳn
robocopy "D:\GDrive-Mirror" "C:\Users\KUN\OneDrive - VISUAL WEBER COMPANY LIMITED" /MIR /R:3 /W:5
Write-Host "robocopy exit code: $LASTEXITCODE"   # 0-7 = thành công (xem `robocopy /?`), >=8 = lỗi
```

Có thể gộp cả 2 bước vào một script `.bat`/`.ps1` chạy theo lịch (Task Scheduler §3.9) — miễn
là bước 2 luôn chạy SAU khi bước 1 kết thúc (không chạy song song, không xen kẽ).

---

## 4. Đọc kết quả

Khi có item lỗi, tool ghi 2 report vào **thư mục cha của `--path`**:

```
<path>/../gdrive-mirror-reports/
  ├── failed-<folderTag>-<YYYYMMDD-HHMMSS>.json   # chi tiết đầy đủ, giữ 10 bản mới nhất
  └── <ddmmYYYY_HHMMSS>.csv                        # mở thẳng bằng Excel (UTF-8 BOM, phân cách ;)
```

Ví dụ `--path=/Volumes/WD-DATA1/GDrive-Mirror` → report nằm ở
`/Volumes/WD-DATA1/gdrive-mirror-reports/`.

Cột CSV: `file;path;size;category;error_reason;permanent;drive_link` — sắp xếp
**permanent trước**, rồi theo category, rồi size giảm dần. Cột `permanent=true`
nghĩa là retry cũng vô ích (vd Google Forms/Sites không export được, file quá lớn
để export, thiếu quyền) → cần xử lý tay qua `drive_link`.

---

## 5. Bảng chọn `--concurrency` nhanh

| Tình huống | Giá trị nên dùng |
| --- | --- |
| SSD nội bộ + mạng cáp quang | `8` |
| Ổ cứng ngoài USB / HDD | `4` (mặc định) |
| Ổ mạng NAS / SMB | `2`–`4` |
| Mạng yếu, hay rớt | `2` + `--retry=10` |
| Đang test | `1`–`2` + `--limit=5` |

`--list-concurrency` (liệt kê, mặc định `8`) là tham số RIÊNG, không cần đổi theo bảng trên — nó
chỉ gọi API metadata nhẹ, không đụng ổ đĩa/băng thông tải file, nên thường để mặc định là đủ; chỉ
hạ xuống nếu thấy Drive trả lỗi rate-limit (`429`/`userRateLimitExceeded`) dồn dập ở giai đoạn 1.

## 6. Xử lý sự cố nhanh

| Triệu chứng | Nguyên nhân & cách xử lý |
| --- | --- |
| `error: no credentials file found` | Sai đường dẫn `--creds`, hoặc biến env chưa set. Kiểm tra file tồn tại và không phải thư mục. |
| Auth OK nhưng liệt kê rỗng | Chưa share thư mục Drive cho `client_email` của service account (quyền Viewer). |
| `preflight: --path (...) nằm trong thư mục do OneDrive/Google Drive/... đồng bộ SỐNG` | Đúng như thông báo — xem README "KHÔNG mirror thẳng vào thư mục cloud-sync sống" §3.12 ở trên. Đổi `--path` ra ổ local thuần rồi dùng `robocopy /MIR` (Windows) một bước riêng sau. |
| Dừng ngay ở preflight | Đích không ghi được thật (ổ chưa mount / read-only / hỏng I/O). Tool ghi-thử file thật để bắt sớm — cắm lại ổ rồi chạy lại. |
| Chạy dừng giữa chừng sau nhiều lỗi | Circuit breaker cắt sau **20 lỗi I/O local liên tiếp** — ổ đĩa có vấn đề, không phải lỗi mạng. |
| Nhiều item `permanent` | Loại file Google không export được (Forms/Sites/Maps/Jamboard) hoặc vượt hạn mức export — xem cột `error_reason` trong CSV. |
| Chạy lại tải lại từ đầu | Đang bật `--force`. Bỏ flag đó đi để dùng delta-sync. |
| Tên file/folder local khác tên trên Drive (có `_` thay ký tự lạ, hoặc có đuôi `_<8 ký tự ID>` trước phần mở rộng) | Bình thường — tool sanitize tên trên MỌI OS (không chỉ Windows) để mirror di động được giữa Mac/Windows, và tự đổi tên khi 2 item trùng tên (kể cả trùng chỉ khác hoa/thường, hoặc 1 file trùng tên 1 folder). Xem log dòng `WARN: Trùng tên...` và cột `Collisions` ở cuối run. |
| Tên rất dài bị cắt ngắn, có thêm `~xxxxxx` trước phần mở rộng | Bình thường — giới hạn 255 byte/thành-phần-tên (NTFS). Hash 6 ký tự đảm bảo 2 tên dài khác nhau không bị gộp làm một sau khi cắt. |
