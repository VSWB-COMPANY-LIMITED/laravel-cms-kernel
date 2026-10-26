# gdrive-mirror — Vận hành trên máy Windows đĩa hạn chế, đích cuối là OneDrive

Tài liệu này ghi lại **quy trình vận hành thật** đang dùng để mirror 20 folder Google Drive của
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

## 2. Staging local — ĐÃ ĐỔI CHIẾN LƯỢC 2026-10-26: giữ vĩnh viễn, không xoá nữa

**Lịch sử quyết định — đọc để hiểu vì sao, không chỉ để biết "phải làm gì":**

Máy chạy chỉ có ổ `C:`. Chiến lược BAN ĐẦU (để tiết kiệm đĩa): lặp từng folder ID (không gọi 1
lệnh `@folderIds`, vì `--push-to` chỉ chạy MỘT LẦN sau khi TẤT CẢ folder đã tải xong — xem
`internal/mirror/push.go`, `main.go::maybePush`), và **xoá `C:\GDrive-Mirror` ngay sau khi push
xong mỗi folder** để đĩa luôn ở mức ~1x thay vì ~2x dung lượng Drive.

**Sự cố thật phát hiện cùng ngày khiến chiến lược này bị HUỶ BỎ:** xoá staging làm mất baseline
delta-sync (so size+mtime+MD5 với lần trước) — nghĩa là MỖI lần chạy, tool phải tải/export lại
TOÀN BỘ file từ Drive, kể cả khi nội dung Drive không hề đổi. Với file Google-native (Docs/Sheets/
Slides), **export của Google KHÔNG ổn định byte-for-byte giữa các lần export khác nhau** — đã xác
nhận thật trên 1 file `.pptx`: cùng 19 slide, cùng nội dung hiển thị, nhưng byte khác nhau mỗi lần
export (chỉ khác ở cách đánh số lại ảnh/font nhúng bên trong). `robocopy --push-to` coi mỗi lần
export-lại-khác-byte đó là "file đã đổi" và ghi đè OneDrive — OneDrive coi là xung đột ghi và tự
sinh bản `<tên>-DESKTOP-<tên-máy>-N.ext` mỗi lần — rác xung đột thật tăng dần trên dữ liệu khách
hàng, không phải lỗi giả lập.

**Quyết định mới (hiện hành): KHÔNG xoá staging nữa.** `C:\GDrive-Mirror` giữ vĩnh viễn làm
baseline. Khi Drive không đổi, delta-sync (mtime cho Google-native, size+MD5 cho file thường) tự
skip — không tải/export lại — không có gì để push lại — không còn xung đột giả. Đánh đổi: đĩa cần
thêm dung lượng cho staging (đo thật 2026-10-26: **~15 GB** cho toàn bộ 19 folder — nhỏ hơn nhiều
so với lo ngại ban đầu; máy còn 370+ GB trống nên không đáng lo). Script vẫn **lặp từng folder ID**
(không đổi) để 1 folder lỗi fatal không cản các folder còn lại và để thấy tiến độ rõ theo từng
folder — chỉ bỏ bước xoá staging.

**Nếu THẬT SỰ cần tiết kiệm đĩa tối đa** (ví dụ chuyển sang máy đĩa rất nhỏ): có thể khôi phục lại
cách xoá staging cũ (xem lịch sử git file này, hoặc commit đã thêm `Remove-DirLongPathSafe` ở
§3.1), nhưng phải **chấp nhận rủi ro xung đột OneDrive lặp lại cho mọi file Google-native** mỗi lần
chạy — không khuyến nghị trừ khi không còn lựa chọn khác.

## 3. Script vận hành — `C:\Tools\gdrive\run-mirror.ps1`

Script hiện sống tại `C:\Tools\gdrive\run-mirror.ps1` trên máy vận hành (KHÔNG commit vào repo —
chứa 20 folder ID + đường dẫn OneDrive cụ thể của tổ chức; bản sao kỹ thuật dưới đây để tham khảo
khi cần dựng lại máy khác hoặc đổi danh sách folder). **Bản hiện tại (2026-10-26, bản giữ-staging)
— không còn bước xoá staging** (xem §2 vì sao).

