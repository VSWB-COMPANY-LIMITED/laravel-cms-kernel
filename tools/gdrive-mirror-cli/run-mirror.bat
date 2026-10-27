@echo off
REM ============================================================================
REM  run-mirror.bat -- chay dong bo Google Drive -> staging local -> OneDrive
REM                    bang 1 cu double-click (khong can mo PowerShell)
REM ============================================================================
REM
REM  FILE NAY LAM GI
REM    Chi la "vo boc" goi script PowerShell run-mirror.ps1 (nam CUNG THU MUC voi
REM    file .bat nay), roi hien ket qua OK / CANH BAO va giu cua so de doc.
REM    Toan bo logic dong bo (danh sach folder ID, --push-to, resume...) nam trong
REM    run-mirror.ps1, KHONG nam o day.
REM
REM  YEU CAU (may van hanh Windows)
REM    Thu muc trien khai, mac dinh C:\Tools\gdrive\, phai co du:
REM      run-mirror.bat          <- file nay
REM      run-mirror.ps1          <- script chinh (chua folder ID that, KHONG commit
REM                                 vao git -- xem README muc 11.2 de dung lai)
REM      gdrive-mirror.exe       <- binary (copy tu dist\gdrive-mirror-windows-amd64.exe)
REM      creds.json              <- service-account JSON; cac folder Drive phai duoc
REM                                 share cho client_email trong file nay (Viewer)
REM      logs\                   <- tu tao; chua log moi lan chay + completed-folders.txt
REM
REM  CACH DUNG
REM    1) Double-click file nay, HOAC mo cmd/PowerShell va go:
REM         C:\Tools\gdrive\run-mirror.bat
REM    2) Cho den khi hien "Exit code: ..." -- cay folder to co the mat nhieu phut
REM       o giai doan liet ke (im lang / chi toan WARN la BINH THUONG, khong phai treo).
REM       Chua thay dong "Found N item(s)." = dang liet ke, chua tai.
REM    3) Xem log: C:\Tools\gdrive\logs\run-<yyyyMMdd-HHmmss>.log
REM
REM  EXIT CODE (cua chinh file nay = exit code cua run-mirror.ps1)
REM    0 = moi folder sach, khong loi
REM    1 = co folder loi item hoac exception -- mo log moi nhat de xem; chay lai
REM        duoc, delta-sync chi tai phan thieu
REM
REM  CHAY LAI / RESUME
REM    Folder da xong duoc ghi vao logs\completed-folders.txt va se bi SKIP o lan
REM    sau. Muon 1 folder chay lai: xoa dung dong ID cua no khoi file do (lenh mau
REM    o README muc 11.6). Muon chay lai TOAN BO: xoa han file do -- van re vi
REM    staging C:\GDrive-Mirror con nguyen nen delta-sync bo qua file khong doi.
REM
REM  LUU Y QUAN TRONG
REM    * DUNG build / copy de gdrive-mirror.exe khi script dang chay -- Windows
REM      khong cho ghi de exe dang mo.
REM    * KHONG xoa C:\GDrive-Mirror (staging): do la baseline delta-sync. Xoa di se
REM      khien moi file Google-native bi export lai khac byte -> OneDrive sinh ban
REM      xung dot "<ten>-DESKTOP-<may>-N.ext". Chi tiet: README muc 11.1.
REM    * Folder moi chua share quyen cho service account se bao "Found 0 item(s)"
REM      nhung exit code van la 0 -> bi ghi "xong gia". Share quyen TRUOC, roi xoa
REM      ID do khoi completed-folders.txt. Chi tiet: README muc 11.3.
REM    * File nay viet ASCII khong dau co chu y: cmd.exe doc tieng Viet co dau theo
REM      codepage may, de bi vo chu.
REM
REM  TAI LIEU DAY DU: README.md (cung thu muc trong repo), muc 11 "Van hanh that".
REM ============================================================================

REM setlocal: bien (CODE...) chi ton tai trong file nay, khong ro ra shell goi no.
setlocal

REM Chuyen ve thu muc chua file .bat (%~dp0 = o dia + duong dan cua file nay), de
REM chay duoc du double-click, chay tu cmd o thu muc khac, hay qua Task Scheduler.
REM /d cho phep doi ca o dia. Nho do, copy ca bo sang may/thu muc khac van chay.
cd /d "%~dp0"

echo ===== gdrive-mirror: Drive -^> C:\GDrive-Mirror -^> OneDrive =====
echo Bat dau: %date% %time%
echo.

REM Kiem tra som: thieu run-mirror.ps1 thi bao ro thay vi de PowerShell tu nem loi kho hieu.
if not exist "%~dp0run-mirror.ps1" (
  echo [LOI] Khong tim thay run-mirror.ps1 canh file .bat nay: %~dp0
  echo       Xem README muc 11.2 de dung lai script tren may nay.
  set CODE=2
  goto :done
)

REM -NoProfile        : khong nap profile PowerShell cua user (nhanh hon, ket qua on dinh)
REM -ExecutionPolicy Bypass : chi cho TIEN TRINH NAY, de khong bi chan script chua ky;
REM                     KHONG doi policy he thong.
REM -File             : chay file .ps1; exit code cua script tro thanh ERRORLEVEL o duoi.
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0run-mirror.ps1"
set CODE=%ERRORLEVEL%

:done
echo.
echo Ket thuc: %date% %time%
if %CODE% EQU 0 (
  echo [OK] Tat ca folder xong, khong co loi.
) else if %CODE% EQU 2 (
  echo [LOI] Thieu run-mirror.ps1 -- khong chay duoc.
) else (
  echo [CANH BAO] Co folder loi hoac exception - xem log moi nhat trong %~dp0logs
)
echo Exit code: %CODE%

REM pause: giu cua so de doc ket qua khi double-click. Neu goi tu Task Scheduler /
REM script khac (khong can tuong tac), xoa dong "pause" nay de khong bi treo cho.
pause

REM Tra exit code ra ngoai de scheduler/script goi nhan biet thanh cong hay that bai.
exit /b %CODE%
