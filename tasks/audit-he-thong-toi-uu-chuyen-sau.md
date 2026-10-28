# Audit chuyên sâu — giải pháp tối ưu logic & hiệu năng (lượt 2)

> Trạng thái: **AUDIT — chưa sửa code.** Đây là **tài liệu hỗ trợ**, không phải chỉ thị. Mọi mức ưu
> tiên, ước lượng và giải pháp dưới đây là gợi ý tham khảo; agent nhận việc tự đo lại (log thời gian,
> đếm request, EXPLAIN, profiler) trước khi quyết định làm gì, theo thứ tự nào, và có quyền bác bỏ
> nếu số liệu thật không khớp.
>
> Bổ sung cho `tasks/audit-he-thong-toi-uu.md` (lượt 1). Lượt 2 khác lượt 1 ở 3 điểm: (1) **tự đọc
> lại code để kiểm chứng** từng phát hiện, đính chính chỗ sai của lượt 1 (mục 1); (2) đọc thêm
> phần lượt 1 bỏ ngỏ: `src/Commands/GDriveMirrorSync.php`, các provider/middleware chạy ở mỗi
> request, luồng sinh nhật member; (3) mỗi vấn đề kèm **giải pháp cụ thể, ước lượng lợi ích, rủi ro**.

**Code tham chiếu:** nhánh `v7.x`, gồm bản vá cột nhảy `eccf4b9`. Số dòng có thể lệch vài dòng khi
code đổi.

**Ký hiệu độ tin cậy:**
- ✅ = đã tự đọc code và xác nhận.
- 🔎 = do agent đọc song song báo cáo; đã xem qua nhưng chưa tự kiểm từng dòng. Nên xác nhận lại
  trước khi làm.
- Mọi con số "ước lượng" là **suy ra từ code**, chưa đo trên môi trường thật.

---

## 0. Tóm tắt: các việc đáng làm nhất

| # | Hạng mục | Loại | Lợi ích | Công sức | Rủi ro | Mục |
|---|---|---|---|---|---|---|
| 1 | Ghi lead vào Sheet: 4 request Google/lead → 2 | Hiệu năng + quota | Cao | Thấp–TB | Thấp | 2.1 |
| 2 | Bỏ session/cookie khỏi nhóm route `api` (webhook) | Hiệu năng | Cao | Thấp | TB | 2.4 |
| 3 | Login CRM: thêm timeout + cache token + bỏ HTTP gọi vòng về chính mình | Ổn định | Cao | TB | TB | 2.3 |
| 4 | `env()` lúc chạy → sai khi `config:cache` (log level, URL, prefix cache) | Logic | Cao | Thấp | Thấp | 2.2 |
| 5 | Sinh nhật member **không bao giờ gửi** + gửi nhầm địa chỉ cứng | Bug | Cao | Thấp | Thấp | 3.1 |
| 6 | `apps_phone_convert` giữ dấu chấm → SĐT `0982.311.866` không chuẩn hoá | Bug | Cao | Thấp | Thấp | 3.2 |
| 7 | Cache helper: 1 lượt đọc = 5–6 round-trip, tự xoá dữ liệu còn sống | Hiệu năng | TB–Cao | TB | TB | 2.5 |
| 8 | GDrive PHP: `files.get` thừa cho mọi file + hash lại toàn cây mỗi run | Hiệu năng | Cao | Thấp | Thấp | 4.1 |
| 9 | GDrive PHP ↔ Go trùng chức năng → PHP làm wrapper gọi binary Go | Kiến trúc | Cao | TB–Cao | TB | 4.1 |
| 10 | Go CLI: token incremental lấy SAU khi list → bỏ sót thay đổi | Bug | TB | Thấp | Thấp | 4.2 |
| 11 | Go CLI: hết quota ngày vẫn retry từng file (có thể chờ hàng giờ) | Hiệu năng | TB–Cao | Thấp | Thấp | 4.2 |
| 12 | Endpoint debug công khai trả cả header `Authorization`, cookie, session | Bảo mật | Cao | Thấp | Thấp | 5 |

Trước khi tối ưu: nên có số đo nền và hạ tầng test (mục 6). Không có hai thứ này thì không biết
tối ưu có hiệu quả thật hay không, và cũng không dám sửa những hàm đang chạy production.

---

## 1. Đính chính so với lượt 1 (`audit-he-thong-toi-uu.md`)

Kiểm chứng lại bằng cách tự đọc code. Các điểm sau trong lượt 1 **sai hoặc thiếu**:

1. **"Mảng tỉnh/huyện cỡ lớn khởi tạo lại mỗi lần gọi" (lượt 1, mục 14) — sai.**
   `apps_get_provinces_districts_rawdata()` thực tế chỉ có **4 tỉnh** (Tiền Giang, Trà Vinh, Vĩnh
   Long, Kiên Giang), chi phí không đáng kể. Vấn đề thật là **dữ liệu thiếu và lỗi thời** (mục 3.3).
   "Key Bạc Liêu khai báo trùng" cũng sai: 2 dòng đó thuộc 2 mảng của 2 hàm khác nhau.
2. **"Migration sample tạo bảng `licenses`" (lượt 1, mục 25) — sai file.**
   Bảng `licenses` được tạo ở `database/migrations/2026_03_05_000000_create_license_monitoring_tables.php`.
   File `2023_05_21_194154_sample.php` thì rỗng, không tạo bảng nào.
