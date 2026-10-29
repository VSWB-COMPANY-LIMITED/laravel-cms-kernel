# Kiểm thử sau triển khai — dành cho agent của app dùng `dev-extensions/kernel` dòng v6

**Áp dụng cho:** `lte.6x`, `lte.6x-is_dev`, `lte.6x-is_platform` (nền tảng cũ: PHP 7.4, CMS ≤ 6.x).
Cùng một file nằm ở cả 3 nhánh. Mục nào chỉ đúng với một số nhánh thì ghi rõ.

**Đối tượng:** agent đang tích hợp hoặc nâng cấp thư viện này trong một app nền cũ. App có môi trường
thật (đúng phiên bản PHP/Laravel/gói), dữ liệu thật và bộ kiểm thử riêng, nên kết quả kiểm ở đó chính
xác hơn mọi kiểm tra trong repo thư viện.

> **⛔ BẮT BUỘC:** áp dụng quy trình "BẮT BUỘC" ở đầu `tasks/audit-v6-port-ban-va-va-toi-uu.md`:
> - tự kiểm chứng;
> - tôn trọng PHP 7.4;
> - tự đo trước và sau;
> - tự phản biện;
> - tái hiện trước, sửa sau;
> - ghi bằng chứng;
> - dừng khi không kiểm chứng được.
>
> **Không merge v7 ↔ v6. Không tin tuyệt đối tài liệu này.**

## 0. Việc kiểm thử mà người sửa CHƯA làm được — agent tích hợp PHẢI làm

Thư viện được sửa và kiểm trong môi trường **chỉ có PHP 8.3**, không có app thật, không có
Google/Drive/CRM thật. Còn thiếu:

1. **Chạy trên PHP 7.4 thật.**
   - `php -v` của **worker hàng đợi** (không chỉ web) trên từng server.
   - Chạy luồng ghi lead vào Sheet và luồng gửi notification một lần trên PHP đó.
   - Code mới đã được soát tay để không dùng cú pháp chỉ có từ PHP 8, nhưng **chưa được thực thi trên
     7.4**. Nếu có công cụ (PHPCompatibility, `testVersion 7.4`), chạy trên `helpers/helpers.php`.
2. **Ghi thử vào Google Sheet thật.**
   - Dùng một sheet nháp có **dòng trống nằm giữa dữ liệu**.
   - Ghi 3–5 lead ⇒ mọi dòng phải bắt đầu ở **cột A**, đúng thứ tự cột.
   - Lặp lại sau khi gọi `apps_google_sheet_update_by_log()` trong **cùng worker**.
   - Kiểm ô có giá trị `"0"` vẫn được ghi là `"0"`.
3. **Đo hiệu năng trên nền v6.**
   - Thời gian ghi 1 lead (p50/p95) trước và sau khi cập nhật gói.
   - Số request Google mỗi lead; số lead/phút trước khi bị 429.
   - Các con số trong tài liệu (41.000 dòng / 24 MB / 1.316 dòng log) là của v7.x, không phải của v6.
4. **Phiên bản thật trên server:** `composer show revolution/laravel-google-sheets`, Laravel, cache
   driver; `config:cache` có bật không.
5. Sau khi cập nhật gói: **`php artisan config:clear && php artisan config:cache`** (nếu dùng cache
   config), để các giá trị `env` mới được chụp.

## A. Kiểm thử từng bản sửa

