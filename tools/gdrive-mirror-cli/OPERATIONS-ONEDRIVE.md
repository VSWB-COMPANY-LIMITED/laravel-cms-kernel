# gdrive-mirror — Vận hành trên máy Windows đĩa hạn chế, đích cuối là OneDrive

Tài liệu này ghi lại **quy trình vận hành thật** đang dùng để mirror 19 folder Google Drive của
VisualWeber vào `OneDrive - VISUAL WEBER COMPANY LIMITED` trên một máy Windows có dung lượng đĩa
hạn chế. Khác với [README.md](README.md) (tham chiếu flag đầy đủ) và
[RUN-SAMPLES.md](RUN-SAMPLES.md) (lệnh mẫu tổng quát theo OS), tài liệu này mô tả **một cấu hình
cụ thể đã chốt và đang chạy**, kèm lý do từng quyết định — để người sau (hoặc chính người viết sau
vài tháng) không phải suy luận lại từ đầu.

## 1. Vì sao không chạy `--path` thẳng vào OneDrive

Lệnh cũ (trước khi có `--push-to`) trông như sau:

```powershell
.\gdrive-mirror.exe $folderId --path="C:\Users\KUN\OneDrive - VISUAL WEBER COMPANY LIMITED" --creds=...
```

Đây **chính là nguyên nhân sự cố thật** mô tả ở README §"Mirror thẳng vào thư mục cloud-sync
sống": mỗi lần `rename` atomic của tool là một cú ghi ngoài giao thức OneDrive, dù nội dung không
đổi — OneDrive coi là xung đột và tự đẻ bản đánh số (`file.docx` → `file-2.docx` → ... →
`file-21.docx` sau ~20 lần chạy).

Bản build hiện tại (từ commit `69343e0`) **không còn chặn** kiểu `--path` này nữa — chỉ log cảnh
báo rồi chạy tiếp (`--forbid-cloud-sync-path` mới bật lại chế độ chặn). Nghĩa là **lệnh cũ vẫn
chạy "được"**, nhưng rủi ro nhân bản file vẫn còn nguyên — không chạy lại kiểu này nữa, dùng
`--push-to` ở mục 2.

## 2. Vấn đề đĩa hạn chế → chiến lược đã chốt

Máy chạy chỉ có ổ `C:` (xem free space bằng `Get-PSDrive C`). Hai ràng buộc đến từ chính cách
`--push-to` hoạt động (xem `internal/mirror/push.go`, `main.go::maybePush`):

1. **`--push-to` chỉ chạy MỘT LẦN, sau khi TẤT CẢ folder ID trong lệnh đã tải xong** (không phải
   sau mỗi folder). Gọi 1 lệnh với cả 19 folder ID → đĩa phải chứa đủ **toàn bộ** dữ liệu 19 folder
   cùng lúc ở `--path` trước khi được đẩy đi.
2. **`--path` không bao giờ bị tool tự xoá** (nguyên tắc an toàn cốt lõi) — nó tồn tại vĩnh viễn
   làm baseline cho delta-sync lần sau. Giữ nguyên staging nghĩa là đĩa cần **~2 lần** dung lượng
   Drive (1 bản ở `--path`, 1 bản trong OneDrive).

**Quyết định đã chốt** (đổi disk-footprint để lấy, không phải giữ tốc độ delta-sync):

- **Lặp từng folder ID** (gọi exe 19 lần, không gọi 1 lệnh `@folderIds`) → mỗi folder tải xong là
  đẩy sang OneDrive ngay, đĩa đỉnh chỉ cần chứa ~1 folder tại 1 thời điểm, không phải cả 19.
- **Xoá `--path` ngay sau khi push xong** cho từng folder → đĩa ổn định ở mức ~1x (chỉ còn bản
  trong OneDrive). **Đánh đổi:** lần chạy sau KHÔNG còn baseline local → tool tải lại TOÀN BỘ từ
  Drive mỗi lần (không delta-sync được nữa). Nếu sau này đĩa rộng hơn, bỏ bước xoá staging để lấy
  lại delta-sync nhanh.