3. **"`notes`/`description` gán 2 lần là thừa" — sai.**
   Trong `apps_leadgen_prepare_data()`, lần gán đầu (từ `nhu_cau`, ~dòng 3194) là **nguồn dự phòng**
   cho lần gán sau (~dòng 3247), vì danh sách ưu tiên lần sau có `description`/`notes`. Đây là một
   chuỗi ưu tiên **ngầm** chứ không phải code thừa. Xem gợi ý ở mục 3.2.
4. **"Cron sinh nhật kiểm tra trùng điều kiện" (lượt 1, mục 15) — đánh giá thiếu.**
   Đây không phải trùng lặp mà là **bug khiến tính năng không bao giờ gửi** (mục 3.1).
5. **Lỗi "cột nhảy" Google Sheet:** gốc rễ thật là **dòng trống nằm giữa dữ liệu** làm Google dò sai
   bảng, đã sửa ở `eccf4b9` (neo `append()` vào `'Tab'!A:A`). Các giả thuyết trong
   `audit-gsheet-nhay-cot.md` (cache header, trùng tên cột) **không phải nguyên nhân của sự cố
   này**; chúng chỉ còn là rủi ro tiềm ẩn riêng.
6. **2 file test gsheet chết (lượt 1, mục 23) — vẫn đúng ✅.**
   `AppsGsheetWriteTest.php` và `AppsGsheetAppendSucceededTest.php` vẫn gọi 5 hàm không tồn tại;
   `tests/bootstrap.php` cũng tham chiếu `apps_gsheet_append_succeeded`. Test mới
   `AppsGsheetAppendRangeTest.php` thì hợp lệ.

---

## 2. Luồng nóng — chạy với mỗi lead / mỗi request

### 2.1 Ghi lead vào Google Sheet: 4 request Google mỗi lead, có thể giảm còn 2 ✅

**Hiện trạng** (`helpers/helpers.php::apps_google_sheet`), mỗi lead gọi:

| Bước | Dòng (~) | Request Google | Loại quota |
|---|---|---|---|
| `->sheetById()` để lấy tên tab cho lượt đọc header | 757–759 | `spreadsheets.get` | read |
| `apps_gsheet_header_row()` | 762 → 431 | `values.batchGet` (dòng 1) | read |
| `->sheetById()` **lần 2** trước khi ghi | 999–1001 | `spreadsheets.get` | read |
| `->append()` | 1011 | `values.append` | write |

- Cần xác nhận trong vendor của app (gói `revolution/laravel-google-sheets`) rằng `sheetById()`
  gọi `spreadsheets.get` để dựng danh sách tab. Theo mã nguồn gói đó, `sheetList()` gọi
  `spreadsheets->get()` không kèm `fields`.
- ⇒ **3 read + 1 write cho mỗi lead.** Comment trong code ghi trần là 60 read/phút/user, và từng
  dính "Quota exceeded … Read requests per minute per user". Với 3 read/lead, trần thực tế khoảng
  **20 lead/phút** cho mỗi tài khoản OAuth, trước khi bị 429. Comment ngày 28/09 tính 2 read/lead,
  tức bỏ sót lượt `sheetById()` thứ 2.

**Giải pháp (chọn 1, hoặc kết hợp):**

- **A — Cache ánh xạ `sheetId → tên tab` (đơn giản nhất).**
  - Tên tab gần như không đổi. Cache theo khoá `spreadsheetId:sheetId` với TTL dài (vd 1 ngày) và
    dùng cho cả lượt đọc header lẫn lượt ghi ⇒ **còn 1 read + 1 write/lead**, trần khoảng 60
    lead/phút (gấp 3).
  - Khi khách đổi tên tab: lượt đọc header với tên cũ sẽ lỗi `Unable to parse range` ⇒ xoá cache,
    gọi `spreadsheets.get` một lần, thử lại.
  - Nếu cần giữ `sheetById()`, gọi nó **một lần** và dùng chung kết quả cho cả 2 bước, thay vì gọi
    2 lần như hiện tại.
- **B — Gộp "lấy tên tab + đọc header" thành 1 request.**
  - Dùng `spreadsheets.getByDataFilter` với `dataFilters[].gridRange = {sheetId, startRowIndex: 0,
    endRowIndex: 1}`, `includeGridData=true`, và
    `fields=sheets(properties(sheetId,title),data(rowData(values(formattedValue))))`.
  - Một lượt trả cả tên tab lẫn hàng tiêu đề mà không cần biết tên trước.
  - Kết quả: 1 read + 1 write, luôn tươi, không cần cache. Đánh đổi là phải tự gọi Google service,
    không qua gói. Cách này cùng tinh thần với `apps_gsheet_header_row()` hiện tại.
  - Cần kiểm `formattedValue` cho ra giá trị giống `values.batchGet` mặc định (`FORMATTED_VALUE`).
- **C — Gom nhiều lead vào 1 lượt `append`** (chỉ khi đang có backfill hoặc burst).
  - `values.append` nhận nhiều dòng. Hàng đợi ở **app tiêu thụ** (pull.vn, không nằm trong kernel)
    có thể gom các lead cùng `spreadsheetId+sheetId` trong vài giây rồi ghi 1 lần ⇒ N lead tốn
    1 read + 1 write.
  - Đánh đổi: trễ vài giây; một lượt lỗi ảnh hưởng cả lô (cần đánh dấu từng lead). Nên giữ cách ghi
    từng lead làm mặc định, chỉ gom khi hàng đợi dài.

**Lợi ích ước lượng:** độ trễ ghi 1 lead giảm khoảng 40–50% (bớt 2 trên 4 round-trip mạng tới
Google); quota read giảm 3 lần. Đo trước/sau bằng thời gian từng bước (mục 6).

