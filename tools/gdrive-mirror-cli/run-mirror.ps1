# ============================================================================
#  run-mirror.ps1 -- MENU van hanh gdrive-mirror (Google Drive -> staging -> OneDrive)
# ============================================================================
#
#  Mo bang run-mirror.bat (double-click). Chon so trong menu, script tu dong lap tung
#  folder, ghi log, in tong ket. Khong can nho flag.
#
#  File nay viet ASCII khong dau co chu y: PowerShell 5.1 doc file khong co BOM theo
#  codepage may, tieng Viet co dau se vo chu.
#
#  CAC FILE CAN CO (trong $Base; mac dinh la thu muc chua script nay neu co folders.txt /
#  settings.txt canh no, khong thi C:\Tools\gdrive):
#    gdrive-mirror.exe   binary (copy tu dist\gdrive-mirror-windows-amd64.exe)
#    creds.json          service-account JSON; folder Drive phai share cho client_email (Viewer)
#    folders.txt         DANH SACH FOLDER ID, moi dong 1 ID, chu thich sau dau #
#                        (mau: folders.example.txt). KHONG commit vao git.
#    settings.txt        CAU HINH DUONG DAN: Staging, PushTo, Concurrency, Retry
#                        (mau: settings.example.txt). KHONG commit vao git.
#    logs\               tu tao: log moi lan chay + completed-folders.txt (trang thai resume)
#
#  THU TU UU TIEN CAU HINH:  tham so dong lenh (-Staging ...)  >  settings.txt  >  mac dinh.
#    Mac dinh: Staging = C:\GDrive-Mirror, Concurrency = 8, Retry = 10.
#    PushTo KHONG co mac dinh (la duong dan OneDrive cua tung may) -> bat buoc khai bao trong
#    settings.txt hoac -PushTo; thieu thi script bao loi va thoat (exit 2).
#
#  CHAY KHONG CAN MENU (Task Scheduler / script khac):
#    powershell -File run-mirror.ps1 -Action 1      # = muc [1] Chay tiep folder chua xong
#    powershell -File run-mirror.ps1 -Action 2      # = muc [2] Chay lai tat ca
#    Exit code: 0 = sach, 1 = co folder loi item hoac exception (xem log).
#    Cac muc con lai (3-8) can chon folder nen chi dung duoc khi go tay trong menu.
#
#  TOI SAO LAP TUNG FOLDER (khong phai 1 lenh @folderIds):
#    --push-to chi chay 1 LAN sau khi TAT CA folder tai xong; lap tung folder de loi fatal
#    o 1 folder khong can cac folder con lai va thay tien do ro theo folder.
#
#  TOI SAO KHONG XOA STAGING ($Staging) -- DOC TRUOC KHI "DON DEP":
#    Staging la baseline delta-sync. Xoa di => moi lan chay phai tai/export lai TOAN BO file;
#    export Google-native KHONG on dinh byte-for-byte giua cac lan => robocopy coi la "file
#    da doi" va ghi de OneDrive => OneDrive sinh ban xung dot "<ten>-DESKTOP-<may>-N.ext".
#    Chi tiet: README.md muc 11.1.
# ============================================================================

param(
  [string]$Action = "",        # bo trong = hien menu; "1".."9" = chay thang muc do
  [string]$Base = "",          # thu muc trien khai (xem "CAC FILE CAN CO" o tren)
  [string]$Exe = "",           # mac dinh: $Base\gdrive-mirror.exe
  [string]$Creds = "",         # mac dinh: $Base\creds.json
  [string]$LogDir = "",        # mac dinh: $Base\logs
  [string]$FoldersFile = "",   # mac dinh: $Base\folders.txt
  [string]$Staging = "",       # staging local, GIU VINH VIEN. Mac dinh: settings.txt, roi C:\GDrive-Mirror
  [string]$PushTo = "",        # thu muc OneDrive dich. Mac dinh: settings.txt (khong co mac dinh cung)
  [int]$Concurrency = 0,       # so file tai song song; HDD/USB/NAS: 2-4. Mac dinh: settings.txt, roi 8
  [int]$Retry = 0              # so lan thu lai moi thao tac khi mang loi. Mac dinh: settings.txt, roi 10
)

