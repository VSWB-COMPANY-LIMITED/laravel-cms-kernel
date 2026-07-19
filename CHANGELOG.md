# Changelog — dev-extensions/kernel

## [Unreleased]

### Fixed
- **[GDriveMirrorSync + gdrive-mirror-cli] Hậu tố chống trùng: ID ngắn và bộ đếm dự phòng.**
  Bắt được khi review port PHP (2026-07-19) — khiếm khuyết có ở CẢ hai bản, đã sửa đồng bộ:
  - vòng dò hậu tố bắt đầu ở `8` cứng nên ID Drive ngắn hơn 8 ký tự làm nhánh ID không chạy lần
    nào, rơi thẳng xuống nhánh bộ đếm dự phòng → `min(8, strlen($id))`;
  - nhánh bộ đếm nối `-2` vào SAU phần mở rộng (`bao cao_ID.pdf-2`) → file mất đuôi `.pdf` và
    không còn mở được theo association. Gom về một hàm `withSuffixBeforeExt()` dùng chung cho
    mọi kiểu hậu tố để không call site nào nối ra sau đuôi được nữa.
  Có test hồi quy ở cả hai bản (`GDriveResolveSiblingNamesTest`, `list_test.go`).
- **[GDriveMirrorSync] Port bộ fix C1-C5/L1-L3 (trùng tên + an toàn tên file mọi OS) từ bản Go
  `tools/gdrive-mirror-cli` (commit `2dc0969`) sang `GDriveMirrorSync.php`.**
  Bản PHP (chế độ Service Account/Drive API — `fetchFolderChildrenViaApi()`) từng có 8 lỗi:
  - **C1** — 2 file Drive khác nhau chỉ khác hoa/thường bị NTFS/APFS coi là 1 file → ghi đè mất
    dữ liệu im lặng (`$usedLocalPaths` so khớp phân biệt hoa-thường).
  - **C2** — file và folder trùng tên cùng cha → file ghi đè lên thư mục → fail mãi mãi.
  - **C3** — 2 folder trùng tên → nội dung 2 cây con Drive gộp lẫn lộn im lặng.
  - **C4** — hậu tố chống trùng gán theo THỨ TỰ Drive trả về (không đảm bảo ổn định giữa 2 lần
    gọi) → Drive đổi thứ tự là sinh bản sao rác vô hạn (tool không bao giờ xoá file local).
  - **L1** — không giới hạn 255 byte/component (giới hạn cứng NTFS); đuôi export + hậu tố chống
    trùng cộng thêm mà không kiểm tra.
  - **L2** — ký tự Windows cấm (`< > : " | ? * \ /`) không được lọc khỏi tên Drive.
  - **L3** — tên thiết bị Windows dành riêng (`CON`, `PRN`, `COM1-9`, `LPT1-9`…) không bị chặn.
  - **C5** — Drive cho phép `/` và `..` trong tên → nối chuỗi trần (`"{$baseLocalPath}/{$syncPath}"`)
    có thể ghi RA NGOÀI thư mục đích.

  **Fix:**
  1. Thêm 3 static pure helper (port 1:1 `internal/localpath/localpath.go`): `sanitizeNameComponent()`
     (lọc ký tự cấm/control + trim dấu chấm-space cuối + chặn tên reserved), `truncateNameComponent()`
     (cap 255 BYTE UTF-8, cắt đúng ranh giới ký tự, hash chống đụng khi cắt thật — kèm fix bẫy
     `pathinfo(PATHINFO_EXTENSION)` lấy dấu chấm CUỐI làm "extension" khiến tên kiểu VN
     `"Bao cao Q1.2026 - <text dài>"` giữ nguyên đuôi dài verbatim và VƯỢT trần 255 byte), và
     `safeJoinLocalPath()` (nối + xác thực path nằm trong root, chuẩn hoá bằng logic CHUỖI THUẦN
     vì `realpath()` trả `false` khi path chưa tồn tại).
  2. Tách `fetchFolderChildrenViaApi()` thành `collectRawChildrenViaApi()` (gom đủ mọi trang con
     TRỰC TIẾP trước, giữ nguyên `withRetry()`/BUG A-1/BUG B) + `resolveSiblingNames()` (dedup
     THUẦN, chung 1 namespace file+folder, khoá theo Drive file ID TĂNG DẦN — không theo thứ tự
     `files.list` trả về) — tên collision giờ quyết định SAU KHI đã thấy đủ tập anh em, ổn định
     bất kể Drive trả về theo thứ tự nào.
  3. `handle()`: bỏ khối "Handle Name Collisions" cũ (đổi tên bằng cách nối 8 ký tự ID theo thứ
     tự gặp trong vòng lặp chính) — dedup thật đã chuyển lên tầng listing. `$usedLocalPaths` giữ
     lại làm LỚP PHÒNG THỦ: còn đụng ở đây tức bug tầng trên → log ERROR + `failed_files`
     (permanent), KHÔNG âm thầm đổi tên/ghi đè. `$absoluteLocalPath` dùng `safeJoinLocalPath()`
     thay nối chuỗi trần — bị từ chối thì permanent, KHÔNG tính vào circuit breaker ổ đĩa.
     `$localPrefix` (tên folder gốc) cũng qua sanitize+truncate.
  4. `classifyDriveError()`/`isLocalFsError()`: thêm `isInvalidLocalName()` (nhận diện
     `ENAMETOOLONG`/EINVAL kèm path tuyệt đối/lỗi `safeJoinLocalPath()`) — gọi SỚM NHẤT, luôn
     permanent, và luôn KHÔNG được tính là bằng chứng ổ đĩa cục bộ hỏng (nguyên tắc bất đối xứng
     giống `isLocalFsError()`: đoán nhầm "ổ hỏng" ABORT OAN cả run, nặng hơn đoán nhầm "lỗi tên").

  **Khác bản Go về kiến trúc** (không port nguyên văn, xử lý riêng — xem báo cáo triển khai):
  - Bản Go ghi file qua temp-file có tên ĐỘ DÀI CỐ ĐỊNH (`tempDownloadPath()`) rồi rename — bản
    PHP vốn đã KHÔNG dùng temp-file (`streamDownloadViaApi()`/`File::put()` ghi thẳng vào đích,
    đúng docblock "KHÔNG tạo file .bak/.old/.tmp"), nên không có gì cần sửa cho phần này; trần
    255 byte của `truncateNameComponent(..., reserve: 0)` đã đủ vì không có hậu tố tạm nào phải
    chừa chỗ.
  - Bản PHP có nhánh "Deep Check" (`files->get()` lấy lại mimeType khi listing thiếu/generic) mà
    bản Go không có — nếu Deep Check phát hiện exportSpec KHÁC với cái `resolveSiblingNames()` đã
    dùng ở tầng listing, `handle()` vẫn append đuôi export CÓ ĐIỀU KIỆN (chỉ khi tên hiện tại
    CHƯA có đúng đuôi) để không mất phần mở rộng ở ca hiếm này.

  Test: `tests/Unit/GDriveLocalPathTest.php` (19 test — sanitize/truncate/safeJoin, bao gồm bẫy
  `pathinfo` đuôi dài), `tests/Unit/GDriveResolveSiblingNamesTest.php` (10 test — C1/C2/C3/C4 +
  Google-native export ext + sanitize-before-dedup), `tests/Unit/GDriveInvalidLocalNameTest.php`
  (10 test — `isInvalidLocalName()` + tích hợp `classifyDriveError()`/`isLocalFsError()`). Đã
  sanity-check: tạm revert case-folding trong `resolveSiblingNames()` → test C1 đỏ đúng như kỳ
  vọng (RULE #0.0), rồi khôi phục lại.

- **[GDriveMirrorSync] Preflight ghi-thử THẬT (`writeProbe()`) thay `is_writable()`.**
  Sự cố THẬT 2026-07-18 (run `7fe28ffb`, mirror folder `HopDong_KhachHang/2026`): ổ exFAT ngoài
  `/Volumes/WD-DATA1` hỏng I/O — `is_writable()` báo `true` nhưng `mkdir`/ghi thật ném
  `"Input/output error"` (errno=5). Preflight cũ (chỉ `is_writable()`) lọt qua → list 227 item
  Drive → mirror → đụng circuit breaker sau 20 lỗi filesystem liên tiếp (~1 phút phí; circuit
  breaker HOẠT ĐỘNG ĐÚNG, abort sạch, 0 data-loss — nhưng lẽ ra bắt được sớm hơn). Fix: preflight
  ghi 1 file probe thật (`file_put_contents` → đọc lại → xoá); fail → abort NGAY trước khi list
  Drive. Bắt được ổ read-only / hỏng-I/O mà `is_writable()` bỏ sót.
  ⚠️ Giới hạn đã đo & ghi rõ trong code: khi ổ wedged NẶNG (I/O treo hẳn, `mkdir`/`diskutil
  unmount` cũng đơ) thì `file_put_contents` cũng treo — PHP không timeout I/O file cục bộ mà không
  cần pcntl. KHÔNG regression (ổ treo thì code cũ cũng treo ở lần ghi đầu). Ổ treo cứng =
  pathology phần cứng, phải rút-cắm-lại/reboot.
  Test: `tests/Unit/GDriveWriteProbeTest.php` (4 test, thư mục tạm THẬT). Verify-live trên ổ
  fast-error hoãn tới khi ổ hồi phục (hiện ổ đang treo cứng).

### Added
- **[GDriveMirrorSync] Spreadsheet danh sách file lỗi — TỰ ĐỘNG xuất mỗi lần chạy có `failed_files` (mặc định BẬT, không cần cờ).**
  User dùng Excel trên macOS locale Việt Nam — JSON report cũ (`failed/failed-*.json`) không mở
  trực tiếp bằng Excel được, phải tự viết script chuyển đổi mỗi lần cần soi lỗi.
  Nay `finalReport()` tự gọi `writeFailedSpreadsheet()` ngay sau `writeFailedReport()`: ghi
  `storage/app/gdrive-sync/<dmY_His>.xlsx` (vd `17072026_231609.xlsx`) nếu project có
  OpenSpout/PhpSpreadsheet, ngược lại `.csv` với **BOM UTF-8 + dấu `;`** (Excel trên macOS locale
  VN coi `,` là dấu thập phân → CSV chuẩn dùng `,` bị dồn hết vào 1 cột — đây là lý do chính user
  gặp phải). Kernel package KHÔNG hard-depend OpenSpout/PhpSpreadsheet (chỉ pull-server có) nên
  chọn writer qua `class_exists()` guard.
  20 cột: `file, path, local_path, folder_root_id, loai, google_native, mime_type, size_bytes,
  size, category, error_reason, permanent, attempts, error_message, drive_id, drive_link, run_id,
  report_time, trung_file, dong_dai_dien` — sort permanent trước → category → size giảm dần;
  `trung_file`/`dong_dai_dien` đánh dấu (không xoá dòng) khi cùng 1 file Drive lặp qua nhiều folder
  gốc lồng nhau. Giữ 10 bản mới nhất (tái dùng ngưỡng `MAX_FAILED_REPORTS`), lưu ở root
  `storage/app/gdrive-sync/` (không phải `failed/`).
  Logic build dòng tách thành `buildFailedRows()` (pure/static) + `renderFailedCsv()` (pure/static)
  để test không cần boot Laravel/OpenSpout. Test: `tests/Unit/GDriveFailedRowsTest.php` (11 test).
  Nhánh `.xlsx` không test được trong kernel (thiếu lib) — verify live ở pull-server.

### Fixed
- **[GDriveMirrorSync][🔴 file âm thầm thiếu khỏi mirror LẪN manifest] `cannotDownloadFile` vào allowlist permanent.**
  Phát hiện khi CHẠY THẬT để verify các fix bên dưới (run `9cd3158b` 2026-07-17): file dính 403
  `reason: cannotDownloadFile` ("This file cannot be downloaded by the user" — chủ file TẮT quyền
  tải xuống cho người xem). Reason này KHÔNG có trong `$permanentReasons` → rơi xuống nhánh
  403-fallback → `retryable=true` → hai hậu quả: (1) mỗi file đốt 3 retry × backoff (2+4+8=14s) rồi
  vẫn fail — retry KHÔNG BAO GIỜ khỏi vì chỉ chủ file đổi setting chia sẻ mới gỡ được; (2) NẶNG HƠN
  — không được đánh `permanent` nên KHÔNG lọt vào manifest `unexportable/` (vốn chỉ liệt kê item
  permanent) → file thiếu khỏi mirror mà không ai nhìn thấy.
  **Số đo thật** (report `failed-66c5cc45-20260717-150730.json` + manifest `66c5cc45.md`): run fail
  28 file = 23 permanent + 5 retryable; 5 retryable đó ĐÚNG BẰNG 5 file `cannotDownloadFile` (reason
  DUY NHẤT bị phân loại nhầm trong cả run), và manifest liệt kê đúng 23 file (13 `exportSizeLimit` +
  7 `cannotExportFile` + 3 `fileNotDownloadable`) — 5 file kia KHÔNG xuất hiện dòng nào, tức thiếu
  khỏi cả mirror LẪN tài liệu sinh ra để báo "đang thiếu gì".
  Đây ĐÚNG class bug với `cannotExportFile` đã vá 2026-07-16. Fix: thêm vào allowlist + ghi chú
  manifest nêu rõ cách gỡ (xin chủ bật lại "Viewers can download") — khác `cannotExportFile` vốn vô
  phương cứu. Test hồi quy dùng body JSON copy nguyên văn từ log.
- **[GDriveMirrorSync][🔴🔴 1 lỗi đọc đĩa giết CẢ RUN] Guard I/O cho delta-check (`md5_file()`/`filesize()`/`File::lastModified()`).**
  Phát hiện từ log THẬT (`storage/logs/pull.vn-2026-07-17.log` trên pull-server, run `f0547f35`
  01:19:20): ổ đích exFAT ngoài (`/Volumes/WD-DATA1`) rớt kết nối giữa chừng khi `md5_file()` đang đọc
  file local để so sánh delta → PHP ném `E_WARNING` `"md5_file(): Read of 8192 bytes failed with
  errno=5 Input/output error"` → Laravel `HandleExceptions` biến WARNING thành `ErrorException` →
  KHÔNG có try/catch nào trong vòng lặp bắt được (chưa có trước đây) → thoát thẳng ra try/catch NGOÀI
  CÙNG của `handle()` → 💥 Fatal Error giết CẢ run (5760 item, kể cả item hoàn toàn khoẻ mạnh).
  Fix: bọc toàn bộ khối delta-check trong try/catch `\Throwable`. Lỗi đọc local → KHÔNG skip, KHÔNG
  fatal — coi như "không verify được bản local", rơi xuống nhánh download lại (self-heal, khớp hành
  vi đã có sẵn cho size/MD5 mismatch). Log warning kèm `run_id`/`path`/`error`.
- **[GDriveMirrorSync][🔴 retry vô ích khi ổ đĩa hỏng] Tách lỗi filesystem cục bộ khỏi `classifyDriveError()` + circuit breaker.**
  Log THẬT (run `f0547f35` 01:19-01:21): hàng chục file liên tiếp lặp lại y hệt lỗi
  `"mkdir(): Permission denied"` (mkdir gọi từ `File::ensureDirectoryExists()` bên trong callback
  download của `withRetry()`) — mỗi file bị `classifyDriveError()` string-match nhầm thành
  `'Permission denied'` retryable=true (đúng cho lỗi Drive API, SAI cho lỗi ổ đĩa cục bộ) → tốn 3 lần
  retry × backoff (2+4+8=14s) MỖI FILE trong khi ổ đã hỏng — đốt ~2 phút chỉ để fail đều đặn.
  Fix: `isLocalFsError()` (pure/static — nhận diện qua marker tên hàm PHP filesystem
  `mkdir(`/`fopen(`/`md5_file(`/… + cụm `errno=5`/`input/output error`/`read-only file system`/
  `no space left`; `"permission denied"` TRẦN chỉ nhận khi kèm đường dẫn tuyệt đối, tránh đoán nhầm
  lỗi Drive 403 — nghiêng an toàn về `false` khi không chắc). `withRetry()` kiểm `isLocalFsError()`
  TRƯỚC `classifyDriveError()`: nhận diện được → fail ngay, KHÔNG retry. Circuit breaker
  `MAX_CONSECUTIVE_LOCAL_FS_ERRORS = 20`: vượt ngưỡng lỗi filesystem cục bộ LIÊN TIẾP → ABORT TOÀN BỘ
  run (break khỏi cả 2 vòng foreach, vẫn chạy `finalReport()`), báo rõ đường dẫn ổ đích nghi hỏng.
  Preflight đầu `handle()`: kiểm thư mục đích tồn tại/tạo được + ghi được TRƯỚC khi list Drive — tránh
  lặp lại kịch bản run `b5200616` 08:54 (cày liệt kê Drive xong mới phát hiện đích không ghi được).
  Test: `tests/Unit/GDriveLocalFsErrorTest.php` (16 test — message thật từ log + body JSON Drive thật,
  hồi quy cho nguyên tắc bất đối xứng "không chắc → nghiêng retryable/không-abort").
- **[GDriveMirrorSync][🔴🔴 GHI RÁC LÊN ĐĨA + BÁO THÀNH CÔNG] `streamDownloadViaApi()` không kiểm HTTP status.**
  Phát hiện khi chạy sync THẬT trên một folder ~2700 item: một file 420 byte trên đĩa chứa **JSON lỗi
  403 của Google** (`fileNotDownloadable`) thay vì nội dung — mà run vẫn báo `❌ Errors encountered: 0`.
  Chuỗi nhân quả: file là `google-apps.shortcut` → không có trong `$exportMap` → bị coi là file nhị
  phân → `streamDownloadViaApi()` ghi thẳng body ra đĩa KHÔNG check status; `google/apiclient` set
  `http_errors=false` (Client.php:1265) nên Guzzle KHÔNG throw ở 4xx → `withRetry()` trả true →
  đếm là updated → `touch(mtime remote)` → delta-check lần sau (native không có md5 → so mtime) →
  **SKIP vĩnh viễn**. Rác tự bảo tồn, không bao giờ tự lành.
  ⚠️ Hệ quả: toàn bộ `classifyDriveError()` bị ĐI VÒNG QUA — nó chỉ chạy khi có exception.
  Fix: `assertDownloadOk()` (pure/static) check status → throw raw body → `classifyDriveError()` parse
  được reason. `fopen(...,'w')` chuyển xuống SAU check (nó truncate ngay lập tức → mở trước sẽ phá
  file tốt thành rỗng khi request lỗi). `files->export()` đã throw sẵn qua `REST::decodeHttpResponse()`
  → xác minh bằng đọc code, không sửa thừa.
- **[GDriveMirrorSync] `fileNotDownloadable` → permanent** (`Not downloadable (Docs Editors/shortcut)`).

### Added
- **[GDriveMirrorSync] Bỏ qua `google-apps.shortcut` trước khi tải** — shortcut là con trỏ, không phải
  nội dung; target của nó được sync riêng như file độc lập (kiểm chứng: spreadsheet thật cùng tên vẫn
  export đúng ra .xlsx 226KB). Skip + log info kèm run_id/path/shortcut_target_mime.


### Added
- **[GDriveMirrorSync][lỗ hổng dữ liệu vô hình] Manifest `storage/app/gdrive-sync/unexportable/<folderTag>.md` — liệt kê file KHÔNG THỂ mirror + link tải tay.**
  Trên dữ liệu thật: **29 file unique** (dedup theo Drive ID từ 74 entry qua 6 report) không bao giờ vào
  được mirror — 19× quá lớn (>10MB, lớn nhất 76MB; giới hạn cứng của API `files.export`), 10× bị chủ file
  khoá. Trước đây command vẫn in "✨ MIRROR SYNC COMPLETED" rồi kết thúc → muốn biết thiếu file nào phải
  đào JSON report thủ công. Nay: manifest Markdown nhóm theo reason, sort size giảm dần, kèm link
  `docs.google.com/...` build từ mimeType (không gọi API) để tải tay file >10MB. Console + log summary
  (`unexportable_manifest`) trỏ thẳng tới file.
  Key theo **folderTag + GHI ĐÈ** (khác `failed/` archive theo timestamp): mục đích là "hiện còn thiếu
  file nào", không phải lịch sử từng lần chạy. Run nào folder hết permanent-fail → xoá manifest cũ.
  Test: `tests/Unit/GDriveUnexportableManifestTest.php` (7 test, builder pure/static).

### Fixed
- **[GDriveMirrorSync][🔴 retry vô ích cho lỗi 403 vĩnh viễn] Phân loại lỗi Drive theo `reason` JSON, không string-match message.**
  Phân tích 74 entry lỗi thật (6 report `storage/app/gdrive-sync/failed/*.json`): 100% là 403 vĩnh viễn
  (`exportSizeLimitExceeded` 50×, `cannotExportFile` 24×) — 0 lỗi transient. `cannotExportFile` (file bị
  Google lock) từng bị dán nhãn nhầm "Permission denied" vì message chứa chuỗi "403" (string-match cũ),
  và bị **retry 3 lần vô ích** (backoff 2+4+8=14s/file) dù lỗi này KHÔNG BAO GIỜ tự khỏi. Fix: thêm
  `GDriveMirrorSync::classifyDriveError()` (pure/static) đọc `error.errors[0].reason` từ body JSON thật
  của Google trước, fallback heuristic string-match cho lỗi non-JSON (network/curl). `withRetry()` dừng
  ngay lập tức (không sleep) khi lỗi permanent; log mỗi lần retry thất bại (trước đây chỉ có console
  `comment()` — chạy qua cron là mất sạch). `categorizeError()` cũ giữ nguyên làm wrapper mỏng.
  Test hồi quy: `tests/Unit/GDriveErrorClassificationTest.php`.
- **[GDriveMirrorSync][🔴 rate-limit 403 bị nuốt thành permanent → mất file im lặng] Nguyên tắc allowlist cho `classifyDriveError()`.**
  Drive trả rate-limit dưới **cả 429 lẫn 403** (`dailyLimitExceeded`, `sharingRateLimitExceeded`). Chuỗi
  fallback ban đầu match `403` TRƯỚC `rate`/`quota` và trả `retryable=false` → lỗi TẠM THỜI bị coi là vĩnh
  viễn → file bị bỏ hẳn, âm thầm mất khỏi mirror. Ở code cũ thứ tự này vô hại (`categorizeError()` chỉ dùng
  để hiển thị), nhưng khi nó trở thành thứ QUYẾT ĐỊNH retry thì lỗi cosmetic thành lỗi mất dữ liệu.
  Fix: (1) bổ sung `dailyLimitExceeded`/`sharingRateLimitExceeded` vào transient; (2) kiểm rate/quota trước
  403; (3) **permanent = allowlist reason đã biết chắc, mọi thứ còn lại retryable=true** — vì hai chiều sai
  không cân xứng: đoán nhầm permanent = mất file vĩnh viễn, đoán nhầm retryable = phí ~14s backoff.
- **[GDriveMirrorSync][report tích luỹ vô hạn] Rotate `storage/app/gdrive-sync/failed/` — giữ 10 report mới nhất.**
  Đã thấy 6 report tích luỹ, trong đó 3 file (folder `66c5cc45`) + 2 file (folder `cb9bd785`) trùng nội
  dung hoàn toàn: cùng bộ file fail y hệt, mỗi lần chạy lại đẻ thêm 1 report mới, không ai dọn.
  Fix: `pruneOldFailedReports()` sau mỗi lần ghi report, giữ `MAX_FAILED_REPORTS = 10`.
- **[GDriveMirrorSync][docblock sai đường dẫn log] Sửa `storage/logs/laravel-YYYY-MM-DD.log` → `storage/logs/pull.vn-YYYY-MM-DD.log`** (channel `daily` của pull-server trỏ `pull.vn.log`, không phải mặc định Laravel).
- **[GDriveMirrorSync][skip im lặng mất dấu vết] Log warning cho các nhánh trước đây chỉ `$this->warn()`/`continue` im lặng**: service-account mode gặp path (không phải Folder ID), "Found 0 items", và empty catch của deep-check mimeType. Chạy qua cron trước đây các trường hợp này biến mất hoàn toàn khỏi log.

### Added
- **[GDriveMirrorSync] `--include-permanent`**: mặc định `--retry-failed` CHỈ nạp item retryable (bỏ qua item `permanent`); cờ này nạp cả 2 loại. Report JSON mỗi item thêm `permanent`/`error_reason`/`category`; payload top-level thêm `permanent_count`/`retryable_count`.
- **[GDriveMirrorSync] `run_id`** (uniqid ngắn) gắn vào mọi log entry của 1 lần chạy (START/END/warning/error) — truy vết được 1 run cụ thể khi chạy qua cron. Summary log (END) thêm `collisions`, `permanent_count`, `duration_sec`.

- **[Google Sheets][🔴🔴 CRITICAL — mất lead IM LẶNG do collision] `apps_google_sheet()` revert từ `values.update A{count(A:A)+1}` về `append(INSERT_ROWS)`.**
  Bản `values.update` tự đọc cột A rồi ghi `A{count+1}`: khi nhiều lead ghi dồn (queue burst) + Google
  **read-after-write lag**, mọi lead đọc `count(A:A)` bị stale (=1, chỉ thấy header) => cùng tính targetRow=2
  => **ĐÈ lên nhau ở A2**, chỉ dòng ghi cuối sống sót (data-loss IM LẶNG cho MỌI khách dù API trả 200).
  Case thực: sheet "Follow CRM - Teraco" **5/5 lead cùng `A2:G2`**, sheet khác (Toyota/TOY) append đúng dòng
  13203/20523. Fix: quay lại `append([$row],'RAW','INSERT_ROWS')` — Google tính dòng cuối **server-side
  ATOMIC** => hết collision; verify `apps_gsheet_append_succeeded`. Đánh đổi CÓ CHỦ ĐÍCH (user chốt): append
  dò "bảng" nên nếu KHÁCH tự bật Filter/ẩn có thể lệch — chấp nhận lỗi **chủ quan hiếm** này để đổi lấy chống
  mất-lead IM LẶNG cho MỌI khách. Giữ retry/backoff + reset singleton + Cache::lock. Gỡ helper
  `apps_gsheet_next_row_index` (nguồn bug). Test `AppsGsheetWriteTest` cập nhật (bỏ case next_row_index).
- **[Google Sheets][🔴 mất lead khi Google 503/500/429] Retry + backoff cho lỗi TẠM THỜI khi ghi sheet.**
  `values.update` gặp **503 UNAVAILABLE / 500 INTERNAL / 429 rate-limit** (Google hiccup hoặc ghi dồn dập
  vượt quota ~60/phút, vd backfill hàng loạt) => trước đây KHÔNG retry => lead RỚT (cả real-time lẫn
  backfill). Fix: `apps_gsheet_write_with_retry` bọc `->update()` — backoff luỹ thừa (0.5→1→2→4s) tối đa 4
  lần cho lỗi tạm thời, đồng thời tự nới grid nếu sheet đầy. Backoff cũng tự **throttle** nhịp ghi khi
  backfill. Helper thuần `apps_gsheet_is_transient_error` + test (grid-400/permission-403 KHÔNG retry).
- **[Google Sheets][🔴 lead kẹt khi sheet ĐẦY dòng] Tự nới grid (auto-grow) khi values.update vượt grid.**
  `values.update` (ghi tường minh A{last+1}) KHÔNG tự nới grid như `append(INSERT_ROWS)`. Khi sheet đầy
  (targetRow > rowCount) Google trả **400 "exceeds grid limits"** => lead KHÔNG đẩy được (sự cố thực tế:
  Toyota "Lead Hilux" đầy 19765 dòng + "DGM 2026" đầy 11929 dòng, kẹt từ đầu tháng 7). Fix: bắt lỗi
  grid-limit quanh `->update()` => `apps_gsheet_ensure_grid_capacity()` gọi `appendDimension` thêm 2000
  dòng cuối sheet => ghi lại. **KHÔNG phải chừa dòng trống thủ công** cho từng sheet khách; grid tự lớn
  theo lead. Helper thuần `apps_gsheet_is_grid_limit_error` + test `AppsGsheetWriteTest` (logic 3/3).
- **[Google Sheets][regression của chính bản values.update] Reset range Sheets tránh rò singleton.**
  Bản trước set `->range('A:A')`/`->range('A{n}')` nhưng KHÔNG xoá => `Sheets` là singleton trong
  worker => range rò sang LẦN GỌI KẾ TIẾP => lead thứ 2+ trong cùng worker process đọc header bị
  nhầm range => ghi hụt (API vẫn báo 200, totalUpdatedCells=1 rỗng). Phát hiện qua test 2 lần gọi
  liên tiếp dưới filter. Fix: `Sheets::range('')` trong `finally` sau mỗi lần ghi.


### Changed
- **[Google Sheets][triệt để] `apps_google_sheet()` chuyển từ `append()` sang ghi tường minh `values.update`.**
  `append()` để Google tự dò "bảng" để chèn -> Filter/ẩn/sort trên tab đích làm dò sai ranh giới
  => lead bị mất hoặc đặt nhầm chỗ (vẫn trả 200). Nay: tự đọc dòng cuối thật của cột khóa (A) rồi
  `values.update` vào range tường minh `A{last+1}` (KHÔNG qua table-detection => MIỄN NHIỄM filter/ẩn),
  có `Cache::lock` per-spreadsheet chống ghi đua, và verify `totalUpdatedCells/Rows>=1` chống báo
  thành công giả. Helper mới: `apps_gsheet_order_row`, `apps_gsheet_next_row_index`,
  `apps_gsheet_update_succeeded`. Test: `tests/Unit/AppsGsheetWriteTest.php`.
  Đã verify A/B trên sheet nháp (filter bật): values.update PERSIST.


### Fixed
- **[Bug][Google Sheets] `apps_google_sheet()` mất dòng khi tab đích bị Filter/ẩn.**
  Trước đây `append()` dùng `insertDataOption` mặc định = `OVERWRITE`. Khi worksheet đích
  đang bật Filter (hoặc ẩn dòng), table-detection của Google bị lệch → dòng mới bị ghi
  đè/nuốt vào vùng ẩn và **mất hẳn**, dù API trả HTTP 200 (báo "synchronized" giả).
  Sửa: dùng `->append([$values], 'RAW', 'INSERT_ROWS')` (chèn dòng vật lý, không đè;
  sheet bình thường vẫn rơi đúng cuối bảng) + verify `updates.updatedRows >= 1` qua helper
  mới `apps_gsheet_append_succeeded()` → nếu Google không ghi dòng nào thì trả `error: true`
  thay vì báo thành công giả. File: `helpers/helpers.php`. Test: `tests/Unit/AppsGsheetAppendSucceededTest.php`.