**Thêm (thấp):**
- `apps_build_mapped_values()` ghi log `info` 3 dòng cho **mỗi mục mapping** (dòng 298, 315, 319)
  và log toàn bộ mảng `$mappings` 2 lần (dòng 290 và 829), log `$values_mappings` 2 lần (dòng 360
  và 871). Sheet 15 cột × 2 form ≈ 90+ dòng log cho mỗi lead.
- Đề xuất: gộp thành 1 dòng log tóm tắt mỗi lead ở mức `info`, chi tiết từng mapping hạ xuống
  `debug`. Xem thêm 2.2 về việc mức `debug` hiện đang luôn bật.

### 2.2 `env()` gọi lúc chạy → hành vi sai khi production chạy `config:cache` ✅

Khi đã `php artisan config:cache`, Laravel **không nạp `.env` nữa**: `env('X')` gọi ngoài file
config sẽ trả `null` (chỉ còn đọc biến môi trường hệ thống). Các chỗ bị ảnh hưởng:

| Dòng (~) | Code | Hậu quả khi config cache |
|---|---|---|
| 1227 | `'level' => env('APP_LOG_LEVEL', 'debug')` | Mọi kênh log động **luôn ở mức debug** ⇒ ghi toàn bộ log `info`/`debug` (xem 2.1) |
| 1485 | `env('APP_URL', 'https://apis.pull.vn')` | Staging/dev gọi login **sang production** |
| 2350, 2390 | `Str::slug(env('APP_NAME', 'app'))` | Prefix cache thành `app` cho mọi app; chỉ còn dựa vào `cache.prefix` của Laravel để tách app |
| 610 | `setApplicationName(env('APP_NAME'))` | Tên app gửi Google rỗng (vô hại) |
| 1613, 2221, 2234–2235, 2254 | `VTIGER_REDIRECT`, `TELEGRAM_*` | Fallback về `config()` hoặc giá trị cứng. Riêng `chat_id` có giá trị mặc định gắn cứng (`-1001541977083`); nếu config thiếu, tin nhắn đi vào nhóm này |

**Giải pháp:**
- Đưa các khoá này vào `config/general.php` của kernel (vd `kernel.general.log_level`,
  `kernel.general.pull_api_url`), đọc bằng `config()` lúc chạy. Chỉ file config mới được gọi `env()`.
- Riêng prefix cache: bỏ lớp prefix tự chế, vì `cache.prefix` của Laravel đã tách app (xem 2.5).
- Rủi ro thấp. Lợi ích: log giảm mạnh, hết nguy cơ staging gọi nhầm production.

### 2.3 Login CRM (5 hàm `apps_*_login`) — treo worker, login lặp, tự gọi vòng ✅

Vấn đề đã xác nhận:

1. **Không có timeout.** Không hàm nào đặt `CURLOPT_TIMEOUT`/`CURLOPT_CONNECTTIMEOUT` (grep toàn
   file: 0 kết quả). Mặc định của cURL là **không giới hạn thời gian tổng** và chờ kết nối tới
   300 giây. CRM đối tác treo ⇒ worker hàng đợi treo theo.
2. **Login lại mỗi lần cần gọi CRM, không cache token.** Comment ở dòng 1435 tự ghi nhận hậu quả:
   *"xử lý giùm anh trường hợp gọi login nhiều quá, đơ máy chủ… khi đó giá trị trả ra bị null"*.
3. **`apps_pull_login()` gọi HTTP ngược về chính app** (`APP_URL/api/v1/login`). Mỗi lượt login
   chiếm **2 worker PHP-FPM** (1 đang chờ, 1 xử lý login). Khi tải cao, mọi worker có thể cùng chờ
   một request không còn worker nào nhận ⇒ treo dây chuyền, và vì không có timeout (ý 1) nên không
   tự gỡ. Đây là cơ chế rất có thể giải thích triệu chứng "đơ máy chủ" ghi ở dòng 1435.
4. `CURLOPT_SSL_VERIFYPEER = false` ở nhiều chỗ; trả `"error" => false` ngay trong nhánh lỗi
   (dòng 1473, 1496 và tương tự ở các hàm khác); nhánh `if ($info['http_code'] == 200) {}` rỗng.

**Giải pháp (theo thứ tự rủi ro tăng dần):**
- **a. Thêm timeout ngay** (vd connect 5s, tổng 20s), không đổi gì khác. Rủi ro gần như không.
- **b. Cache token theo bộ thông tin đăng nhập**, giống cách đã làm cho OAuth Google (dòng
  ~566–600): khoá `sha1(url+username)`, TTL = hạn token − biên an toàn. Bọc
  `Cache::lock(key)->block(5)` để khi token hết hạn chỉ **1** worker đi login, các worker khác chờ
  rồi dùng lại (chống "bầy đàn" login cùng lúc).
- **c. `apps_pull_login()`: xác thực trong cùng tiến trình** (gọi service/guard sinh token trực tiếp)
  thay vì HTTP vòng về chính mình. Nếu buộc phải gọi HTTP (khác server), dùng URL từ config (2.2)
  và có timeout.
- **d. Gộp 5 hàm vào 1 lớp client dùng chung**, chuyển sang facade `Http::` (có sẵn `timeout()`,
  `retry()`, `connectTimeout()`, và quan trọng là **`Http::fake()` để test được**, xem mục 6).
  Chỉ làm sau khi rà mọi nơi gọi đang dựa vào field `error` theo nghĩa nào.