# $Base: thu muc chua script nay neu co folders.txt / settings.txt canh no (cai dat tai cho),
# nguoc lai C:\Tools\gdrive (vd khi chay ban .ps1 trong repo).
if (-not $Base) {
  if ((Test-Path (Join-Path $PSScriptRoot "folders.txt")) -or (Test-Path (Join-Path $PSScriptRoot "settings.txt"))) { $Base = $PSScriptRoot }
  else { $Base = "C:\Tools\gdrive" }
}

# settings.txt: moi dong "Khoa = gia tri", bo dong trong / dong bat dau bang #. Gia tri giu nguyen
# dau cach ben trong (duong dan OneDrive co dau cach), bo cap nhay bao quanh neu co.
$SettingsFile = Join-Path $Base "settings.txt"
$cfg = @{}
if (Test-Path $SettingsFile) {
  $known = @("Staging", "PushTo", "Concurrency", "Retry")
  foreach ($line in (Get-Content $SettingsFile -Encoding UTF8)) {
    $t = $line.Trim()
    if ($t -eq "" -or $t.StartsWith("#")) { continue }
    $i = $t.IndexOf("=")
    if ($i -lt 1) { Write-Host "[CANH BAO] settings.txt: bo qua dong khong co dau '=': $t" -ForegroundColor Yellow; continue }
    $k = $t.Substring(0, $i).Trim(); $v = $t.Substring($i + 1).Trim().Trim('"')
    if ($known -notcontains $k) { Write-Host "[CANH BAO] settings.txt: khoa la '$k' (hop le: $($known -join ', '))" -ForegroundColor Yellow; continue }
    $cfg[$k] = $v
  }
}

if (-not $Staging)     { $Staging = if ($cfg.Staging) { $cfg.Staging } else { "C:\GDrive-Mirror" } }
if (-not $PushTo)      { $PushTo = $cfg.PushTo }
if ($Concurrency -lt 1) { $Concurrency = if ($cfg.Concurrency -match '^\d+$' -and [int]$cfg.Concurrency -ge 1) { [int]$cfg.Concurrency } else { 8 } }
if ($Retry -lt 1)       { $Retry = if ($cfg.Retry -match '^\d+$' -and [int]$cfg.Retry -ge 1) { [int]$cfg.Retry } else { 10 } }

if (-not $Exe)         { $Exe = Join-Path $Base "gdrive-mirror.exe" }
if (-not $Creds)       { $Creds = Join-Path $Base "creds.json" }
if (-not $LogDir)      { $LogDir = Join-Path $Base "logs" }
if (-not $FoldersFile) { $FoldersFile = Join-Path $Base "folders.txt" }

$StateFile  = Join-Path $LogDir "completed-folders.txt"
$ReportsDir = Join-Path (Split-Path $Staging -Parent) "gdrive-mirror-reports"   # tool ghi report o day
$Log        = Join-Path $LogDir "run-$(Get-Date -Format yyyyMMdd-HHmmss).log"
$script:LastExit = 0

# ---------------------------------------------------------------- tien ich ----

function Say([string]$Text, [string]$Color = "Gray") { Write-Host $Text -ForegroundColor $Color }

# Doc 1 dong tu ban phim. Tra $null khi het stdin (vd chay tu pipe) de vong lap menu tu thoat.
function Ask([string]$Prompt) {
  Write-Host -NoNewline $Prompt
  $line = [Console]::ReadLine()
  if ($null -eq $line) { return $null }
  return $line.Trim()
}

function Confirm-Yes([string]$Message) { return ((Ask "$Message (go 'yes' de dong y): ") -eq "yes") }

function Write-Log([string]$Text) { $Text | Tee-Object -FilePath $Log -Append }