## 3. Script vận hành — `C:\Tools\gdrive\run-mirror.ps1`

Script hiện sống tại `C:\Tools\gdrive\run-mirror.ps1` trên máy vận hành (KHÔNG commit vào repo —
chứa 19 folder ID + đường dẫn OneDrive cụ thể của tổ chức; bản sao kỹ thuật dưới đây để tham khảo
khi cần dựng lại máy khác hoặc đổi danh sách folder).

```powershell
$exe     = "C:\Tools\gdrive\gdrive-mirror.exe"
$staging = "C:\GDrive-Mirror"
$pushTo  = "C:\Users\KUN\OneDrive - VISUAL WEBER COMPANY LIMITED"
$creds   = "C:\Tools\gdrive\creds.json"
$logDir  = "C:\Tools\gdrive\logs"
$log     = Join-Path $logDir "run-$(Get-Date -Format yyyyMMdd-HHmmss).log"

$folderIds = @("<folder-id-1>", "<folder-id-2>", ...)   # 19 ID thật — xem file trên máy

if (-not (Test-Path $staging)) { New-Item -ItemType Directory -Path $staging -Force | Out-Null }
if (-not (Test-Path $logDir))  { New-Item -ItemType Directory -Path $logDir -Force | Out-Null }

$summary = @()
foreach ($id in $folderIds) {
    "`n===== $(Get-Date -Format s) Folder $id =====" | Tee-Object -FilePath $log -Append

    $before = @(Get-ChildItem -Path $staging -Force -Name -ErrorAction SilentlyContinue)

    & $exe $id --path="$staging" --push-to="$pushTo" --creds="$creds" --retry=10 --concurrency=8 *>&1 |
        Tee-Object -FilePath $log -Append
    $code = $LASTEXITCODE
    "Exit code: $code" | Tee-Object -FilePath $log -Append

    # Chỉ dọn khi push đã thực sự chạy (maybePush luôn chạy với code 0/1; code khác là lỗi fatal
    # trước khi tới bước push — xem main.go). Chỉ xoá đúng top-level entry MỚI xuất hiện lần này,
    # để không đụng dữ liệu của folder khác nếu 2 folder ID trùng tên Drive.
    if ($code -eq 0 -or $code -eq 1) {
        $after = @(Get-ChildItem -Path $staging -Force -Name -ErrorAction SilentlyContinue)
        $new = $after | Where-Object { $before -notcontains $_ }
        if ($new) {
            foreach ($n in $new) { Remove-Item -Path (Join-Path $staging $n) -Recurse -Force }
        }
        # else: không có entry mới -> có thể trùng tên top-level với 1 folder ID khác trong danh
        # sách -> KHÔNG tự xoá, cần kiểm tra tay (xem log "CANH BAO" dòng tương ứng)
    }
    $summary += [pscustomobject]@{ FolderID = $id; ExitCode = $code }
}
$summary | Format-Table -AutoSize
```

Chạy thủ công:

```powershell
cd C:\Tools\gdrive
.\run-mirror.ps1
```

Log mỗi lần chạy nằm ở `C:\Tools\gdrive\logs\run-<timestamp>.log`.

### Vì sao cách xoá "top-level entry mới xuất hiện" là an toàn

Local path của mỗi top-level folder = **tên Drive của chính folder ID đó** (resolve qua
`Files.Get(folderID).Fields("name")`, xem `sync.go::resolveFolderName`) — không phải tên con nào
bên trong. Diff "trước/sau" theo tên entry ở đúng cấp `--path` nên không cần biết trước tool sẽ đặt
tên gì; nếu 2 folder ID khác nhau tình cờ cùng tên Drive, lần 2 sẽ không thấy entry "mới" (đã có từ
lần 1, đã bị dọn) → script tự báo CẢNH BÁO và bỏ qua dọn dẹp thay vì xoá nhầm/xoá thiếu.

## 4. Build lại khi dev team push code mới

```powershell
cd C:\Users\KUN\Desktop\laravel-cms-kernel
git pull --ff-only origin v7.x