```powershell
$exe       = "C:\Tools\gdrive\gdrive-mirror.exe"
$staging   = "C:\GDrive-Mirror"                                               # giữ VĨNH VIỄN — xem §2
$pushTo    = "C:\Users\KUN\OneDrive - VISUAL WEBER COMPANY LIMITED"
$creds     = "C:\Tools\gdrive\creds.json"
$logDir    = "C:\Tools\gdrive\logs"
$log       = Join-Path $logDir "run-$(Get-Date -Format yyyyMMdd-HHmmss).log"
$stateFile = Join-Path $logDir "completed-folders.txt"   # resume -- xem §3.1

$folderIds = @("<folder-id-1>", "<folder-id-2>", ...)   # 20 ID thật — xem file trên máy

if (-not (Test-Path $staging)) { New-Item -ItemType Directory -Path $staging -Force | Out-Null }
if (-not (Test-Path $logDir))  { New-Item -ItemType Directory -Path $logDir -Force | Out-Null }

$alreadyDone = @()
if (Test-Path $stateFile) { $alreadyDone = @(Get-Content $stateFile | Where-Object { $_ -ne "" }) }

$summary = @()
foreach ($id in $folderIds) {
    if ($alreadyDone -contains $id) {
        "`n===== $(Get-Date -Format s) Folder $id -- SKIP (da xong tu truoc, xem $stateFile) =====" |
            Tee-Object -FilePath $log -Append
        continue
    }

    "`n===== $(Get-Date -Format s) Folder $id =====" | Tee-Object -FilePath $log -Append

    # try/catch quanh CẢ vòng lặp: 1 exception PowerShell bất ngờ ở 1 folder không được làm
    # dừng cả batch (xem §3.2 — lỗi thật đã gặp khi script còn bước xoá staging).
    try {
        & $exe $id --path="$staging" --push-to="$pushTo" --creds="$creds" --retry=10 --concurrency=8 *>&1 |
            Tee-Object -FilePath $log -Append
        $code = $LASTEXITCODE
        "Exit code: $code" | Tee-Object -FilePath $log -Append

        if ($code -eq 0 -or $code -eq 1) {
            Add-Content -Path $stateFile -Value $id
        }
        $summary += [pscustomobject]@{ FolderID = $id; ExitCode = $code }
    } catch {
        "LOI KHONG LUONG TRUOC, bo qua folder nay, tiep tuc folder sau: $_" | Tee-Object -FilePath $log -Append
        $summary += [pscustomobject]@{ FolderID = $id; ExitCode = "EXCEPTION" }
    }
}