# Doc folders.txt: moi dong "ID  # nhan", bo dong trong / dong bat dau bang #.
function Get-Folders {
  if (-not (Test-Path $FoldersFile)) { return }
  foreach ($line in (Get-Content $FoldersFile -Encoding UTF8)) {
    $t = $line.Trim()
    if ($t -eq "" -or $t.StartsWith("#")) { continue }
    $id = $t; $label = ""
    $i = $t.IndexOf("#")
    if ($i -ge 0) { $id = $t.Substring(0, $i).Trim(); $label = $t.Substring($i + 1).Trim() }
    if ($id) { [pscustomobject]@{ Id = $id; Label = $label } }
  }
}

function Get-Done {
  if (Test-Path $StateFile) { return @(Get-Content $StateFile | Where-Object { $_ -ne "" }) }
  return @()
}

function Show-Folders($Folders) {
  $done = Get-Done
  for ($i = 0; $i -lt $Folders.Count; $i++) {
    $f = $Folders[$i]
    $mark = if ($done -contains $f.Id) { "[xong]" } else { "[chua]" }
    $color = if ($done -contains $f.Id) { "DarkGray" } else { "White" }
    Say ("{0,3}. {1} {2}  {3}" -f ($i + 1), $mark, $f.Id, $f.Label) $color
  }
}

# Cho chon folder: "all" | "1,3,5-7" | Enter = huy. Tra mang folder (rong neu huy / nhap sai).
function Select-Folders($Folders) {
  if ($Folders.Count -eq 0) { Say "Chua co folder nao trong $FoldersFile" Red; return @() }
  Show-Folders $Folders
  $a = Ask "Chon folder (vd 1,3,5-7 | all | Enter = huy): "
  if (-not $a) { return @() }
  if ($a -eq "all") { return @($Folders) }
  $idx = New-Object System.Collections.Generic.List[int]
  foreach ($tok in ($a -split ",")) {
    $tok = $tok.Trim()
    if ($tok -match '^(\d+)-(\d+)$') { for ($n = [int]$Matches[1]; $n -le [int]$Matches[2]; $n++) { $idx.Add($n) } }
    elseif ($tok -match '^\d+$') { $idx.Add([int]$tok) }
    else { Say "Khong hieu '$tok' - huy." Red; return @() }
  }
  $out = @()
  foreach ($n in ($idx | Select-Object -Unique)) {
    if ($n -lt 1 -or $n -gt $Folders.Count) { Say "So $n ngoai khoang 1..$($Folders.Count) - huy." Red; return @() }
    $out += $Folders[$n - 1]
  }
  return $out
}

# Hoi them flag tu do (khi chay tu menu). Enter = khong them gi.
function Read-Extra {
  if ($Action) { return @() }   # che do -Action: khong hoi, de chay duoc tu scheduler
  Say "  Flag them tuy chon: --incremental  --ignore-shrink  --list-concurrency=16  --forbid-cloud-sync-path" DarkGray
  $e = Ask "  Nhap flag them (Enter = khong): "
  if (-not $e) { return @() }
  return @($e -split "\s+" | Where-Object { $_ })
}

# ------------------------------------------------------------- loi chay -------