### 2.4 Mỗi request API đều mở session + mã hoá cookie ✅

`KernelServiceProvider::register()` (dòng 65–100) đẩy vào nhóm `api` các middleware:
`EncryptCookies`, `AddQueuedCookiesToResponse`, `StartSession`, `ShareErrorsFromSession`.

- **Webhook** (Facebook leadgen, form…) không gửi cookie ⇒ **mỗi lượt gọi tạo một session mới**:
  1 lượt đọc + 1 lượt ghi kho session (file/DB/Redis), kèm `Set-Cookie` trả về.
- Với driver `file` hoặc `database`, kho session phình thêm 1 bản ghi rác cho mỗi webhook, chỉ được
  dọn theo xác suất GC.
- Ước lượng: 2 thao tác I/O thừa mỗi request API, cộng chi phí mã hoá cookie.
- Comment trong code cho biết lý do: một guard tuỳ biến cần session. Tức là chỉ **một số** route cần
  session, không phải tất cả.

**Giải pháp:**
- Tạo nhóm middleware riêng (vd `api.session`) chỉ cho các route cần guard có session; nhóm `api`
  để stateless.
- Rủi ro trung bình: cần liệt kê đúng route nào đang dựa vào session (đăng nhập bằng guard tuỳ
  biến) trước khi tách. Test bằng request thật trên staging.

**Các điểm liên quan:**
- `pushMiddlewareToGroup()` **thêm vào CUỐI** nhóm, nên comment "TrustHosts phải đầu tiên" không
  đúng với thực tế chạy. Cần xem `app/Http/Kernel.php` (hoặc `bootstrap/app.php`) của app: nếu
  `TrustProxies`/`TrimStrings` đã có ở middleware toàn cục thì đang **chạy 2 lần** mỗi request.
- **Rate limit** `200/phút theo user hoặc IP` (dòng 180) kết hợp với `TrustProxies::$proxies = '*'`:
  - client tự đặt `X-Forwarded-For` giả là **né được giới hạn**;
  - ngược lại, nếu app đứng sau proxy mà proxy không được tin, mọi client **dùng chung 1 IP** ⇒ 200
    request/phút cho cả hệ thống, burst webhook có thể bị 429 oan.
  - Giải pháp: khai báo dải IP proxy thật (Cloudflare/nginx) thay cho `*`; tách limiter riêng cho
    route webhook.
- **`HookServiceProvider`** chèn khoảng 80 dòng JS inline vào **mỗi trang admin**, chạy sau
  `setTimeout(1000)`, nên giao diện "giật" sau 1 giây. 3 khối JS gần như giống hệt nhau (chỉ khác
  selector).
  - Gợi ý: chuyển thành 1 file JS tĩnh (trình duyệt cache được), 1 hàm nhận selector, chạy ở
    `DOMContentLoaded`/`MutationObserver` thay vì chờ cứng 1 giây.
- Nhỏ: `loadMigrations()` gọi 2 lần (dòng 123, 126). `helpers` được autoload ở cả `register()`
  (dòng 107) lẫn `loadHelpers()` (dòng 127), cùng một thư mục.

### 2.5 Bộ hàm `apps_cache_*` — đọc kèm ghi, tự xoá dữ liệu còn sống ✅

Hiện trạng (`helpers.php` ~2333–2729):

- `apps_cache_get($key, $default, $group)` gọi `apps_cache_get_key()`. Hàm này **mỗi lần đọc**
  đều làm: `Cache::has(list)` + `Cache::get(list)` + `Cache::forever(list)` + `Cache::get(group)`
  (+ `Cache::forever(group)` nếu key mới) rồi mới `Cache::get(value)`
  ⇒ **5–6 round-trip cho 1 lượt đọc**, trong đó 1–2 là lượt **ghi**.
- Danh sách nhóm là read-modify-write **không khoá** ⇒ các worker chạy song song ghi đè danh sách
  của nhau, làm mất key.
- **Giới hạn 100 key/nhóm theo FIFO**: key thứ 101 khiến key cũ nhất **bị xoá khỏi cache dù còn
  hạn**. Nhóm nào chứa nhiều hơn 100 key (vd theo từng form, từng lead) sẽ liên tục tự xoá dữ liệu,
  tỉ lệ cache hit giảm mà không ai thấy.
- `apps_cache_flush($key)` (không truyền nhóm) **duyệt mọi nhóm** để gỡ key ⇒ O(số nhóm) round-trip.
- Các khoá quản lý (`md5("app_data_cache_list")`, `md5("group_x")`) **không có prefix riêng của
  app**, chỉ dựa vào `cache.prefix` của Laravel. Nhiều app dùng chung Redis mà trùng `cache.prefix`
  thì flush nhóm của app này sẽ xoá dữ liệu app kia.

**Giải pháp đề xuất — "phiên bản theo nhóm" (versioned namespace):**
- Khoá thật: `"{group}:v{N}:{key}"`, với `N = Cache::get("{group}:ver", 1)` (ghi nhớ trong request
  bằng biến `static`).
- **Đọc** = 1 round-trip (cộng 1 lượt lấy `ver` đầu request). **Ghi** = 1. **Flush cả nhóm** =
  `Cache::increment("{group}:ver")`: 1 lệnh, nguyên tử, không cần danh sách, không có race. Bản cũ
  tự hết hạn theo TTL.
- Bỏ hẳn danh sách nhóm và giới hạn 100 ⇒ hết tình trạng tự xoá dữ liệu còn sống.
- Điều kiện: mọi entry phải có TTL (không dùng `forever` cho dữ liệu trong nhóm), nếu không bản cũ
  nằm mãi trong bộ nhớ.
