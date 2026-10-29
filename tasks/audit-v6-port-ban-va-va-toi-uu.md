# Audit dòng v6 — port bản vá từ v7.x & đề xuất tối ưu logic/hiệu năng

**Áp dụng cho:** `lte.6x`, `lte.6x-is_dev`, `lte.6x-is_platform`. Cùng một file này nằm ở cả 3 nhánh;
mục nào chỉ đúng với một số nhánh thì ghi rõ ở cột/ghi chú "Nhánh".

> Trạng thái:
> - **Code đã sửa đúng một việc:** port các bản vá **đã có sẵn trên v7.x** vào `helpers/helpers.php`
>   (mục 1). Không có thay đổi code nào khác.
> - **Không merge** nhánh nào: dòng v6 phục vụ nền tảng cũ (PHP 7.4, CMS ≤ 6.x) và có lịch sử git
>   riêng, không chung gốc với v7.x.
> - Mọi mục khác trong tài liệu là **đề xuất** (tham khảo); agent nhận việc tự quyết làm gì, theo thứ
>   tự nào, và có quyền bác bỏ.
> - Riêng **quy trình ở mục "BẮT BUỘC" dưới đây là bắt buộc**, không có ngoại lệ.

## ⛔ BẮT BUỘC với agent thực thi — đọc và tuân thủ trước khi đụng vào code

**Tài liệu này có thể sai.**
- Audit tương tự trên v7.x từng sai 4 nhận định.
- Code có thể đã đổi sau khi tài liệu được viết.
- Mọi số liệu hiệu năng ở đây là số đo trên **v7.x** hoặc suy ra từ code, **chưa từng đo trên nền
  tảng v6**.

**KHÔNG được tin tuyệt đối vào bất kỳ câu nào ở đây**, kể cả mục đánh dấu ✅. Dấu ✅ chỉ nghĩa là
"người viết đã đọc code / chạy kiểm tra tại thời điểm viết".

Với **mỗi** mục định xử lý, agent thực thi **bắt buộc** làm đủ 7 bước sau. Thiếu bước nào thì
không được sửa mục đó:

1. **Tự kiểm chứng tại nguồn.**
   - Mở đúng file/hàm trên code **hiện tại** của nhánh làm việc và đọc tận nơi.
   - Kiểm cả tiền đề ngoài repo: phiên bản PHP, Laravel, gói `revolution/laravel-google-sheets`
     **thật sự cài trên từng server** (`composer show`), driver cache/session đang dùng,
     `config:cache` có bật không.
   - Không khớp với tài liệu ⇒ ghi lại là tài liệu sai, **không** làm theo.
2. **Tôn trọng ràng buộc nền cũ.**
   - Code cho v6 phải chạy được trên **PHP 7.4**: không union type, `mixed`, nullsafe `?->`,
     `match`, named argument, `catch` bỏ biến, `str_contains`…
   - Không dùng API chỉ có ở Laravel/gói bản mới.
   - Tuyệt đối không merge v7 ↔ v6.
3. **Tự đo trước và sau.**
   - Lấy số đo nền **trước khi sửa** và đo lại **sau khi sửa** bằng đúng cách đó.
   - Số liệu trích từ v7.x chỉ là giả thuyết cho v6. Cấm dùng chúng làm kết quả.
4. **Tự phản biện từng giải pháp** (thành văn bản):
   - (a) Khi nào giải pháp sai hoặc gây hại?
   - (b) Có cách rẻ hơn không?
   - (c) Ảnh hưởng tới **mọi nơi gọi**? Gồm cả các app nền cũ đang dùng gói.
   - (d) Có chạm vùng code mang cảnh báo **"ĐỪNG SỬA"** hoặc quyết định đã chốt của chủ sản phẩm
     không? Có chạm ⇒ dừng, hỏi người dùng.
5. **Tái hiện trước, sửa sau.**
   - Với bug: có test hoặc kịch bản thấy nó **thất bại** trước khi sửa, rồi **qua** sau khi sửa.
   - Với tối ưu: chứng minh kết quả đầu ra **không đổi**.