# Chay gdrive-mirror cho danh sach folder. $Push = them --push-to; $MarkDone = ghi vao state resume.
function Invoke-Batch($Targets, [string[]]$Extra, [bool]$Push, [bool]$MarkDone) {
  if ($Targets.Count -eq 0) { Say "Khong co folder nao de chay." Yellow; return }
  $summary = @()
  $done = Get-Done
  foreach ($f in $Targets) {
    Write-Log "`n===== $(Get-Date -Format s) Folder $($f.Id) $($f.Label) ====="
    $args2 = @($f.Id, "--path=$Staging", "--creds=$Creds", "--retry=$Retry", "--concurrency=$Concurrency")
    if ($Push) { $args2 += "--push-to=$PushTo" }
    $args2 += $Extra
    Write-Log ("CMD: " + $Exe + " " + ($args2 -join " "))
    # try/catch quanh moi vong: 1 exception bat ngo o 1 folder khong duoc lam dung ca batch.
    try {
      & $Exe @args2 *>&1 | Tee-Object -FilePath $Log -Append
      $code = $LASTEXITCODE
      Write-Log "Exit code: $code"
      if ($MarkDone -and ($code -eq 0 -or $code -eq 1) -and ($done -notcontains $f.Id)) {
        Add-Content -Path $StateFile -Value $f.Id
        $done += $f.Id
      }
      $summary += [pscustomobject]@{ FolderID = $f.Id; ExitCode = $code }
    } catch {
      Write-Log "LOI KHONG LUONG TRUOC, bo qua folder nay, tiep tuc folder sau: $_"
      $summary += [pscustomobject]@{ FolderID = $f.Id; ExitCode = "EXCEPTION" }
    }
  }
  Write-Log "`n===== SUMMARY ====="
  Write-Log ($summary | Format-Table -AutoSize | Out-String)
  $fail = @($summary | Where-Object { $_.ExitCode -ne 0 }).Count
  Write-Log "Xong. $fail/$($summary.Count) folder co exit code != 0 hoac exception."
  Say "LOG: $Log" Cyan
  # Dat exit code tuong minh: neu khong, $LASTEXITCODE ro ra tu lenh native cuoi (robocopy co thang rieng 0-7 = OK).
  $script:LastExit = if ($fail -gt 0) { 1 } else { 0 }
}

# -------------------------------------------------------------- cac muc menu --