- Nếu driver là Redis, có thể dùng `Cache::tags()`. Nhưng versioned namespace chạy được trên **mọi**
  driver và có chi phí flush O(1).
- **Giữ nguyên chữ ký hàm** (`apps_cache_get/store/flush/reset`) để code gọi không phải đổi.
- Rủi ro trung bình: lần triển khai đầu, cache cũ coi như mất (đổi định dạng khoá), nên tránh làm
  vào giờ cao điểm.
- `apps_cache_reset()` cần cơ chế khác: giữ một tập tên nhóm nhỏ, hoặc tăng một "phiên bản toàn cục"
  ghép vào mọi khoá.

### 2.6 Lưu custom field cho lead (`src/Traits/FieldHelper.php`) ✅

- 6 field × (1 SELECT `getFirstBy` + 1 INSERT/UPDATE) = **12 câu lệnh** mỗi lead.
- Chạy ở chế độ autocommit ⇒ **12 lần commit**, mỗi lần InnoDB flush log.
- Chuỗi JSON cấu hình được `json_decode` lại mỗi lần gọi.
- `$data[$row['slug']]` truy cập trực tiếp, sẽ warning khi thiếu key (lệch quy ước dùng
  `Arr::get`/`data_get`).

**Giải pháp:**
- Bọc cả vòng lặp trong **`DB::transaction()`** ⇒ 12 commit còn 1. Rủi ro gần như không; lợi rõ
  nhất khi ổ đĩa DB chậm.
- **1 query** lấy hết bản ghi hiện có:
  `whereIn('field_item_id', [...])->where('use_for', …)->where('use_for_id', …)`, đưa vào map theo
  `field_item_id`. Field chưa có → INSERT (có thể gộp một lệnh `insert` nhiều dòng). Field đã có
  mà **giá trị không đổi → bỏ qua** (phần lớn các lượt lưu lại là không đổi).
  ⇒ khoảng 12 câu lệnh còn 2–3.
- Cấu hình field để thành hằng/`static` mảng PHP thay vì chuỗi JSON.
- **Không** thêm unique index vào bảng custom field: bảng thuộc plugin vendor, không sửa (quy ước
  Botble §1). Vì vậy dùng cách "đọc 1 lần + ghi phần thiếu" thay cho `upsert()`.
- Lưu ý: slug `provider_ad_id_ad_id`, `provider_adgroup_idoup_id` trông như gõ nhầm, và `id` không
  khớp thứ tự tiêu đề. Phải đối chiếu với dữ liệu thật trước khi động vào, vì sửa slug sẽ làm lệch
  bản ghi đã lưu.

---

## 3. Bug logic (không phải hiệu năng, nhưng ảnh hưởng dữ liệu)

### 3.1 Sinh nhật member — tính năng thực tế không gửi được ✅

`src/Commands/MemberBirthdayNotificationCommand.php` + `src/Notifications/MemberBirthdayNotification.php`:

1. **Không bao giờ gửi (dòng 69 + 74–77).**
   - SQL lọc đúng "cùng tháng-ngày" (`DATE_FORMAT(dob,'%m-%d') = hôm nay`).
   - Sau đó PHP lại so **cả ngày-tháng-năm**: `$birthday->startOfDay()->eq(Carbon::today())`. Người
     sinh 28/10/1990 so với 28/10/2026 ⇒ khác năm ⇒ `false` ⇒ không phát event.
   - Chỉ người có `dob` đúng **ngày hôm nay của năm nay** mới được gửi. Nhánh fallback `created_at`
     cũng không bao giờ chạy tới, vì SQL đã bắt buộc có `dob`.
2. **Gửi tới địa chỉ cứng (dòng 76–81):** `sendUsingTemplate(..., 'toan@visualweber.com' ?: null,
   ..., true /* debug */)` gửi template tới một email nội bộ cố định chứ không phải member, rồi lại
   trả `MailMessage` cho kênh mail ⇒ có thể **2 email/lượt** (1 tới địa chỉ cứng).
3. **`setVariableValues(['email', $notifiable->email])` (dòng 72–75):** truyền mảng **danh sách**
   (key 0, 1), không phải `['email' => …]` ⇒ biến `email` trong template có thể không được thay.
   Cần xem chữ ký `EmailHandler::setVariableValues` để xác nhận.
4. Member không có email ⇒ `$emailHandler` chưa định nghĩa ở dòng 87 ⇒ lỗi; `catch` gọi `dd()` ⇒
   dừng tiến trình worker (đã nêu ở lượt 1).
5. **Không chống gửi trùng:** cron chạy 2 lần trong ngày (hoặc job retry) sẽ gửi 2 lần.

**Giải pháp:**
- So **tháng-ngày** thay vì cả ngày (hoặc bỏ hẳn bước so PHP, vì SQL đã lọc).
- Dùng `Cache::add("birthday:{memberId}:{year}", 1, ttl đến hết năm)` làm khoá "đã gửi năm nay".
- Bỏ địa chỉ cứng; sửa `setVariableValues` theo dạng khoá-giá-trị; `toMail()` trả sớm khi không có
  email; thay `dd()` bằng log + throw lại để queue retry đúng cơ chế.
- Người sinh 29/02: quyết định gửi vào 28/02 hay 01/03 ở năm không nhuận (nghiệp vụ chọn).
- Hiệu năng (thấp): `DATE_FORMAT(dob)` không dùng được index ⇒ quét cả bảng mỗi ngày.
  - Chỉ đáng xử lý khi bảng member lớn.
  - Cách xử lý: thêm cột sinh (generated column) `dob_md` + index. Thay đổi này phải nằm trong
    **migration của plugin sở hữu bảng Member** (`Dev\AdvancedRole`), không đặt ở kernel.

