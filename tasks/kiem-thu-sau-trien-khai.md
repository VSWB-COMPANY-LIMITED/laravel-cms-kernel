# Kiểm thử sau triển khai — dành cho agent của app dùng `dev-extensions/kernel` (v7.x)

**Đối tượng:** agent đang tích hợp hoặc nâng cấp thư viện này trong một app cụ thể. App có môi trường
thật, dữ liệu thật và bộ kiểm thử của riêng nó, nên kết quả kiểm ở đó chính xác hơn mọi kiểm tra trong
repo thư viện.

> **⛔ BẮT BUỘC:** áp dụng quy trình "BẮT BUỘC" ở đầu `tasks/audit-he-thong-toi-uu-chuyen-sau.md`:
> - tự kiểm chứng tại nguồn trên code hiện tại;
> - tự đo trước và sau;
> - tự phản biện từng giải pháp;
> - tái hiện trước, sửa sau;
> - ghi bằng chứng;
> - dừng khi không kiểm chứng được.
>
> **Không tin tuyệt đối tài liệu này**, kể cả phần "đã sửa": thư viện được sửa và kiểm trong môi trường
> không có app thật, không có Google/Drive/CRM thật.

## A. Kiểm thử các bản sửa ngày 29/10/2026

Mô tả chi tiết từng bản sửa nằm ở `CHANGELOG.md` (mục 29/10/2026).

| # | Bản sửa | Kiểm trong app thế nào |
|---|---|---|
| A1 | `apps_phone_convert` bỏ dấu chấm | Lead có SĐT `0982.311.866` ⇒ lưu `0982311866`. **Kiểm thêm:** dữ liệu cũ đã lưu kèm dấu chấm có làm so trùng lead theo SĐT lệch không; có cần chuẩn hoá lại dữ liệu cũ không |
| A2 | `apps_leadgen_prepare_data`: `mau_xe_ma_ban_quan_tam` | Lead có cả `model` và `mau_xe_ma_ban_quan_tam` khác nhau ⇒ trường này nay nhận giá trị chuẩn hoá (ưu tiên `model`). Kiểm các mapping CRM/Sheet đang đọc trường này có còn đúng ý nghiệp vụ |
| A3 | Notification sinh nhật: không có email thì bỏ qua; bỏ `dd()` | Member không có email: job không còn lỗi. Lỗi khác trong `toMail()`: job vào `failed_jobs` thay vì làm worker dừng |
| A4 | `whereCaseInsensitive` dùng `mb_strtolower` | Tìm `"ĐÀ NẴNG"` khớp `"đà nẵng"` |
| A5 | Gỡ `GET /test` (web) | Không còn route; kiểm không có thứ gì trong app trỏ tới `/test` |
| A6 | Dọn range của `Sheets` sau `apps_google_sheet_update_by_log` và trước khi đọc header | Trong cùng worker: gọi `update_by_log` rồi ghi lead mới ⇒ log **không** có `[GSheet] Đọc header tươi THẤT BẠI`, dòng ghi bắt đầu ở cột A |
| A7 | `apps_env()` thay `env()` lúc chạy | Bật `php artisan config:cache` rồi kiểm: log level đúng `APP_LOG_LEVEL`; `apps_pull_login` gọi đúng `APP_URL` (staging không gọi sang production); vtiger login có URL; Telegram đúng `chat_id`/`thread_id`/token. **Cần `config:clear` + `config:cache` lại sau khi cập nhật gói** để giá trị mới được chụp |
| A8 | `array_merge` thay spread `...` | Ghi 1 lead thành công ⇒ kết quả trả về vẫn có đủ `workbookId/sheetId/sheetName/updates`. App chạy PHP 8.0: trước đây lượt ghi thành công lại báo lỗi (có thể ghi trùng khi thử lại), nay hết |
| A9 | `GDriveMirrorSync::looksLikeDriveId` | Chạy với tên thư mục một cấp dài có dấu cách ⇒ được xử lý như đường dẫn, không như ID. `phpunit tests/Unit/GDriveDriveIdDetectionTest.php` |
| A10 | Go CLI: token `--incremental` lấy trước khi liệt kê | Với `--incremental`: sửa 1 file trên Drive **trong lúc** lần chạy đầu đang liệt kê ⇒ lần chạy sau phải tải file đó. `go test ./...` trong `tools/gdrive-mirror-cli` |
| A11 | Go CLI: `--path D:\x` (dấu cách) | Chạy với dạng có dấu cách ⇒ không còn bị coi là folder ID. Dùng binary mới trong `dist/`; `gdrive-mirror.exe` ở gốc thư mục tool **chưa** cập nhật, nên copy lại từ `dist/` nếu máy vận hành dùng bản đó |

**Chung:**
- Chạy toàn bộ test của app.
- Kiểm log 24 giờ đầu sau khi triển khai, theo dõi 3 dòng:
  - `[GSheet] Đọc header tươi THẤT BẠI`
  - `[GSheet] BỎ QUA lượt ghi`
  - `[GSheet] Dòng vừa ghi KHÔNG bắt đầu ở cột A`
- So số lead ghi vào Sheet với số lead nhận được trong cùng khoảng thời gian.

## B. Lỗi CHƯA sửa — chưa tường minh, agent tích hợp tự quyết sửa bằng bộ test của app

Thư viện **cố ý không sửa** các mục dưới đây. Lý do: sửa sẽ đổi hành vi theo cách phụ thuộc nghiệp vụ,
dữ liệu hoặc nơi gọi của từng app. Mỗi mục ghi điều cần làm rõ trước khi sửa.

