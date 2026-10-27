@echo off
REM ============================================================================
REM  run-mirror.bat -- MO FILE NAY LEN de dong bo Google Drive -> staging -> OneDrive
REM ============================================================================
REM
REM  FILE NAY LAM GI
REM    Chi la cai "cong": mo menu run-mirror.ps1 trong cua so nay. Moi lua chon
REM    (chay tiep, chay lai tat ca, chon folder, dry-run, retry loi, --force,
REM    quet duplicate, xem log...) nam trong menu do, khong nam o day.
REM
REM  CACH DUNG
REM    1) Double-click file nay -> hien menu -> go so muc can chay -> Enter.
REM    2) Hoac chay thang 1 muc, khong hien menu (tien cho Task Scheduler):
REM         run-mirror.bat 1      = Chay tiep folder chua xong
REM         run-mirror.bat 2      = Chay lai tat ca folder
REM       Cac muc khac can chon folder nen chi dung duoc trong menu.
REM    3) Cay folder to co the mat nhieu phut o giai doan liet ke -- im lang hoac
REM       chi toan WARN la BINH THUONG, khong phai treo. Chua thay "Found N item(s)."
REM       nghia la dang liet ke, chua tai.
REM
REM  CAN CO TRONG C:\Tools\gdrive\  (hoac thu muc khac, xem GDRIVE_PS1 ben duoi)
REM      run-mirror.ps1       menu + logic (co trong repo)
REM      gdrive-mirror.exe    binary (copy tu dist\gdrive-mirror-windows-amd64.exe)
REM      creds.json           service-account JSON; folder Drive phai share cho
REM                           client_email trong do (Viewer)
REM      folders.txt          danh sach folder ID, moi dong 1 ID (mau: folders.example.txt;
REM                           KHONG commit vi chua ID that)
REM      logs\                tu tao; log moi lan chay + completed-folders.txt (resume)
REM
REM  TIM run-mirror.ps1 THEO THU TU
REM    1) bien moi truong GDRIVE_PS1 neu da dat
REM    2) file canh .bat nay
REM    3) C:\Tools\gdrive\run-mirror.ps1
REM
REM  EXIT CODE  0 = thoat binh thuong / sach;  1 = co folder loi;  2 = thieu file can co
REM
REM  LUU Y QUAN TRONG
REM    * DUNG build / copy de gdrive-mirror.exe khi dang chay -- Windows khong cho ghi
REM      de exe dang mo.
REM    * KHONG xoa C:\GDrive-Mirror (staging): la baseline delta-sync. Xoa di => moi file
REM      Google-native bi export lai khac byte => OneDrive sinh ban xung dot
REM      "<ten>-DESKTOP-<may>-N.ext". Chi tiet: README.md muc 11.1.
REM    * Folder moi chua share quyen cho service account se bao "Found 0 item(s)" nhung
REM      exit code van la 0 => bi ghi "xong gia". Share quyen TRUOC. Chi tiet: README muc 11.3.
REM    * File nay viet ASCII khong dau co chu y: cmd.exe doc tieng Viet co dau theo
REM      codepage may, de bi vo chu.
REM
REM  TAI LIEU DAY DU: README.md, muc 11 "Van hanh that".
REM ============================================================================

REM setlocal: bien (PS1, CODE) chi ton tai trong file nay, khong ro ra shell goi no.
setlocal

REM Chuyen ve thu muc chua file .bat (%~dp0 = o dia + duong dan), de chay duoc du
REM double-click, chay tu cmd o thu muc khac, hay qua Task Scheduler. /d cho phep doi o dia.
cd /d "%~dp0"

REM Tim run-mirror.ps1 -- xem thu tu uu tien o tren.
set "PS1=%GDRIVE_PS1%"
if not defined PS1 set "PS1=%~dp0run-mirror.ps1"
if not exist "%PS1%" set "PS1=C:\Tools\gdrive\run-mirror.ps1"

REM Kiem tra som: thieu script thi bao ro thay vi de PowerShell tu nem loi kho hieu.
REM Luu y: khong dung dau ngoac trong echo ben trong khoi if, cmd se hieu nham.
if not exist "%PS1%" (
  echo [LOI] Khong tim thay run-mirror.ps1 - da thu: canh file .bat, roi C:\Tools\gdrive\
  set CODE=2
  goto :done
)

REM -NoProfile        : khong nap profile PowerShell cua user (nhanh hon, ket qua on dinh)
REM -ExecutionPolicy Bypass : chi cho TIEN TRINH NAY, de khong bi chan script chua ky;
REM                     KHONG doi policy he thong.
REM -File             : chay file .ps1; exit code cua script tro thanh ERRORLEVEL o duoi.
REM %~1               : tham so dau tien cua .bat (vd 1 hoac 2) -> -Action; bo trong = hien menu.
if "%~1"=="" (
  powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%PS1%"
) else (
  powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%PS1%" -Action "%~1"
)
set CODE=%ERRORLEVEL%

:done
echo.
if %CODE% EQU 0 (
  echo [OK] Thoat binh thuong. Exit code: 0
) else if %CODE% EQU 2 (
  echo [LOI] Thieu file can co ^(script / exe / creds / folders.txt^) - xem thong bao o tren. Exit code: 2
) else (
  echo [CANH BAO] Co folder loi hoac exception - xem log moi nhat trong C:\Tools\gdrive\logs. Exit code: %CODE%
)

REM pause: giu cua so de doc ket qua khi double-click. Goi tu Task Scheduler / script khac
REM thi xoa dong "pause" nay de khong bi treo cho.
pause

REM Tra exit code ra ngoai de scheduler / script goi nhan biet thanh cong hay that bai.
exit /b %CODE%