6. **Ghi bằng chứng.** Commit phải ghi:
   - đã kiểm chứng gì;
   - số đo trước/sau;
   - chỗ nào tài liệu sai;
   - mục nào bỏ qua và vì sao.
7. **Dừng khi không kiểm chứng được.** Thiếu quyền vào server, không biết phiên bản đang chạy,
   không có môi trường thử… ⇒ dừng ở mục đó và báo người dùng. **Không suy diễn để làm tiếp.**

---

## Cập nhật 29/10/2026 — các lỗi đã xác định chắc chắn ĐÃ SỬA

Người dùng cho phép sửa mọi lỗi **xác định chắc chắn** trên mọi phiên bản; lỗi chưa tường minh để agent
của app tích hợp tự xử lý.

**Đã sửa trên cả 3 nhánh v6 (không merge):**
- spread mảng key chuỗi → `array_merge` (mục 2.1, có thể gây ghi trùng dòng trên PHP 7.4/8.0);
- gỡ nhóm route `api/v1/test/*` (mục 2.2) và `GET /test` gửi Telegram (mục 2.3);
- `update_by_log` dọn range;
- `apps_phone_convert` bỏ dấu chấm;
- notification sinh nhật: thiếu email, `dd()`;
- `whereCaseInsensitive` dùng `mb_strtolower`;
- `env()` lúc chạy → `apps_env()`.

Cùng các lỗi tương ứng cũng đã sửa trên v7.x.

**Kiểm thử còn treo** (PHP 7.4 thật, ghi Sheet thật, đo hiệu năng trên nền v6) và **danh sách lỗi chưa
sửa**: `tasks/kiem-thu-sau-trien-khai.md`.

## 0. Bối cảnh 3 nhánh v6

| Nhánh | Namespace | Khác biệt |
|---|---|---|
| `lte.6x` | `Dev\Kernel` | Bản đầy đủ: thêm bộ import địa giới (`Location*`, model `District`/`Ward`), `SettingSeeder`, API test gói cước |
| `lte.6x-is_dev` | `Dev\Kernel` | **Nội dung giống hệt `lte.6x`** (so toàn bộ cây file ✅) |
| `lte.6x-is_platform` | `Platform\Kernel` | Không có bộ import địa giới, `SettingSeeder`; API controller chỉ còn `test()` rỗng |

- `helpers/helpers.php` **giống hệt nhau ở cả 3 nhánh** (✅ so byte trước khi sửa).
- Lịch sử git của v6 **không có tổ tiên chung với v7.x**. Commit gốc: *"php 7.4 lte6x: cho các bản cms
  cũ <=6x"*. Code v6 đã được hạ cú pháp cho PHP 7.4 (không còn union type, `mixed`, constructor
  promotion; ✅ quét toàn bộ, chỉ còn trong docblock).
- `helpers.php` của v6 đã lệch v7.x khoảng 1.400 dòng ngay từ 07/04/2026 ⇒ không cherry-pick được,
  phải port tay từng bản vá.
- Không có thư mục `tests/`, không có `CHANGELOG.md`.

---

## 1. Bản vá ĐÃ PORT từ v7.x (đã sửa code — chỉ `helpers/helpers.php`)