| # | Bản sửa | Kiểm trong app thế nào |
|---|---|---|
| A1 | Port bản vá ghi Google Sheet từ v7.x (đọc dòng 1, header dự phòng, sắp cột, chặn ghi mù, neo cột A, cache token OAuth, `apps_log`) | Xem mục 0.2. Theo dõi log 24 giờ đầu, 3 dòng: `[GSheet] Đọc header tươi THẤT BẠI`, `[GSheet] BỎ QUA lượt ghi`, `[GSheet] Dòng vừa ghi KHÔNG bắt đầu ở cột A`. So số lead nhận được với số dòng ghi vào Sheet |
| A2 | `array_merge` thay spread `...` (kết quả ghi Sheet, tuỳ chọn Telegram) | **Quan trọng nhất với PHP 7.4/8.0:** trước đây mỗi lượt ghi **thành công** lại báo lỗi, có thể khiến nơi gọi thử lại và **ghi trùng dòng**. Kiểm: ghi 1 lead ⇒ kết quả `error: false`, có đủ `workbookId/sheetId/sheetName/updates`; không có dòng trùng. Nếu app từng ghi trùng, cân nhắc dọn dữ liệu cũ |
| A3 | `apps_google_sheet_update_by_log()` dọn range trong `finally` | Gọi `update_by_log` rồi ghi lead mới trong cùng worker ⇒ không có cảnh báo đọc header, dòng bắt đầu ở cột A |
| A4 | `apps_phone_convert` bỏ dấu chấm | `0982.311.866` ⇒ `0982311866`. Kiểm dữ liệu cũ có dấu chấm có làm so trùng theo SĐT lệch không |
| A5 | Notification sinh nhật: không có email thì bỏ qua; bỏ `dd()` | Member không có email: job không lỗi. Lỗi khác: job vào `failed_jobs`, worker không dừng |
| A6 | `whereCaseInsensitive` dùng `mb_strtolower` | `"ĐÀ NẴNG"` khớp `"đà nẵng"` |
| A7 | `apps_env()` thay `env()` lúc chạy (`APP_LOG_LEVEL`, `APP_URL`, `VTIGER_REDIRECT`) | Bật `config:cache` ⇒ log level đúng; `apps_pull_login` gọi đúng `APP_URL` (staging không gọi sang production); vtiger login có URL |
| A8 | Gỡ nhóm route `api/v1/test/*` (tạo plan, gắn feature vào mọi plan, đăng ký member, QR code…) | `lte.6x`/`is_dev`: các route này từng ghi dữ liệu gói cước qua GET không xác thực. `is_platform`: từng lỗi 500. Kiểm không có công cụ nội bộ nào gọi chúng. **Kiểm dữ liệu gói cước** có plan rác (tên kiểu `123123 123123`) hoặc feature bị nhân bản do ai đó đã gọi trước đây |
| A9 | Gỡ `GET /test` (web) | Route này gửi tin Telegram mỗi lần có người truy cập. Kiểm không còn ai dùng |

## B. Lỗi CHƯA sửa — chưa tường minh, agent tích hợp tự quyết sửa bằng bộ test của app

Mỗi mục ghi điều cần làm rõ trước khi sửa. Mô tả chi tiết ở `tasks/audit-v6-port-ban-va-va-toi-uu.md`.

1. **Email sinh nhật không bao giờ gửi được** (so cả năm sinh). Sửa thì **bắt đầu gửi email thật**; cần
   nghiệp vụ xác nhận. Kèm:
   - địa chỉ email gắn cứng `toan@visualweber.com` với `debug=true`;
   - `setVariableValues(['email', $email])` truyền mảng danh sách.
2. **5 hàm login CRM:**
   - không timeout cURL;
   - `"error" => false` trong nhánh lỗi;
   - tắt kiểm TLS;
   - không cache token;
   - `apps_pull_login` gọi vòng về chính app.
   Cần biết timeout chấp nhận được và cách mọi nơi gọi đọc field `error`.
3. **`FieldHelper::saveCustomFields`:** thiếu key thì ném lỗi. Sửa bằng `?? ''` có thể ghi đè giá trị đang
   có thành rỗng.
4. **Prefix cache `apps_cache_*` dùng `env('APP_NAME')`:** đổi sẽ làm toàn bộ cache trượt một lần.
5. **Bộ import địa giới** (`lte.6x`, `is_dev`):
   - mỗi dòng 1 job; `firstOrCreate` không index;
   - race tạo trùng;
   - `--truncate` xoá trước khi chắc có file;
   - lỗi bị nuốt;
   - XLSX nạp toàn bộ vào RAM;
   - cấu trúc 3 cấp so với dữ liệu sau sắp xếp đơn vị hành chính 2025.
   Chỉ xử lý nếu app còn dùng.
6. **`SettingSeeder`** (`lte.6x`, `is_dev`) ghi đè cấu hình site nếu chạy nhầm trên production.
7. **App secret Facebook gắn cứng**; **chuỗi dạng khoá API trong metadata git** (commit `6948d96`) ⇒ nên
   xoay vòng khoá/secret ở hệ thống tương ứng.
8. **Session trên mọi route `api`**, **4 request Google mỗi lead**, **thiết kế `apps_cache_*`**, **dữ
   liệu địa giới lỗi thời**: tối ưu/nghiệp vụ, cần đo trong app.
9. **`gdrive-mirror`/`GDriveMirrorSync` không có ở v6**: không áp dụng.

---
*Tài liệu do Claude tạo ngày 29/10/2026 khi sửa các lỗi đã xác định chắc chắn trên dòng v6. Phần B là
danh sách việc cho agent tích hợp tự đánh giá; không phải chỉ thị cách sửa.*
