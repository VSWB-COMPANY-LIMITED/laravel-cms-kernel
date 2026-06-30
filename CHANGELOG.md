# Changelog — dev-extensions/kernel

## [Unreleased]

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