1. **Email sinh nhật không bao giờ gửi được.**
   - `MemberBirthdayNotificationCommand` so **cả năm sinh** với hôm nay. Sửa thì tính năng **bắt đầu gửi
     email thật** cho member.
   - Cần nghiệp vụ xác nhận có muốn bật không. Ngày 29/02 năm không nhuận gửi khi nào? Có chống gửi trùng
     khi cron chạy 2 lần không?
2. **Notification sinh nhật gửi template tới địa chỉ cứng** `toan@visualweber.com` với `debug=true`, và
   `setVariableValues(['email', $email])` truyền mảng danh sách thay vì khoá-giá-trị. Có thể là ý đồ (gửi
   bản sao cho chủ dự án) hoặc là code thử để quên. Đối chiếu chữ ký `EmailHandler` trong vendor của app.
3. **5 hàm login CRM** (`apps_pull_login`, `apps_vtiger_login`, `apps_toyota_crm_login`,
   `apps_mmv_crm_login`, `apps_apollo_crm_login`):
   - không có timeout cURL (có thể treo worker vô hạn);
   - trả `"error" => false` ngay trong nhánh lỗi;
   - tắt kiểm TLS;
   - không cache token;
   - `apps_pull_login` gọi HTTP vòng về chính app.
   Cần biết: CRM đối tác chậm tối đa bao lâu (để chọn timeout), và **mọi nơi gọi** đang đọc field `error`
   theo nghĩa nào trước khi đổi.
4. **`FieldHelper::saveCustomFields`** truy cập `$data[$row['slug']]` không kiểm key.
   - Thiếu key ⇒ Laravel ném `ErrorException`.
   - Sửa bằng `?? ''` sẽ **ghi đè giá trị đang có thành rỗng** khi cập nhật một phần.
   - Cần biết nơi gọi có luôn truyền đủ 6 slug không, và thiếu key thì nên bỏ qua hay ghi rỗng.
5. **`GDriveMirrorSync`: item không có timestamp** (`$remoteTimestamp = 0`) ⇒ file Google-native không bao
   giờ được export lại, và mtime local bị đặt về 1970. Sửa đúng sẽ làm các file đó export lại ở mỗi lượt
   chạy. Cần đo xem có bao nhiêu item rơi vào trường hợp này.
6. **Prefix cache `apps_cache_*` dùng `env('APP_NAME')` lúc chạy** ⇒ khi `config:cache`, mọi app có prefix
   `app:`. Đổi sang `apps_env()` sẽ đổi toàn bộ khoá cache ⇒ cache trượt một lần khi triển khai. Chỉ đáng
   sửa nếu nhiều app dùng chung Redis mà `cache.prefix` trùng nhau.
7. **`GET /api/v1/test/middleware-check`** phản chiếu header (gồm `Authorization`), cookie, session **của
   chính người gọi**. Rủi ro: lộ cookie HttpOnly nếu trang có XSS. **`GET /api/v1/cms/plugins/get-plugins`**
   công khai danh sách plugin. Kiểm app có công cụ nào đang dùng hai endpoint này không, rồi quyết định gỡ
   hay giới hạn môi trường.
8. **Thiết kế `apps_cache_*`** (đọc kèm ghi, FIFO 100 key tự xoá dữ liệu còn hạn), **4 request Google cho
   mỗi lead**, **session trên route `api`**: tối ưu hiệu năng, cần đo trong app. Xem
   `tasks/audit-he-thong-toi-uu-chuyen-sau.md` mục 2.
9. **Dữ liệu địa giới hành chính** (`apps_province_detection`, 63 tỉnh cũ, còn "Hà Tây"). Phụ thuộc CRM đại
   lý đang dùng tên cũ hay mới.
10. **Test chết:** `tests/Unit/AppsGsheetWriteTest.php`, `AppsGsheetAppendSucceededTest.php` gọi 5 hàm
    không còn tồn tại ⇒ `phpunit` fatal. Viết lại theo hàm hiện có hoặc xoá.
11. **Go CLI:** lỗi hết quota ngày (`dailyLimitExceeded`) vẫn retry từng file, có thể chờ hàng giờ. Cần chọn
    ngưỡng dừng.
12. **App secret Facebook gắn cứng** trong `apps_facebook_parse_signed_request`.
    - Chuyển sang config/env cần mỗi app khai báo giá trị.
    - Secret đã nằm trong lịch sử git ⇒ nên **xoay vòng secret** ở phía Facebook rồi cấu hình giá trị mới.
13. **`TrustProxies::$proxies = '*'`** kết hợp rate limit theo IP ⇒ client tự đặt `X-Forwarded-For` giả để
    né giới hạn. Cần biết dải IP proxy thật của hạ tầng từng app.

## C. Đo hiệu năng (số đo nền trước khi tối ưu)

Đo trên app thật, trước và sau mỗi thay đổi:
- Thời gian ghi 1 lead vào Sheet (p50/p95) và từng bước. Dùng
  `apps_log_write(..., 'start'|'step'|'success')` có sẵn.
- Số request Google mỗi lead; số lead/phút trước khi bị 429.
- Số query và thời gian DB mỗi job lead (`DB::listen` tạm thời hoặc slow query log).
- Số dòng log mỗi lead; dung lượng log mỗi ngày.
- Thời gian một lượt mirror Drive "không có gì đổi" (summary cuối run có `duration`).

---
*Tài liệu do Claude tạo ngày 29/10/2026 khi sửa các lỗi đã xác định chắc chắn. Phần B là danh sách việc
cho agent tích hợp tự đánh giá; không phải chỉ thị cách sửa.*
