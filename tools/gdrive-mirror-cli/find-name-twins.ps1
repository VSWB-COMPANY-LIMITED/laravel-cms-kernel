# find-name-twins.ps1 - tim (va tuy chon don) cap file/thu muc TRUNG TEN do khac dang Unicode NFC/NFD.
#
# VI SAO CAN: NTFS giu rieng 2 ten "Bao gia" viet bang ky tu dung san (NFC) va ky tu + dau roi (NFD),
# nhung OneDrive/SharePoint coi la MOT ten -> bao loi "This item has the same name as another item in
# this folder" va tao ban xung dot "-<may>-N". gdrive-mirror khong bao gio xoa file local, nen mot cap
# da lot vao staging thi o lai va bi push len OneDrive moi lan. Xem README.md muc 11.7.
#
# SCRIPT LAM GI (an toan: mac dinh CHI BAO CAO; khi -Apply thi CHUYEN, khong bao gio xoa):
#   A) Cap trung ngay trong STAGING  -> chuyen MOI bien the cua cap do khoi ca staging lan OneDrive.
#      Sau do chay lai gdrive-mirror (menu [2] hoac [3]) de cong cu tai lai dung ten Drive dang dung.
#   B) Cap trung chi o ONEDRIVE      -> giu bien the khop CHINH XAC (tung byte) ten trong staging, chuyen
#      cac bien the con lai. Neu khong bien the nao khop staging thi KHONG dong vao, chi liet ke.
#   Muc ra: <ArchiveDir>\staging\..., <ArchiveDir>\onedrive\... (giu nguyen cay) + manifest.csv
#   (cot original/archived: muon khoi phuc thi chuyen nguoc archived -> original).
#
# CACH DUNG:
#   powershell -File find-name-twins.ps1 -OneDrive "C:\Users\X\OneDrive - Cong ty"            # bao cao
#   powershell -File find-name-twins.ps1 -OneDrive "C:\Users\X\OneDrive - Cong ty" -Apply     # don
#   Tuy chon: -Staging (mac dinh C:\GDrive-Mirror)  -ArchiveDir (mac dinh C:\gdrive-archive\name-twins-<ngay>)
#
# THU TU AN TOAN KHI DON: tam dung dong bo OneDrive (hoac de no chay xong) -> chay -Apply -> chay lai mirror
#   (menu [2]) -> chay lai script nay khong -Apply, ket qua phai la 0 cap o ca hai ben.
# EXIT CODE: 0 = khong con cap trung (hoac da don xong) | 3 = con cap trung (che do bao cao) | 1 = loi tham so/chuyen that bai.
#
# LUU Y KY THUAT: file ASCII thuan (Windows PowerShell 5.1 doc .ps1 khong BOM bang ANSI); dung tien to
# \\?\ de qua gioi han MAX_PATH; ten file KHONG bao gio bi doi - chi so sanh bang khoa NFC + chu thuong.
param(
  [Parameter(Mandatory = $true)][string]$OneDrive,
  [string]$Staging = "C:\GDrive-Mirror",
  [string]$ArchiveDir = "",
  [switch]$Apply
)
$ErrorActionPreference = "Stop"
$LP = '\\?\'
if (-not $ArchiveDir) { $ArchiveDir = "C:\gdrive-archive\name-twins-" + (Get-Date -Format yyyyMMdd) }
foreach ($p in @($Staging, $OneDrive)) { if (-not (Test-Path -LiteralPath $p)) { Write-Host "Khong thay thu muc: $p" -ForegroundColor Red; exit 1 } }
$Staging = $Staging.TrimEnd('\'); $OneDrive = $OneDrive.TrimEnd('\')

function Fold([string]$s) { return $s.Normalize([Text.NormalizationForm]::FormC).ToLowerInvariant() }

# Quet 1 thu muc goc -> danh sach nhom {cung thu muc-cha-gap + ten-gap} co >= 2 muc.
# Moi muc: Rel (duong dan tuong doi, giu nguyen byte), IsDir.
function Get-TwinGroups([string]$Root) {
  $g = @{}
  foreach ($i in Get-ChildItem -LiteralPath ($LP + $Root) -Recurse -Force -ErrorAction SilentlyContinue) {
    $rel = $i.FullName.Replace($LP, '').Substring($Root.Length)
    $key = (Fold (Split-Path $rel -Parent)) + '|' + (Fold $i.Name)
    if (-not $g.ContainsKey($key)) { $g[$key] = New-Object System.Collections.Generic.List[object] }
    $g[$key].Add([pscustomobject]@{ Rel = $rel; IsDir = [bool]$i.PSIsContainer })
  }
  $out = New-Object System.Collections.Generic.List[object]
  foreach ($k in $g.Keys) { if ($g[$k].Count -gt 1) { $out.Add($g[$k].ToArray()) } }
  return , $out   # dau phay: giu nguyen List, khong de PowerShell bung tung nhom ra
}

Write-Host "Quet staging : $Staging"
$stgGroups = Get-TwinGroups $Staging
Write-Host "Quet OneDrive: $OneDrive"
$odGroups = Get-TwinGroups $OneDrive
Write-Host ("Cap trung: staging={0}  onedrive={1}" -f $stgGroups.Count, $odGroups.Count)

# Ke hoach chuyen: danh sach {Root, Base, Rel, IsDir}
$plan = New-Object System.Collections.Generic.List[object]
$manual = New-Object System.Collections.Generic.List[string]
$stgKeys = @{}
foreach ($grp in $stgGroups) { foreach ($v in $grp) { $stgKeys[(Fold $v.Rel)] = $true } }

# A) tat ca bien the cua cac nhom staging, o ca hai ben
if ($stgKeys.Count -gt 0) {
  foreach ($root in @(@{ N = 'staging'; P = $Staging }, @{ N = 'onedrive'; P = $OneDrive })) {
    foreach ($i in Get-ChildItem -LiteralPath ($LP + $root.P) -Recurse -Force -ErrorAction SilentlyContinue) {
      $rel = $i.FullName.Replace($LP, '').Substring($root.P.Length)
      if ($stgKeys.ContainsKey((Fold $rel))) { $plan.Add([pscustomobject]@{ Root = $root.N; Base = $root.P; Rel = $rel; IsDir = [bool]$i.PSIsContainer }) }
    }
  }
}
# B) nhom chi o OneDrive: giu bien the khop staging tung byte
foreach ($grp in $odGroups) {
  if ($stgKeys.ContainsKey((Fold $grp[0].Rel))) { continue }   # da nam trong ke hoach A
  $keep = @($grp | Where-Object { Test-Path -LiteralPath ($LP + $Staging + $_.Rel) })
  if ($keep.Count -eq 0) { $manual.Add(($grp | ForEach-Object { $_.Rel }) -join '  ||  '); continue }
  foreach ($v in $grp) {
    if (-not (Test-Path -LiteralPath ($LP + $Staging + $v.Rel))) {
      $plan.Add([pscustomobject]@{ Root = 'onedrive'; Base = $OneDrive; Rel = $v.Rel; IsDir = $v.IsDir })
    }
  }
}
# thu muc truoc, duong dan ngan truoc: khi cha da chuyen thi con tu mat theo (bo qua o buoc chuyen)
$plan = @($plan | Sort-Object @{ e = { $_.IsDir }; Descending = $true }, @{ e = { $_.Rel.Length } })

Write-Host ("Ke hoach: {0} muc se chuyen (staging {1}, onedrive {2}); {3} nhom khong tu xu ly duoc" -f `
  $plan.Count, @($plan | Where-Object { $_.Root -eq 'staging' }).Count, @($plan | Where-Object { $_.Root -eq 'onedrive' }).Count, $manual.Count)
if ($manual.Count -gt 0) { Write-Host "-- Nhom KHONG bien the nao khop staging (de nguyen, soat tay):" -ForegroundColor Yellow; $manual | Select-Object -First 20 | ForEach-Object { Write-Host "   $_" } }

if (-not $Apply) {
  $plan | Select-Object -First 40 | ForEach-Object { Write-Host ("   {0,-8} {1} {2}" -f $_.Root, $(if ($_.IsDir) { 'DIR ' } else { 'FILE' }), $_.Rel) }
  if ($plan.Count -gt 40) { Write-Host "   ... (+$($plan.Count - 40) muc)" }
  if ($plan.Count -eq 0 -and $manual.Count -eq 0) { Write-Host "OK: khong con cap trung." -ForegroundColor Green; exit 0 }
  Write-Host "(bao cao - chua chuyen gi. Them -Apply de don.)" -ForegroundColor Cyan
  exit 3
}

$done = New-Object System.Collections.Generic.List[object]; $skip = 0; $fail = 0
foreach ($p in $plan) {
  $src = $LP + $p.Base + $p.Rel
  $exists = if ($p.IsDir) { [IO.Directory]::Exists($src) } else { [IO.File]::Exists($src) }
  if (-not $exists) { $skip++; continue }
  $dst = $LP + $ArchiveDir + '\' + $p.Root + $p.Rel
  try {
    [IO.Directory]::CreateDirectory((Split-Path $dst -Parent)) | Out-Null
    $dstExists = if ($p.IsDir) { [IO.Directory]::Exists($dst) } else { [IO.File]::Exists($dst) }
    if ($dstExists) { $dst = $dst + '.dup' + (Get-Random) }
    if ($p.IsDir) { [IO.Directory]::Move($src, $dst) } else { [IO.File]::Move($src, $dst) }
    $done.Add([pscustomobject]@{ root = $p.Root; isdir = $p.IsDir; original = ($p.Base + $p.Rel); archived = $dst.Replace($LP, '') })
  } catch { $fail++; Write-Host "LOI chuyen: $($p.Rel) : $($_.Exception.Message)" -ForegroundColor Red }
}
New-Item -ItemType Directory -Force $ArchiveDir | Out-Null
$manifest = Join-Path $ArchiveDir "manifest.csv"
$done | Export-Csv $manifest -NoTypeInformation -Encoding UTF8 -Delimiter ';'
Write-Host ("Da chuyen {0} muc | bo qua {1} (da mat theo thu muc cha) | loi {2}" -f $done.Count, $skip, $fail)
Write-Host "Archive : $ArchiveDir"
Write-Host "Manifest: $manifest"
if (@($done | Where-Object { $_.root -eq 'staging' }).Count -gt 0) {
  Write-Host "BUOC TIEP: chay lai gdrive-mirror (run-mirror: muc [2]) de tai lai dung ten Drive, roi chay lai script nay khong -Apply." -ForegroundColor Cyan
}
if ($fail -gt 0) { exit 1 }
exit 0
