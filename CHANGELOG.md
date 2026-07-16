# Changelog — dev-extensions/kernel

## [Unreleased]

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