$summary | Format-Table -AutoSize
$failCount = ($summary | Where-Object { $_.ExitCode -ne 0 }).Count
if ($failCount -gt 0) { exit 1 } else { exit 0 }   # exit tường minh -- xem §3.3
```

Chạy thủ công:

```powershell
cd C:\Tools\gdrive
.\run-mirror.ps1
```

Log mỗi lần chạy nằm ở `C:\Tools\gdrive\logs\run-<timestamp>.log`. Danh sách lệnh vận hành đầy đủ
(chạy full, chạy 1 folder, thêm folder mới, force re-scan…): xem **mục 8**.

### 3.1 Resume — `completed-folders.txt`

`run-mirror.ps1` ghi mỗi folder ID có exit code 0/1 (tức là đã PUSH xong, kể cả khi push có vài
item lỗi permanent) vào `C:\Tools\gdrive\logs\completed-folders.txt` — một ID mỗi dòng. Chạy lại
script tự động **bỏ qua** mọi ID đã có trong file này. Giờ staging không bị xoá nữa nên việc này
chủ yếu để đỡ in log trùng lặp/tiết kiệm thời gian do delta-sync vốn đã rẻ sẵn — không còn là cơ
chế sống còn để tránh tải lại từ đầu như trước.

**Lỗi đã biết — "xong giả" khi folder không truy cập được:** nếu folder ID sai hoặc service account
chưa được share, `gdrive-mirror.exe` liệt kê ra **0 item** nhưng vẫn trả **exit code 0** (Drive API
coi "không có gì khớp" là listing rỗng hợp lệ, không phải lỗi) — script vẫn ghi ID đó vào
`completed-folders.txt` như thể đã xong thật, nên **share quyền xong rồi chạy lại vẫn bị SKIP oan**.
Gặp thật 2026-10-26 khi thêm folder `Projects Data - Internal` lúc chưa share service account.
**Cách nhận biết:** mở log của lần chạy đó, tìm đúng khối `===== Folder <id> =====`, nếu thấy
`Found 0 item(s)` NGAY SAU một dòng `WARN: Could not resolve folder name ... 404` — đó là false
positive, không phải folder rỗng thật. **Cách xử lý:** xoá đúng dòng chứa ID đó khỏi
`completed-folders.txt` (xem lệnh ở mục 8.3) rồi chạy lại.

### 3.2 Lịch sử — MAX_PATH khi script còn bước xoá staging (đã hết hiệu lực)

Giữ lại mục này vì **kỹ thuật vẫn áp dụng cho việc archive file trùng tên trên OneDrive** (mục 7) dù
bước xoá-staging-sau-mỗi-folder đã bị bỏ (§2). Khi script còn xoá `C:\GDrive-Mirror` sau mỗi folder
(bản cũ, trước 2026-10-26), nó từng dùng `Remove-Item -Path ... -Recurse -Force -ErrorAction Stop`
và chết giữa chừng ở 1 cây con rất sâu, nhiều tầng tên tiếng Việt — tổng độ dài path vượt 260 ký tự
(MAX_PATH). `Remove-Item` đi qua .NET `System.IO`, KHÔNG tự lách MAX_PATH như Go làm — ném
`DirectoryNotFoundException`, và vì `-ErrorAction Stop` là lỗi dừng (terminating) nằm ngoài
try/catch lúc đó, cả vòng lặp chết theo.

**Quan trọng — đây KHÔNG phải bug của `gdrive-mirror.exe`.** Go trên Windows tự prefix `\\?\` cho
path, lách MAX_PATH hoàn toàn cho MỌI thao tác file của chính tool (xem comment
`internal/localpath/localpath.go` dòng 38-41) — file đường dẫn dài/Unicode tool tải về không lỗi gì.
Bug chỉ ở các đoạn PowerShell ad-hoc (`Remove-Item`/`Move-Item` trực tiếp) thao tác lại trên cùng
cây đó sau này.

**Kỹ thuật xoá/archive an toàn với path dài** (dùng ở mục 7, và từng dùng trong script trước khi bỏ
bước xoá staging):

```powershell
$empty = Join-Path $env:TEMP "tmp-empty-$(Get-Random)"
New-Item -ItemType Directory -Path $empty -Force | Out-Null
robocopy $empty "<thư mục cần xoá sạch nội dung>" /purge /NFL /NDL /NJH /NJS /R:1 /W:1
# exit code robocopy 0-7 = thành công (xem robocopy /?), >=8 = lỗi thật
Remove-Item "<thư mục đó>" -Force   # giờ rỗng, an toàn xoá vỏ
Remove-Item $empty -Recurse -Force -ErrorAction SilentlyContinue
```

**Lưu ý thêm phát hiện 2026-10-26 (archive file lẻ, không phải cả cây):** `Move-Item`/`Rename-Item`
một FILE LẺ (không phải cả cây thư mục) vào `_duplicates_legacy\<tên gốc> (lý do, archived
<ngày>)...` cũng có thể vượt 260 ký tự nếu tên gốc đã dài sẵn (gặp thật với tên file 86+ ký tự) —
**dùng tên archive NGẮN** (vd `DUP_<ngày>_<tên-rút-gọn>.ext`), không nối nguyên văn tên gốc + mô tả
dài vào đường dẫn đích.

### 3.3 Exit code của chính `run-mirror.ps1`

Không đặt `exit` tường minh ở cuối script thì exit code của tiến trình `powershell.exe` bị **rò ra
từ lệnh native cuối cùng** script gọi (robocopy hoặc `gdrive-mirror.exe`) — robocopy có thang điểm
riêng (0-7 đều là THÀNH CÔNG, xem `robocopy /?`), nên 1 lần chạy hoàn toàn ổn vẫn có thể báo exit
code `2` hay `3` ra ngoài, gây hiểu lầm là lỗi khi đọc exit code từ Task Scheduler/script gọi
ngoài. Script hiện tại đặt `exit` tường minh ở cuối, theo đúng quy ước của chính
`gdrive-mirror.exe`: `0` = mọi folder sạch hoàn toàn, `1` = có folder lỗi item hoặc exception (xem
log).

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
| `git push` bị GitHub từ chối: `GH007: Your push would publish a private email address` | Tài khoản GitHub dùng để push có bật "Keep my email addresses private", email trong commit (`git config user.email`) không phải email noreply của tài khoản đó. Lấy email noreply tại github.com/settings/emails (dạng `<ID>+<username>@users.noreply.github.com`), hoặc dùng lại `Claude <noreply@anthropic.com>` (đã xác nhận hoạt động với repo này — xem `git log`), set `git config user.email "..."` rồi commit lại (không amend commit đã push). |
| `git push` bị từ chối `(fetch first)` / `! [rejected]` | Remote có commit mới (dev team khác đã push) mà local chưa kéo về. LUÔN `git fetch origin <branch>` + `git log HEAD..origin/<branch> --oneline` trước khi push — nếu có commit mới, `git pull --rebase origin <branch>` rồi push lại. Đã xảy ra nhiều lần trong phiên vận hành 2026-10-26. |
| Ghi file log/trực tiếp vào gốc `C:\` bị "Access denied" dù chạy PowerShell thường | Không phải lỗi quyền Windows — một số môi trường chạy agent/automation tự chặn ghi/xoá trực tiếp ở gốc ổ hệ thống. Luôn đặt log/output vào một thư mục con (`C:\Tools\gdrive\logs\...`), không đặt thẳng ở `C:\`. |
| `go build` báo lỗi "The process cannot access the file ... being used by another process" khi build `gdrive-mirror.exe` | `run-mirror.ps1` (hoặc 1 lần chạy tay) đang chạy process đó. Đợi chạy xong (hoặc dừng) rồi build lại — xem mục 4. |
| Muốn tiết kiệm đĩa hơn nữa (không giữ staging) | KHÔNG khuyến nghị — xem §2, đây chính là nguyên nhân gây xung đột OneDrive lặp lại cho file Google-native. Chỉ làm nếu đĩa thật sự cạn kiệt. |
| Thêm 1 folder ID mới vào `run-mirror.ps1` rồi chạy lại nhưng bị SKIP, dù chưa từng chạy thành công | Xem §3.1 — exit code 0 giả khi chưa share quyền cho service account. Xoá đúng dòng ID đó khỏi `completed-folders.txt` (lệnh ở mục 8.3) rồi chạy lại — PHẢI share quyền Drive cho service account TRƯỚC (mục 8.2). |
| OneDrive báo "Sync issues" với 1 file nhưng kiểm tra tên không thấy ký tự cấm/khoảng trắng đầu-cuối nào | Có thể là thông báo CŨ OneDrive chưa tự dọn UI (xảy ra thật 2026-10-26 với 3 file `{DEV}...` — không tìm thấy vi phạm nào sau khi rà kỹ). Bấm "Rename" hoặc "Resolve individually" ngay trong dialog OneDrive — an toàn, không ảnh hưởng tool. |
| Archive 1 file trùng tên vào `_duplicates_legacy` bị lỗi "Could not find a part of the path" | Path đích vượt 260 ký tự (tên gốc dài + hậu tố mô tả dài cộng dồn) — xem §3.2, dùng tên archive NGẮN. |
| Đã archive/xoá 1 bản trùng tên trên OneDrive, nhưng vài phút/giờ sau nó XUẤT HIỆN LẠI | **Kiểm tra trước hết: 2 file có thật sự trùng, hay là 1 Google Sheet/Doc/Slide (native) và 1 file thường cùng tên gốc, khác định dạng?** Đây là nguyên nhân THẬT đã xác nhận 2026-10-26 (xem §7) — nếu đúng vậy thì **việc xoá trên OneDrive sẽ vô ích vĩnh viễn**, vì lần mirror tiếp theo chạm tới folder đó sẽ tải lại CẢ HAI từ Drive và push lại y hệt. Phải xử lý tận gốc TRÊN GOOGLE DRIVE (xoá hẳn 1 trong 2 item ở đó), không phải trên OneDrive. Nếu không phải case này (2 item đúng là cùng 1 Drive ID/cùng định dạng mà vẫn tái xuất hiện dù không mirror lại folder đó) — nghi vấn thiết bị khác cũng đồng bộ cùng thư viện OneDrive for Business này còn giữ bản cache cũ, hoặc chính sách retention/compliance của tổ chức; xử lý bằng cách xoá qua giao diện web SharePoint/OneDrive (xoá server-side, propagate đúng tới mọi thiết bị) thay vì xoá cục bộ trên máy này. |
| `--push-to` không xoá file đã bị xoá tay trên OneDrive — file "sống lại" sau lần mirror kế tiếp | Đúng thiết kế, không phải lỗi: `--push-to` là đẩy MỘT CHIỀU, không nhận biết xoá (không `/MIR`, không `/PURGE`) — nếu file vẫn còn trong staging/Drive, lần push sau sẽ tạo lại nó trên OneDrive. Muốn 1 file biến mất vĩnh viễn khỏi OneDrive dù Drive vẫn còn: xoá tay trong `C:\GDrive-Mirror\<...>` trước mỗi lần chạy lại folder đó (vẫn bị tải lại nếu full re-scan), hoặc xoá hẳn trên Drive gốc. |

## 7. Duplicate thật trên Drive — nguyên nhân đã xác nhận & cách xử lý

**Tình huống thật đã điều tra kỹ 2026-10-26** (file `Dịch Vụ Hosting - Domains.xlsx` trong
`Accounting/Sổ Thu Chi`, xoá đi xoá lại 3 lần vẫn quay lại): ban đầu nghi ngờ (1) lỗi Unicode
NFC/NFD, rồi (2) OneDrive tự phục hồi do race condition khi đang bận đồng bộ, rồi (3) thiết bị khác
đồng bộ cùng thư viện. **Cả 3 giả thuyết đều SAI.** Nguyên nhân thật, xác nhận bằng cách mở trực
tiếp Google Drive: folder đó có **CẢ MỘT Google Sheet native tên `Dịch Vụ Hosting - Domains`
(không đuôi mở rộng) LẪN một file Excel thật `Dịch Vụ Hosting - Domains.xlsx`** — khi export, Sheet
native tự thêm đuôi `.xlsx`, trùng tên y hệt file Excel thật. Đây là **2 Drive item khác nhau, cả
hai cùng tồn tại VĨNH VIỄN trên Drive** — mirror lần nào cũng tải cả hai, luôn luôn collision,
luôn luôn push lại. Xoá 1 bản trên OneDrive chỉ là xoá TRIỆU CHỨNG — Drive vẫn sinh lại nó mỗi lần
mirror chạm tới folder đó.

**Đã fix trong code** (commit cùng ngày): `internal/mirror/list.go::logicalName()` — report
`name-duplicates/<folderTag>.md` (xem README §Reports) giờ bắt đúng cả 2 dạng duplicate thật:
1. Tên gốc Drive y hệt nhau (2 file/folder trùng tên thật, Drive cho phép).
2. **Google-native (Sheet/Doc/Slide) trùng tên với 1 file thường cùng thư mục** sau khi thêm đuôi
   export — case vừa phát hiện.

Vẫn cố ý LOẠI TRỪ trùng do sanitize ký tự (`a:b.pdf` và `a_b.pdf` cùng thành `a_b.pdf`) — 2 file đó
khác nhau thật, không phải duplicate.

**Quy trình xử lý 1 cặp duplicate thật từ report:**

1. Mở `C:\gdrive-mirror-reports\name-duplicates\<folderTag>.md`, lấy 2 link Drive của 1 cặp.
2. Mở Google Drive trực tiếp (như ảnh chụp đã dùng để xác nhận case trên) — xem icon loại file
   (Sheet/Doc/Slide native có icon riêng, khác icon Excel/Word thường) để biết đây là case "khác
   định dạng" hay "trùng tên thật cùng định dạng".
3. **Xoá/đổi tên TRÊN GOOGLE DRIVE** (không phải trên OneDrive) — đây là nơi duy nhất xoá được vĩnh
   viễn, vì OneDrive chỉ phản ánh lại những gì Drive có.
4. Chạy lại `run-mirror.ps1` cho đúng folder đó (xoá entry khỏi `completed-folders.txt` trước nếu
   cần — mục 8.3) để xác nhận report không còn liệt kê cặp đó nữa.

## 8. Danh sách lệnh vận hành nhanh

### 8.1 Chạy đầy đủ / chạy lại (tự skip folder đã xong)

```powershell
cd C:\Tools\gdrive
.\run-mirror.ps1
```

### 8.2 Thêm 1 folder Drive mới vào danh sách đồng bộ

```powershell
# 1) Lấy client_email của service account
Select-String '"client_email"' C:\Tools\gdrive\creds.json

