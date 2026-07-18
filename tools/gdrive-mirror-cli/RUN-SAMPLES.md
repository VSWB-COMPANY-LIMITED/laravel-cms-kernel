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

### 1.4 Bước 2 — tải thử 5 file

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

### 2.4 Bước 2 — tải thử 5 file

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

### 3.3 PowerShell — bước 2: tải thử 5 file

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

## 6. Xử lý sự cố nhanh

| Triệu chứng | Nguyên nhân & cách xử lý |
| --- | --- |
| `error: no credentials file found` | Sai đường dẫn `--creds`, hoặc biến env chưa set. Kiểm tra file tồn tại và không phải thư mục. |
| Auth OK nhưng liệt kê rỗng | Chưa share thư mục Drive cho `client_email` của service account (quyền Viewer). |
| Dừng ngay ở preflight | Đích không ghi được thật (ổ chưa mount / read-only / hỏng I/O). Tool ghi-thử file thật để bắt sớm — cắm lại ổ rồi chạy lại. |
| Chạy dừng giữa chừng sau nhiều lỗi | Circuit breaker cắt sau **20 lỗi I/O local liên tiếp** — ổ đĩa có vấn đề, không phải lỗi mạng. |
| Nhiều item `permanent` | Loại file Google không export được (Forms/Sites/Maps/Jamboard) hoặc vượt hạn mức export — xem cột `error_reason` trong CSV. |
| Chạy lại tải lại từ đầu | Đang bật `--force`. Bỏ flag đó đi để dùng delta-sync. |
| Tên file/folder local khác tên trên Drive (có `_` thay ký tự lạ, hoặc có đuôi `_<8 ký tự ID>` trước phần mở rộng) | Bình thường — tool sanitize tên trên MỌI OS (không chỉ Windows) để mirror di động được giữa Mac/Windows, và tự đổi tên khi 2 item trùng tên (kể cả trùng chỉ khác hoa/thường, hoặc 1 file trùng tên 1 folder). Xem log dòng `WARN: Trùng tên...` và cột `Collisions` ở cuối run. |
| Tên rất dài bị cắt ngắn, có thêm `~xxxxxx` trước phần mở rộng | Bình thường — giới hạn 255 byte/thành-phần-tên (NTFS). Hash 6 ký tự đảm bảo 2 tên dài khác nhau không bị gộp làm một sau khi cắt. |