| v7.x commit | Nội dung | Điều chỉnh riêng cho v6 |
|---|---|---|
| `89790f6` | `Log::channel($logger)` → `apps_log($logger)` (40 chỗ). Kênh log chưa khai báo không còn làm mất log (trên v7 đo ở một project ngày 21/08/2026: 1.316 dòng `EMERGENCY`). | Không có. 40 chỗ, đều nằm ngoài vùng định nghĩa `apps_log*`. |
| `34edf15` | Cache token OAuth2 (kèm `expires_in`/`created`) theo `sha1(refresh_token)`. Hết cảnh refresh token ở **mỗi** lượt ghi, khiến Google chặn ở tầng TLS. | Không có. Đã xác nhận tiền đề trên gói 5.8.1: `setAccessToken()` tự refresh khi token thiếu hạn dùng. |
| `0074a20`, `2ea8dff` | `apps_gsheet_header_row()`: chỉ đọc **dòng 1** thay vì tải cả tab; trần riêng 8s cho lượt đọc, trả lại HTTP client nguyên trạng. | Docblock ghi đúng: gói 5.x/6.x bind `Sheets` bằng `singleton()`, không phải `scoped()`. |
| `417457d`, `a7f8191` | Đọc header tươi là đường chính. Cache 300s + "header tốt gần nhất" (LKG) 7 ngày làm lưới đỡ, có log. Chặn lượt ghi chắc chắn hỏng (không có header) bằng lỗi nhìn thấy được, thay vì mất lead im lặng. | Không có. |
| `13058ea` | Sắp cột theo header **trước** `append()`, để gói không tải toàn bộ tab ở mỗi lượt ghi. | **Công thức ô trống dùng `is_null()` thay vì `empty()`** — xem 1.1. |
| `eccf4b9` | Neo `append()` vào `'Tab'!A:A`, chữa lỗi "cột nhảy" khi có dòng trống giữa dữ liệu. Dọn range trước/sau khi ghi; log lỗi nếu dòng ghi không bắt đầu ở cột A. | **Thêm `->range('')` trước khi đọc tên tab ở đường đọc header** — xem 1.1. |

**Hàm mới thêm:** `apps_gsheet_header_row`, `apps_gsheet_append_range`,
`apps_gsheet_range_starts_at_column_a`. Chữ ký hàm `apps_google_sheet()` giữ nguyên;
`$cache_force_refresh` không còn tác dụng (giống v7.x).

### 1.1 Hai điều chỉnh so với bản v7.x — lý do đã kiểm chứng ✅

Đọc mã nguồn gói `revolution/laravel-google-sheets` các bản **5.5.2, 5.8.1, 6.2.1** (bản phù hợp
PHP 7.4/8.0), so với bản mới nhất mà v7.x dùng:

1. **`orderAppendables()` của gói cũ coi ô là trống khi `is_null($value) || $key === $value`**,
   còn gói mới dùng `empty($value)`.
   - Port nguyên văn `empty()` từ v7.x sẽ khiến ô có giá trị `"0"` bị ghi thành ô trống ⇒ **lệch
     hành vi** so với trước khi vá.
   - Bản port dùng `is_null()`, cho kết quả trùng khít với gói cũ (kiểm bằng 6 bộ dữ liệu ở mục 1.2).
2. **Gói 5.x/6.x bind `Sheets` bằng `singleton()`** (✅ `SheetsServiceProvider` của cả 3 bản), nên
   `$range` "dính" sống qua **nhiều job** trong cùng worker.
   - `apps_google_sheet_update_by_log()` đặt range mà không dọn.
   - Nếu không dọn, lượt đọc header kế tiếp sẽ đọc theo range rò rỉ, lỗi, rồi rơi xuống lưới đỡ.
   - Bản port thêm `->range('')` trước `ranges()` ở đường đọc header. Đây là cùng nguyên tắc
     "luôn dọn trước khi dùng" của chính commit `eccf4b9`.
   - Ghi chú cho v7.x: v7.x có cùng chỗ này. Tác động nhỏ hơn nếu gói mới bind `scoped()` như
     comment trong code nói, nhưng **nên kiểm**.

### 1.2 Bằng chứng kiểm chứng

- **Tái hiện trước khi sửa:** script kiểm tra chạy trên `helpers.php` gốc của v6 ⇒ `Fatal error: Call
  to undefined function apps_gsheet_append_range()` (chưa có bản vá).
- **Sau khi sửa: 19/19 kiểm tra qua, trên cả 3 nhánh.**
  - Toàn bộ ca của `tests/Unit/AppsGsheetAppendRangeTest.php` (v7.x).
  - So công thức sắp cột mới với công thức `orderAppendables()` của gói 5.8.1 trên 6 bộ dữ liệu:
    đủ cột, sai thứ tự, ô `"0"`, key không khớp header, giá trị trùng chỉ số, `null`.
  - Một ca chứng minh công thức `empty()` của v7.x khác gói cũ.