# 2) Share folder đó trên Google Drive cho email trên, quyền Viewer

# 3) Thêm folder ID vào mảng $folderIds trong C:\Tools\gdrive\run-mirror.ps1 (Edit thủ công)

# 4) Chạy lại — chỉ folder mới chạy, các folder cũ tự skip nhờ completed-folders.txt
.\run-mirror.ps1
```

### 8.3 Xoá 1 entry khỏi state (để 1 folder cụ thể chạy lại thay vì bị skip)

```powershell
$stateFile = "C:\Tools\gdrive\logs\completed-folders.txt"
$idToRetry = "<folder-id-can-chay-lai>"
(Get-Content $stateFile | Where-Object { $_ -ne $idToRetry }) | Set-Content $stateFile
.\run-mirror.ps1
```

### 8.4 Buộc chạy lại TOÀN BỘ từ đầu (xoá hết state)

```powershell
Remove-Item "C:\Tools\gdrive\logs\completed-folders.txt" -Force
.\run-mirror.ps1
```

Vẫn RẺ nhờ delta-sync (staging `C:\GDrive-Mirror` không bị xoá, §2) — không phải tải lại byte nào
cho file không đổi, chỉ tốn thời gian liệt kê + so sánh.

### 8.5 Xem log / tìm lỗi của 1 lần chạy

```powershell
# Log mới nhất
Get-ChildItem C:\Tools\gdrive\logs\run-*.log | Sort-Object LastWriteTime -Descending | Select-Object -First 1