### 3.2 Chuẩn hoá dữ liệu lead ✅

- **`apps_phone_convert()` (~dòng 1162):** `preg_replace("/[^0-9.]/", "", …)` **giữ lại dấu chấm**.
  - Người Việt hay nhập `0982.311.866`; kết quả vẫn còn dấu chấm, dài 12 ký tự, không qua được các
    nhánh chuẩn hoá ⇒ SĐT lưu sai định dạng.
  - Hậu quả: so trùng lead theo SĐT trượt; CRM đích có thể từ chối.
  - Sửa: regex `/\D/`. Lưu ý dữ liệu cũ đã lưu có dấu chấm (cân nhắc chuẩn hoá lại khi so trùng).
  - Hàm còn trả `false` khi rỗng (nên là `null`).
- **`apps_leadgen_prepare_data()` dòng 3185:** `$data['mau_xe_ma_ban_quan_tam'] = …` gán vào biến
  không tồn tại ⇒ field này không bao giờ được chuẩn hoá (đã nêu ở lượt 1, xác nhận lại ✅).
- **Chuỗi ưu tiên ngầm `notes`/`description`** (đính chính ở mục 1.3): nên viết thành một danh
  sách ưu tiên **tường minh** trong một lần gán, để người sau không "dọn" nhầm.
  - Cũng cần xem lại việc `'model'` (dòng xe) nằm trong danh sách dự phòng cho `notes`: lead không
    có nội dung sẽ bị ghi chú bằng tên dòng xe.
- **Log toàn bộ lead ở mức `info` (dòng 3269):** gồm SĐT, email, **CCCD**, mã số thuế, lưu dạng văn
  bản 14 ngày.
  - Ngoài chi phí I/O, đây là dữ liệu cá nhân nhạy cảm nằm trong log.
  - Gợi ý: che bớt (vd `09xx…866`), hoặc chỉ log danh sách khoá, không log giá trị.

### 3.3 Dữ liệu địa giới hành chính lỗi thời ✅

- `apps_province_detection()` dùng danh sách **63 tỉnh cũ**, còn có cả **"Hà Tây"** (đã nhập vào
  Hà Nội từ 2008).
- Sau đợt sắp xếp đơn vị hành chính năm 2025, cả nước còn 34 tỉnh/thành và **bỏ cấp huyện**. Danh
  sách tỉnh và dữ liệu quận/huyện (`apps_get_provinces_districts_rawdata`, chỉ có 4 tỉnh) đều không
  còn khớp địa giới hiện hành.
- **Không tự đổi ngay:** CRM của đại lý (Toyota/Thaco…) có thể vẫn dùng tên tỉnh cũ để định tuyến
  lead. Cần hỏi nghiệp vụ: chuẩn hoá về tên mới, hay giữ tên cũ kèm bảng ánh xạ cũ → mới.
- Logic so khớp bằng `Str::contains` theo thứ tự mảng có thể khớp nhầm chuỗi con khi tên tỉnh nằm
  lẫn trong chuỗi đại lý dài. Nên ưu tiên khớp **dài nhất** hoặc khớp nguyên từ.
- `Collection::whereCaseInsensitive` (`MacroServiceProvider`) dùng `strtolower`, không xử lý chữ có
  dấu (`"ĐÀ NẴNG"` ≠ `"đà nẵng"`) ⇒ dùng `mb_strtolower`/`Str::lower`.

---

## 4. Công cụ mirror Google Drive

### 4.1 `src/Commands/GDriveMirrorSync.php` (PHP, 3058 dòng)

Chạy **tuần tự hoàn toàn** (liệt kê từng folder, tải từng file). Phần ghi state/report đã ổn
(1 lần mỗi folder / mỗi run). Theo thứ tự lợi ích:

1. **`files.get` thừa cho mọi file thường ✅ (dòng ~985–1004).**
   - Điều kiện thật là `if (!$exportSpec && $isId)`, trong khi comment nói chỉ "khi thiếu mimeType".
     Ở chế độ ID, `files.list` đã trả sẵn `mimeType/md5Checksum/size`, nên **mỗi file thường tốn
     thêm 1 request, kể cả file sắp được bỏ qua vì không đổi**.
   - Ước lượng: 5.000 file ⇒ khoảng 5.000 request tuần tự, ~8–17 phút mỗi run.
   - Sửa: chỉ gọi khi `mimeType` rỗng hoặc là `application/octet-stream`, và đặt **sau** bước so
     khác biệt. Rủi ro thấp.
2. **Hash MD5 lại toàn bộ file đã tải mỗi run 🔎 (dòng ~1093–1115).**
   - Port fast-path của bản Go (commit `033be7f`): nếu `filesize == remoteSize` và
     `filemtime === remoteTimestamp` khớp tuyệt đối thì bỏ qua hash. Code đã `touch()` mtime sau mỗi
     lần tải.
   - Một run "không có gì đổi" khi đó chỉ còn chi phí `stat`.
3. **Liệt kê đệ quy tuần tự, mỗi folder 1 request 🔎 (dòng ~1844–1994):**
   - gộp 30–50 folder cha vào một query `q=('a' in parents or 'b' in parents …)` kèm field `parents`
     ⇒ số request giảm khoảng ×30;
   - hoặc song song hoá bằng `GuzzleHttp\Pool`.
   - Phải giữ cơ chế "verify khi list trả rỗng" **theo từng cha**.