cd tools\gdrive-mirror-cli
go vet ./...
go test ./...

# Cross-compile cả 5 target (đúng công thức README) rồi ghi SHA256SUMS
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

Sau khi `dist/` đã cập nhật, copy binary Windows mới sang máy vận hành:

```powershell
Copy-Item "C:\Users\KUN\Desktop\laravel-cms-kernel\tools\gdrive-mirror-cli\dist\gdrive-mirror-windows-amd64.exe" `
  "C:\Tools\gdrive\gdrive-mirror.exe" -Force
Copy-Item "C:\Users\KUN\Desktop\laravel-cms-kernel\tools\gdrive-mirror-cli\dist\gdrive-mirror-windows-amd64.exe" `
  "C:\Tools\gdrive\gdrive-mirror-windows-amd64.exe" -Force
```

Lưu ý: **đừng rebuild/copy đè khi `run-mirror.ps1` đang chạy** — `gdrive-mirror.exe` đang là 1
process đang chạy, Windows không cho ghi đè file exe đang mở (`go build`/`Copy-Item` sẽ lỗi "file
đang được sử dụng"). Đợi script chạy xong (xem log mới nhất trong `C:\Tools\gdrive\logs`) rồi mới
build/copy.

## 5. Máy chưa có Go toolchain?

```powershell
winget install --id GoLang.Go -e --accept-package-agreements --accept-source-agreements
# Mở lại PowerShell mới (PATH cập nhật), hoặc trong phiên hiện tại:
$env:PATH = "$env:PATH;C:\Program Files\Go\bin"
go version
```

## 6. Troubleshooting

| Triệu chứng | Nguyên nhân & cách xử lý |
| --- | --- |
| `git push` bị GitHub từ chối: `GH007: Your push would publish a private email address` | Tài khoản GitHub dùng để push có bật "Keep my email addresses private", email trong commit (`git config user.email`) không phải email noreply của tài khoản đó. Lấy email noreply tại github.com/settings/emails (dạng `<ID>+<username>@users.noreply.github.com`), set lại `git config user.email "..."` cho repo này rồi commit lại (không amend commit đã push, tạo commit mới hoặc sửa trước khi push lần đầu). |
| Ghi file log/trực tiếp vào gốc `C:\` bị "Access denied" dù chạy PowerShell thường | Không phải lỗi quyền Windows — một số môi trường chạy agent/automation tự chặn ghi/xoá trực tiếp ở gốc ổ hệ thống. Luôn đặt log/output vào một thư mục con (`C:\Tools\gdrive\logs\...`), không đặt thẳng ở `C:\`. |
| `go build` báo lỗi "The process cannot access the file ... being used by another process" khi build `gdrive-mirror.exe` | `run-mirror.ps1` (hoặc 1 lần chạy tay) đang chạy process đó. Đợi chạy xong (hoặc dừng) rồi build lại — xem mục 4. |
| Muốn quay lại delta-sync nhanh (không tải lại từ đầu mỗi lần) | Bỏ đoạn xoá staging trong `run-mirror.ps1` (mục 3) — đổi lại, đĩa sẽ cần ~2x dung lượng Drive liên tục (xem mục 2). |

## 7. Tham chiếu

- [README.md](README.md) — flag đầy đủ, hành vi chi tiết, Reports, `--push-to`/`--incremental`.
- [RUN-SAMPLES.md](RUN-SAMPLES.md) — lệnh mẫu theo từng OS, troubleshooting chung.
- `CHANGELOG.md` (gốc repo) — lịch sử thay đổi code của `gdrive-mirror-cli`.