- `php -l` sạch (PHP 8.3).
- **Tương thích PHP 7.4:** soát mọi dòng mới thêm để tìm cú pháp chỉ có từ PHP 8 ⇒ không có. Chưa
  chạy được trên PHP 7.4 thật vì máy kiểm tra chỉ có PHP 8.3, và công cụ PHPCompatibility không được
  phép tải về.
- `cache_force_refresh`, nhánh `$with_keys = false`, nhánh service account: hành vi không đổi.

### 1.3 CHƯA kiểm chứng được — agent triển khai phải tự làm

- Chưa chạy với Google Sheets thật trên nền v6. Trước khi phát hành, ghi thử vào một sheet nháp có
  **dòng trống giữa dữ liệu** để xác nhận dòng mới bắt đầu ở cột A.
- Chưa đo hiệu năng trên nền v6. Các con số 41.000 dòng / 24 MB / 1.316 dòng log là của v7.x.
- Chưa biết từng server v6 dùng gói Sheets bản nào, Laravel bản nào. Phải `composer show` trên từng
  server trước khi `composer update`.
- `config('google.client_id')`/`client_secret` phải có trên server. Thiếu thì bản vá OAuth tự lùi về
  hành vi cũ (không tệ hơn), nhưng sẽ không có lợi ích.
- Sau khi triển khai, theo dõi 3 dòng log mới:
  - `[GSheet] Đọc header tươi THẤT BẠI`
  - `[GSheet] BỎ QUA lượt ghi`
  - `[GSheet] Dòng vừa ghi KHÔNG bắt đầu ở cột A`

### 1.4 Bản vá/thay đổi của v7.x KHÔNG port — và vì sao

| Thay đổi trên v7.x | Lý do không port |
|---|---|
| Thêm kiểu PHP 8 (`mixed`, union type, `catch` bỏ biến…) | Phá PHP 7.4 |
| Telegram: `TELEGRAM_NOTIFY_ENABLE`, `message_thread_id`, token theo bot | Tính năng mới, không phải bản vá |
| Viết lại `apps_phone_extraction` | Đổi định dạng đầu ra (v6 trả chuỗi chỉ có số, v7 trả nguyên chuỗi khớp); không có ghi nhận là bản vá |
| Nhóm `apps_cache_*` (`isAppliedKey`, gỡ key khỏi mọi nhóm khi flush) | Thay đổi thiết kế, thêm round-trip; cả nhóm cần thiết kế lại (xem 3.6) |
| Tìm file service account theo nhiều đường dẫn | Tính năng; dùng `str_starts_with` (PHP 8) |
| `LoadAndPublishDataTrait` hỗ trợ `dev-extensions/packages` | Tính năng; đổi cách dò đường dẫn trên nền cũ là rủi ro |
| Middleware bảo mật (`SecurityHeaders`, `TrustHosts`…), `GDriveMirrorSync`, Go CLI | Tính năng mới, v6 không có |

---

## 2. Lỗi nghiêm trọng riêng của v6 (CHƯA SỬA — chờ quyết định)

1. **Có thể ghi trùng dòng trên PHP 7.4/8.0 ✅ (đọc code)** — `apps_google_sheet()`, khối `return`
   thành công: `...(array) $result->toSimpleObject()`.
   - Cú pháp spread mảng có **key chuỗi** chỉ hợp lệ từ **PHP 8.1**. Trên 7.4/8.0 nó ném `Error:
     Cannot unpack array with string keys`.
   - Lỗi này xảy ra **sau khi dòng đã ghi vào Sheet**. Hàm rơi vào `catch` và trả `error: true` ⇒ nơi
     gọi tưởng thất bại ⇒ thử lại ⇒ **dòng trùng**.
   - Phải xác nhận PHP thật trên từng server (`php -v` của worker hàng đợi). Nếu < 8.1: thay bằng
     `array_merge([...], (array) $result->toSimpleObject())`.
   - Lỗi có sẵn từ trước, không phải bản vá của v7.x (v7.x chạy PHP ≥ 8.1 nên không bị), nên lượt này
     **không sửa**.