4. **`sleep(1)` + 1 request verify cho mỗi folder rỗng 🔎 (dòng ~1853).** Gom lại verify một lần
   cuối pha liệt kê. 300 folder rỗng ⇒ tiết kiệm khoảng 5 phút mỗi run.
5. **Không có incremental (Changes API) 🔎.** Bản Go đã có.
6. **Ghi stream 8 KB/lần 🔎 (dòng ~2320).** Dùng Guzzle `'sink' => $tmpPath` để cURL ghi thẳng ra
   đĩa.
7. **Preflight quét đệ quy toàn cây local mỗi run 🔎 (dòng ~2239–2288)** chỉ để tìm file tạm sót.
   Ghi marker "đang chạy" và chỉ quét khi lần trước không kết thúc sạch.
8. **Bug nhận diện ID ✅ (dòng 672):** `strpos('/')===false && strlen>20` ⇒ tên folder một cấp dài
   hơn 20 ký tự (vd `Marketing Materials 2026`) bị coi là ID. Dùng
   `preg_match('/^[A-Za-z0-9_-]{20,}$/')`.
9. **Bug mtime 1970 ✅ (dòng 975–977, 1174):** thiếu timestamp ⇒ `$remoteTimestamp = 0` ⇒
   `touch(path, 0)`. File Google-native khi đó có thể **không bao giờ được export lại**. Chỉ
   `touch` khi timestamp > 0.
10. `withRetry` không đọc `Retry-After`, không có jitter 🔎.
11. **Kiến trúc — trùng chức năng với Go CLI 🔎.**
    - Nhiều hàm PHP ghi rõ là "port 1:1 từ bản Go"; bản Go đã có song song, incremental, fast-path,
      rename-tracking, `--push-to`.
    - PHP chỉ còn 2 thứ riêng: OAuth bằng refresh token lấy từ settings, và chạy qua Laravel
      scheduler.
    - Gợi ý: biến command PHP thành **wrapper mỏng** gọi binary Go qua `Symfony\Process` (truyền
      tham số, đẩy stdout vào log, ánh xạ exit code). Bổ sung OAuth refresh token cho bản Go, hoặc
      giữ nhánh PHP chỉ cho chế độ OAuth.
    - Lợi ích: có ngay các mục 2–7 mà không phải port, và chỉ còn **một** bản logic để bảo trì.
    - Rủi ro trung bình: phải phân phối đúng binary cho hệ điều hành của server.

### 4.2 `tools/gdrive-mirror-cli` (Go)

Nền tảng đã tốt: `go vet` sạch, `go test ./...` pass; đã dùng `Fields()` + `PageSize(1000)`;
transport đã chỉnh `MaxIdleConnsPerHost`, giữ HTTP/2. Phần còn lại:

1. **Bug — token incremental lấy SAU khi liệt kê ✅.** `sync.go:175` list toàn cây xong, tới dòng
   204 → `incremental.go:406` mới gọi `GetStartPageToken()`. File sửa **trong lúc** đang list cây
   lớn (có thể vài phút), ở folder đã list xong, sẽ không bao giờ xuất hiện trong Changes API.
   Sửa: lấy token **trước** khi list. Rủi ro thấp.
2. **Hết quota ngày vẫn retry từng file ✅** (`internal/classify/classify.go:65–66, 101–102`:
   `dailyLimitExceeded`/`quotaExceeded` được coi là có thể thử lại).
   - Quota ngày không tự hồi trong run ⇒ mỗi file còn lại tốn khoảng 14 giây backoff rồi vẫn lỗi.
     Ví dụ 10.000 file / 4 worker ⇒ khoảng 10 giờ chờ vô ích.
   - Sửa: circuit breaker, K lần liên tiếp dính quota ngày thì dừng run, ghi report để
     `--retry-failed` hôm sau.
3. **Giữ slot semaphore trong lúc ngủ 1 giây** để verify folder rỗng 🔎 (`list.go`). Trả slot trước
   khi ngủ, hoặc verify gộp cuối pha.
4. **Mỗi folder ≥ 1 request** 🔎:
   - gộp nhiều cha vào một `q`;
   - nếu gốc là Shared Drive: `corpora=drive&driveId=…` liệt kê phẳng toàn drive rồi dựng cây ở
     client (khoảng 50 request thay vì khoảng 5.000 cho 50k item).
5. **`--push-to` duyệt lại toàn bộ 2 cây mỗi run** 🔎. Syncer đã biết tập file vừa ghi/dời ⇒ chỉ
   đẩy đúng tập đó. Giữ full-walk làm chế độ dọn định kỳ. Go copier chạy tuần tự ⇒ dùng worker pool.
6. **Log tiến độ mỗi file, cả file SKIP, ra stderr không đệm** 🔎. `run-mirror.ps1` đẩy từng dòng
   qua pipeline PowerShell (chậm, có thể chặn exe). In tóm tắt mỗi N file hoặc mỗi giây.
7. **Retry không jitter, không throttle chung giữa worker** 🔎. Thêm full jitter + "cổng" dùng chung
   khi một worker dính rate-limit + đọc `Retry-After`.
8. **Không có timeout đọc khi tải** 🔎. Thêm `ResponseHeaderTimeout` + watchdog "không có byte mới
   trong X giây".
