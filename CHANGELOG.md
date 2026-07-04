# Changelog — dev-extensions/kernel

## [Unreleased]

### Fixed
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