2. **Route test công khai ghi dữ liệu gói cước** — `routes/api.php` nhóm `api/v1/test`, không xác
   thực. **Nhánh: `lte.6x`, `lte.6x-is_dev` ✅.**
   - `GET plan/create-plan` tạo plan mới mỗi lần gọi.
   - `GET plan/add-features-plan` gắn thêm 6 feature vào **mọi** plan.
   - `GET plan/create-subscription` đăng ký member id 3.
   - `GET test/test` trả danh sách subscription sắp hết hạn.
   - `plan/details/{id}` khai báo 3 lần, chỉ lần cuối có hiệu lực.
   - `make-qrcode` gắn cứng URL của dự án khác rồi `exit`.
   - Trên `lte.6x-is_platform`: các route vẫn khai báo nhưng controller không có method ⇒ lỗi 500.
   - Gợi ý: gỡ cả nhóm `test` khỏi production, hoặc chỉ đăng ký khi môi trường local.
3. **`GET /test` (web) gửi tin Telegram mỗi lần truy cập**, không xác thực ✅. **Cả 3 nhánh.**
4. **Thông tin giống khoá API nằm trong metadata git** ✅ — trường author của commit gốc `6948d96`
   có chuỗi dạng `apikey:…`.
   - Không chép lại giá trị ở đây.
   - Gợi ý: coi là đã lộ ⇒ **thu hồi/xoay vòng khoá** ở hệ thống tương ứng.
   - Gỡ khỏi lịch sử git phải viết lại lịch sử nhánh dùng chung: việc lớn, cần người dùng quyết định.
5. **Bộ import địa giới** — chỉ `lte.6x`, `lte.6x-is_dev`; 🔎 agent báo, người viết đã xem các file
   liên quan:
   - Mỗi dòng Excel thành 1 job. `firstOrCreate` trên `districts`/`wards` không có index cho
     `code`/khoá ngoại ⇒ quét toàn bảng mỗi dòng, chi phí khoảng O(n²) theo số dòng.
     `WithBatchInserts` vô tác dụng vì `model()` không trả model nào.
   - Không có unique constraint ⇒ nhiều worker có thể tạo trùng tỉnh/huyện/xã.
   - `--truncate` xoá sạch 5 bảng (tắt FK) **trước** khi chắc có file để nạp lại ⇒ dữ liệu tham
     chiếu `city_id` có thể mồ côi.
   - Lỗi trong job bị nuốt; command luôn báo thành công.
   - XLSX nạp toàn bộ vào RAM. Gợi ý: `setReadDataOnly(true)`, hoặc để thư viện đọc theo chunk.
   - Cấu trúc 3 cấp (có huyện) có thể không khớp file dữ liệu sau sắp xếp đơn vị hành chính 2025
     (bỏ cấp huyện). Phải mở file thật để xác nhận; **không** bỏ bảng `districts` vội.
   - Migration chỉ chạy khi plugin location đã bật lúc migrate; `down()` không đối xứng.
   - ALTER bảng của plugin vendor/bảng core (`cities`, `states`, `media_files`) và tạo bảng không
     prefix `app_`: lệch quy ước nền tảng (sửa bảng vendor cần runbook đi kèm). **Không** đổi tên bảng
     đang có dữ liệu.
6. **`SettingSeeder`** — `lte.6x`, `lte.6x-is_dev`. Ghi đè `admin_email`, `locale`, ngôn ngữ mặc
   định bằng giá trị cứng. Chạy nhầm trên production sẽ đổi cấu hình site. Gỡ, hoặc chỉ cho chạy ở
   môi trường local.

---

## 3. Lỗi & cơ hội tối ưu CHUNG với v7.x (v7.x cũng chưa sửa ⇒ không thuộc diện "port")

Mô tả chi tiết và giải pháp cụ thể nằm trong `tasks/audit-he-thong-toi-uu-chuyen-sau.md` trên nhánh
`v7.x`. Dưới đây chỉ xác nhận chúng **cũng có ở v6** ✅ (cả 3 nhánh). Giải pháp port về v6 phải viết
lại cho PHP 7.4.