function Invoke-Choice([string]$Choice, $Folders) {
  switch ($Choice.ToUpper()) {
    "1" {  # chay tiep: bo qua folder da xong
      $done = Get-Done
      $todo = @($Folders | Where-Object { $done -notcontains $_.Id })
      if ($todo.Count -eq 0) {
        Say "Tat ca $($Folders.Count) folder da 'xong' trong $StateFile." Yellow
        Say "Muon chay lai: dung muc [2] (tat ca), [3] (chon folder) hoac [X] (xoa trang thai)." Yellow
        return
      }
      Say "Se chay $($todo.Count)/$($Folders.Count) folder chua xong." Cyan
      Invoke-Batch $todo (Read-Extra) $true $true
    }
    "2" {  # chay lai tat ca, bo qua resume
      Say "Chay lai TAT CA $($Folders.Count) folder (bo qua resume). Re nho delta-sync: chi tai file moi/doi." Cyan
      Invoke-Batch $Folders (Read-Extra) $true $true
    }
    "3" {  # chon folder cu the
      $sel = @(Select-Folders $Folders)
      if ($sel.Count -gt 0) { Invoke-Batch $sel (Read-Extra) $true $true }
    }
    "4" {  # dry-run
      Say "Dry-run: chi liet ke 20 item dau, KHONG tai, KHONG push. Dung de kiem tra auth + thay dung thu muc." Cyan
      $sel = @(Select-Folders $Folders)
      if ($sel.Count -gt 0) { Invoke-Batch $sel @("--dry-run") $false $false }
    }
    "5" {  # test nhanh --limit
      Say "Test nhanh: --limit=N dem ITEM (thu muc + file tron lan), KHONG phai N file. Van phai liet ke toan bo cay truoc." Cyan
      $sel = @(Select-Folders $Folders)
      if ($sel.Count -eq 0) { return }
      $n = Ask "  N = "
      if ($n -notmatch '^\d+$' -or [int]$n -lt 1) { Say "N phai la so nguyen >= 1 - huy." Red; return }
      Say "  (Chi tai ve staging, KHONG push OneDrive, khong ghi trang thai resume.)" DarkGray
      Invoke-Batch $sel @("--limit=$n") $false $false
    }
    "6" {  # retry-failed
      Say "Chay lai CHI cac item loi lan truoc (doc failed-*.json moi nhat trong $ReportsDir). Khong liet ke lai Drive." Cyan
      if (-not (Test-Path $ReportsDir)) { Say "Khong thay thu muc report: $ReportsDir" Red; return }
      $extra = @()
      if (Confirm-Yes "  Thu ca item 'permanent' (khong tu khoi: khoa, qua lon, thieu quyen)?") { $extra += "--include-permanent" }
      Write-Log "`n===== $(Get-Date -Format s) RETRY-FAILED ====="
      $args2 = @("--retry-failed=$ReportsDir", "--path=$Staging", "--creds=$Creds", "--retry=$Retry", "--concurrency=4", "--push-to=$PushTo") + $extra
      Write-Log ("CMD: " + $Exe + " " + ($args2 -join " "))
      try {
        & $Exe @args2 *>&1 | Tee-Object -FilePath $Log -Append
        $code = $LASTEXITCODE; Write-Log "Exit code: $code"
        $script:LastExit = if ($code -eq 0) { 0 } else { 1 }
      } catch { Write-Log "LOI KHONG LUONG TRUOC: $_"; $script:LastExit = 1 }
      Say "LOG: $Log" Cyan
    }
    "7" {  # force
      Say "CANH BAO --force: tai lai TOAN BO file cua folder da chon (khong xoa gi)." Yellow
      Say "Ton bang thong va co the sinh ban xung dot '-DESKTOP-...' tren OneDrive voi file Google-native." Yellow
      Say "Chi dung khi nghi file local hong." Yellow
      $sel = @(Select-Folders $Folders)
      if ($sel.Count -eq 0) { return }
      if (Confirm-Yes "  Xac nhan --force cho $($sel.Count) folder?") { Invoke-Batch $sel @("--force") $true $true }
    }
    "8" {  # scan-duplicates
      Say "Quet file local mo coi / duplicate (chi doc, KHONG tai/xoa/di chuyen). Exit 1 neu co phat hien." Cyan
      $sel = @(Select-Folders $Folders)
      if ($sel.Count -gt 0) { Invoke-Batch $sel @("--scan-duplicates") $false $false }
    }
    "9" {  # trang thai
      if ($Folders.Count -eq 0) { Say "Chua co folder nao trong $FoldersFile" Red } else { Show-Folders $Folders }
      Say "Trang thai doc tu: $StateFile" DarkGray
    }
    "C" {  # xem cau hinh dang dung
      Say "Cau hinh dang dung (uu tien: tham so dong lenh > settings.txt > mac dinh):" Cyan
      Say "  Base         : $Base"
      Say "  Staging      : $Staging     (giu vinh vien)"
      Say "  PushTo       : $PushTo"
      Say "  Concurrency  : $Concurrency"
      Say "  Retry        : $Retry"
      Say "  Exe          : $Exe"
      Say "  Creds        : $Creds"
      Say "  Folders      : $FoldersFile"
      Say "  Reports      : $ReportsDir"
      Say "  Settings     : $SettingsFile $(if (Test-Path $SettingsFile) { '(co)' } else { '(chua co - dang dung mac dinh)' })"
    }
    "L" {  # xem log moi nhat
      $latest = Get-ChildItem (Join-Path $LogDir "run-*.log") -ErrorAction SilentlyContinue | Sort-Object LastWriteTime -Descending | Select-Object -First 1
      if (-not $latest) { Say "Chua co log nao trong $LogDir" Yellow; return }
      Say "40 dong cuoi cua $($latest.FullName):" Cyan
      Get-Content $latest.FullName -Tail 40
    }
    "R" {  # mo thu muc report
      if (Test-Path $ReportsDir) { Start-Process explorer.exe $ReportsDir } else { Say "Chua co $ReportsDir (chi tao khi co item loi)." Yellow }
    }
    "X" {  # reset resume
      Say "Xoa trang thai resume: lan chay [1] sau se coi TAT CA folder la chua xong. Staging va OneDrive KHONG bi dong toi." Yellow
      if (-not (Test-Path $StateFile)) { Say "Chua co $StateFile." Yellow; return }
      if (Confirm-Yes "  Xac nhan?") {
        $bak = "$StateFile.bak-$(Get-Date -Format yyyyMMdd-HHmmss)"
        Rename-Item $StateFile $bak
        Say "Da doi ten thanh $bak (co the doi lai neu can)." Green
      }
    }
    default { Say "Khong co muc '$Choice'." Red }
  }
}