9. Manifest đọc/parse 2 lần mỗi run, `MarshalIndent`, ghi **không atomic** 🔎 (chết giữa lúc ghi ⇒
   mất index rename). `assembleItems` đệ quy `append(...)` có chi phí O(N × độ sâu) 🔎. `runFolders`
   tạo client mới cho mỗi folder 🔎. robocopy gom toàn bộ output vào RAM 🔎. `extractFolderIDs`
   nuốt nhầm cờ ở dạng `--path D:\x` 🔎. `ScanDuplicates` so tên theo byte, có thể báo nhầm trên
   HFS+/NFD 🔎.
10. **Binary trong git:**
    - `dist/` khoảng 76 MB + `gdrive-mirror.exe` 23,8 MB ở thư mục gốc tool; pack khoảng 98 MiB.
    - `.gitignore` ghi rõ việc commit `dist/` là **quyết định đã chốt (2026-07-20)**, nên không tự
      đổi.
    - Rủi ro đáng chú ý 🔎: `gdrive-mirror.exe` ở gốc **khác** bản
      `dist/gdrive-mirror-windows-amd64.exe` (hash khác, có vẻ build không strip) ⇒ người vận hành có
      thể chạy nhầm phiên bản. Nên chỉ giữ một nguồn.

---

## 5. Bảo mật lộ ra trong lúc đọc

- **`GET /api/v1/test/middleware-check` ✅** — không middleware, không xác thực. Trả về
  `headers->all()` (gồm cả **`Authorization`** nếu client gửi), toàn bộ cookie và
  `session()->all()`. Gỡ khỏi production hoặc chỉ bật ở môi trường local.
- **`GET /api/v1/cms/plugins/get-plugins` ✅** — công khai danh sách plugin đang bật (thông tin trinh
  sát cho kẻ tấn công).
- **App secret Facebook gắn cứng** (~dòng 2114) — chuyển vào config/env, và **xoay vòng secret** vì
  nó đã nằm trong lịch sử git.
- `TestCommand` (gọi được trên production, mở đầu bằng `dd()`), `LicenseServerController`
  (không route nào trỏ tới) ⇒ code chết, nên gỡ.
- `SecurityHeaders` tin trực tiếp header `X-Forwarded-Proto` (bỏ qua `TrustProxies`). CSP vừa có
  `upgrade-insecure-requests` vừa cho phép `http:`. Cả hai mức thấp.

---

## 6. Đo lường & test — nền để tối ưu an toàn

**Số đo nền nên có trước khi sửa** (để so trước/sau):

| Chỉ số | Cách đo gợi ý |
|---|---|
| Thời gian ghi 1 lead vào Sheet (p50/p95) và từng bước (token, tên tab, header, append) | `apps_log_write(..., 'start'/'step'/'success')` đã có sẵn chế độ đo thời gian |
| Số request Google mỗi lead, số lead/phút trước khi bị 429 | Đếm theo log |
| Số query + thời gian DB mỗi job lead | `DB::listen` tạm thời, hoặc slow query log |
| Số dòng log/lead, dung lượng log/ngày | `wc -l` theo kênh |
| Thời gian 1 run mirror "không có gì đổi" | Summary cuối run đã có `duration` |

**Hạ tầng test:**
- `tests/bootstrap.php` chỉ nạp autoload + helpers: không có Laravel app, không DB, không giả lập
  HTTP. Vì vậy các hàm gọi CRM, Sheet, Telegram, tỉ giá **không thể test**.
- Gợi ý: `orchestra/testbench` (chuẩn để test package Laravel) + `Http::fake()`. Việc chuyển cURL
  sang `Http::` (2.3d) là điều kiện để fake được.
- Hai file test gsheet chết: viết lại theo hàm hiện có, hoặc xoá. Hiện `phpunit` sẽ fatal ở 2 file
  này.
- Nên có test khoá hành vi **trước** khi đổi các hàm ở mục 2.3, 2.5, 2.6, 3.2.

---

## 7. Gợi ý thứ tự (tham khảo — agent tự quyết)

- **Đợt 0, đo:** gắn đo thời gian từng bước ghi Sheet và đếm request/query (mục 6). Làm nhanh,
  không đổi hành vi.
- **Đợt 1, bug rẻ & rủi ro thấp:**
  - sinh nhật (3.1), dấu chấm SĐT (3.2), `$data` → `$lead` (3.2);
  - `env()` → `config()` (2.2), timeout CRM (2.3a);
  - gỡ endpoint debug (5);
  - GDrive PHP `files.get` thừa + mtime 1970 + nhận diện ID (4.1);
  - Go token incremental + circuit breaker quota (4.2).
- **Đợt 2, luồng nóng:**
  - Sheet 4 → 2 request (2.1);
  - transaction + đọc gộp custom field (2.6);
  - cache token CRM có khoá (2.3b);
  - tách session khỏi `api` (2.4), cần liệt kê route dùng session trước;
  - versioned cache (2.5).
- **Đợt 3, kiến trúc:**
  - GDrive PHP → wrapper Go (4.1.11);
  - gộp client CRM (2.3d);
  - gom append Sheet theo lô ở app tiêu thụ (2.1C);
  - quyết định nghiệp vụ về địa giới hành chính mới (3.3).

---
*Tài liệu do Claude tạo trong phiên audit theo yêu cầu người dùng. Tự đọc lại code để kiểm chứng
(đánh dấu ✅), kết hợp 2 lượt đọc song song cho `GDriveMirrorSync.php` và Go CLI (đánh dấu 🔎).
Không có thay đổi nào tới code sản xuất. Toàn bộ nội dung là tài liệu hỗ trợ/tham khảo cho agent
nhận việc, không phải chỉ thị.*