1. **Ghi lead vào Sheet: 4 request Google/lead.**
   - `sheetById()` gọi 2 lần, mỗi lần là 1 `spreadsheets.get` (✅ mã nguồn gói 5.8.1) + đọc header
     + append ⇒ 3 read + 1 write.
   - Giải pháp: cache `sheetId → tên tab`, hoặc `getByDataFilter`.
2. **Sinh nhật member không bao giờ gửi.**
   - So cả năm sinh: `startOfDay()->eq(today)`.
   - Gửi tới địa chỉ email cứng; `setVariableValues` truyền mảng danh sách.
   - Thiếu email ⇒ biến chưa định nghĩa; `dd()` trong `catch` của job hàng đợi.
3. **`apps_phone_convert` giữ dấu chấm** (`[^0-9.]`) ⇒ SĐT `0982.311.866` không được chuẩn hoá.
4. **Login CRM (5 hàm): không có timeout cURL** (0 chỗ `CURLOPT_TIMEOUT`), không cache token, tắt
   kiểm TLS, trả `error: false` trong nhánh lỗi. `apps_pull_login` gọi HTTP vòng về chính app.
5. **`env()` gọi lúc chạy:** sai khi `config:cache` (mức log luôn `debug`, URL login, prefix cache).
6. **Nhóm `apps_cache_*`:** đọc kèm ghi (nhiều round-trip mỗi lượt đọc), giới hạn FIFO 100 key/nhóm
   tự xoá dữ liệu còn hạn, không khoá. Gợi ý thiết kế "phiên bản theo nhóm" (versioned namespace).
7. **`FieldHelper::saveCustomFields`:** khoảng 12 câu lệnh + 12 commit mỗi lead. Gợi ý: transaction
   + đọc gộp.
8. **Session/cookie trên mọi route `api`:** v6 thêm `AddQueuedCookiesToResponse`, `StartSession`,
   `ShareErrorsFromSession`. Mỗi webhook tạo 1 session rác.
9. **App secret Facebook gắn cứng** trong `apps_facebook_parse_signed_request`. Nên xoay vòng secret.
10. **Địa giới hành chính lỗi thời** trong `apps_province_detection` (còn "Hà Tây", 63 tỉnh cũ).
    `whereCaseInsensitive` dùng `strtolower` (không xử lý chữ có dấu). `loadMigrations()` gọi 2 lần.
    JS inline 1 giây trong `HookServiceProvider`.

---

## 4. Đo lường & test cho v6

- v6 **không có test**.
  - Gợi ý tối thiểu: thêm `tests/` + `phpunit` bản hỗ trợ PHP 7.4 (9.x) cho các hàm thuần trong
    `helpers.php`.
  - Có thể bắt đầu bằng các ca đã dùng để kiểm bản port ở mục 1.2.
- Trước/sau mỗi tối ưu: đo thời gian từng bước ghi Sheet bằng `apps_log_write(..., 'start'|'step'|
  'success')` có sẵn; đếm request Google mỗi lead và số dòng log mỗi lead.

## 5. Gợi ý thứ tự (tham khảo — mỗi bước vẫn phải qua mục BẮT BUỘC)

1. **Triển khai bản port mục 1** lên từng nền tảng v6: xác minh phiên bản server → ghi thử sheet nháp
   → theo dõi 3 dòng log mới.
2. **Xác nhận PHP thật trên server** và xử lý mục 2.1 (trùng dòng) nếu < 8.1.
3. **Gỡ route test và `/test`** (mục 2.2, 2.3); **xoay vòng khoá** (mục 2.4) và secret Facebook
   (mục 3.9).
4. **Sửa bug chung rẻ:** sinh nhật, SĐT, timeout CRM, `env()`. Ưu tiên làm trên v7.x trước, rồi port
   về v6 với cú pháp PHP 7.4.
5. Chỉ khi bộ import địa giới còn được dùng: xử lý mục 2.5.

---
*Tài liệu do Claude tạo trong phiên audit theo yêu cầu người dùng. Code chỉ thay đổi ở mục 1 (port
bản vá đã có trên v7.x). Phần còn lại là đề xuất tham khảo; phần BẮT BUỘC là điều kiện bắt buộc với
mọi agent thực thi.*