function Show-Menu($Folders) {
  $done = Get-Done
  $left = @($Folders | Where-Object { $done -notcontains $_.Id }).Count
  Write-Host ""
  Say "==================== GDRIVE MIRROR ====================" Cyan
  Say " Drive -> $Staging -> $PushTo" DarkGray
  Say " Folder: $($Folders.Count) tong, $left chua xong   |   Song song: $Concurrency   Retry: $Retry" DarkGray
  Say "-------------------------------------------------------" Cyan
  Say " CHAY DONG BO (tai + push OneDrive)"
  Say "  [1] Chay tiep           chi folder CHUA xong (resume)"
  Say "  [2] Chay lai TAT CA     bo qua resume, re nho delta-sync"
  Say "  [3] Chon folder         chay rieng vai folder"
  Say " THU / KIEM TRA (khong push OneDrive)"
  Say "  [4] Dry-run             liet ke thu, khong tai gi"
  Say "  [5] Test nhanh          --limit=N item dau"
  Say "  [8] Quet duplicate      --scan-duplicates, chi doc"
  Say " XU LY SU CO"
  Say "  [6] Chay lai item LOI   --retry-failed tu report lan truoc"
  Say "  [7] Tai lai toan bo     --force (can xac nhan)"
  Say " THONG TIN"
  Say "  [9] Trang thai folder   [C] Xem cau hinh   [L] Xem log moi nhat   [R] Mo thu muc report"
  Say "  [X] Xoa trang thai resume (doi ten thanh .bak)"
  Say "  [0] Thoat"
  Say "=======================================================" Cyan
}

# ------------------------------------------------------------------- main ----

if (-not (Test-Path $LogDir))  { New-Item -ItemType Directory -Path $LogDir -Force | Out-Null }
if (-not (Test-Path $Staging)) { New-Item -ItemType Directory -Path $Staging -Force | Out-Null }

# Kiem tra som cac file bat buoc de bao loi ro thay vi de loi kho hieu giua chung.
$problems = @()
if (-not (Test-Path $Exe))         { $problems += "Thieu binary: $Exe  (copy tu dist\gdrive-mirror-windows-amd64.exe)" }
if (-not (Test-Path $Creds))       { $problems += "Thieu creds: $Creds" }
if (-not (Test-Path $FoldersFile)) { $problems += "Thieu danh sach folder: $FoldersFile  (copy tu folders.example.txt roi dien ID that)" }
if (-not $PushTo)                  { $problems += "Chua khai bao PushTo (thu muc OneDrive dich). Tao $SettingsFile tu settings.example.txt, hoac truyen -PushTo" }
elseif (-not (Test-Path $PushTo))  { $problems += "PushTo khong ton tai: $PushTo  (OneDrive chua cai/chua dang nhap, hoac sai duong dan trong $SettingsFile)" }
if ($problems.Count -gt 0) {
  $problems | ForEach-Object { Say "[LOI] $_" Red }
  exit 2
}

$folders = @(Get-Folders)
if ($folders.Count -eq 0) { Say "[LOI] $FoldersFile khong co ID nao." Red; exit 2 }

if ($Action) {                      # che do khong menu
  Invoke-Choice $Action $folders
  exit $script:LastExit
}

while ($true) {
  Show-Menu $folders
  $c = Ask "Chon: "
  if ($null -eq $c -or $c -eq "0") { break }
  if ($c -eq "") { continue }
  Write-Host ""
  Invoke-Choice $c $folders
  $folders = @(Get-Folders)         # doc lai: ban co the vua sua folders.txt
}
Say "Tam biet." DarkGray
exit 0