# Tìm tất cả cảnh báo trùng tên THẬT trên Drive trong 1 log
Select-String "Trùng tên THẬT" <đường-dẫn-log>

# Đọc report chi tiết 1 folder (folderTag in ra ngay trong log, hoặc xem toàn bộ):
Get-ChildItem C:\gdrive-mirror-reports\name-duplicates\
```

### 8.6 Build lại + deploy sau khi dev team push code mới

Xem mục 4 (đầy đủ) — tóm tắt: `git pull` → `go test ./...` → cross-compile 5 target → cập nhật
`SHA256SUMS` → copy bản Windows sang `C:\Tools\gdrive\gdrive-mirror.exe` → commit + push (nhớ
`git fetch` + kiểm tra commit mới từ remote TRƯỚC khi push, xem mục 6).

### 8.7 Cài Go toolchain (máy mới)

Xem mục 5.

## 9. Tham chiếu

- [README.md](README.md) — flag đầy đủ, hành vi chi tiết, Reports (bao gồm `name-duplicates/*.md`
  — §7 ở trên), `--push-to`/`--incremental`.
- [RUN-SAMPLES.md](RUN-SAMPLES.md) — lệnh mẫu theo từng OS, troubleshooting chung.
- `CHANGELOG.md` (gốc repo) — lịch sử thay đổi code của `gdrive-mirror-cli`.
