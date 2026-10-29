# Audit toàn hệ thống — đề xuất tối ưu logic & tốc độ

> Trạng thái: **AUDIT — chưa sửa code.** File này là **tài liệu hỗ trợ**: tổng hợp dữ kiện đã đọc
> được và các phán đoán/đề xuất đã nghĩ tới trong lượt audit này. Đây **không phải chỉ thị** cho
> agent nào nhận việc — mọi mức ưu tiên, hướng sửa ở các mục dưới là gợi ý tham khảo; agent nhận
> việc tự đọc lại code, tự đo lại (profiling, log thật, EXPLAIN query...) trước khi quyết định làm
> gì, theo thứ tự nào, có quyền bác bỏ hoặc đổi hướng nếu dữ kiện mới không khớp.

## ⛔ BẮT BUỘC với agent thực thi — đọc trước khi đụng vào code

**Tài liệu này đã được xác nhận là có chỗ SAI.** Lượt kiểm chứng sau
(`tasks/audit-he-thong-toi-uu-chuyen-sau.md`, mục 1) đã đính chính 4 nhận định:
- mảng tỉnh/huyện;
- file tạo bảng `licenses`;
- `notes`/`description`;
- cron sinh nhật.

Phần còn lại cũng có thể sai, hoặc đã lỗi thời do code thay đổi. **Không được tin tuyệt đối vào bất
kỳ câu nào ở đây.**

Agent thực thi **bắt buộc** áp dụng đầy đủ quy trình **"BẮT BUỘC"** ở đầu
`tasks/audit-he-thong-toi-uu-chuyen-sau.md` cho mọi mục định xử lý:
- tự kiểm chứng tại nguồn trên code hiện tại;
- tự đo trước và sau;
- tự phản biện từng giải pháp;
- tái hiện trước, sửa sau;
- ghi bằng chứng;
- dừng khi không kiểm chứng được.

Ưu tiên đọc bản chuyên sâu trước; tài liệu này chỉ để đối chiếu.

## 0. Phạm vi đã đọc / chưa đọc

**Đã audit (read-only, 4 lượt đọc song song):**
- `helpers/helpers.php` (toàn bộ 3221 dòng, trừ cụm `apps_google_sheet()`/`apps_gsheet_header_row()`
  đã audit riêng — xem `tasks/audit-gsheet-nhay-cot.md`), `helpers/constants.php`.
- `src/Traits/FieldHelper.php` và toàn bộ `src/{Commands,Providers,Traits,Events,Listeners,
  Notifications,Http,Exceptions,Base,Seeders}` — trừ `src/Commands/GDriveMirrorSync.php` (xem giới
  hạn bên dưới).
- `routes/api.php`, `routes/web.php`, `config/*.php`, `database/migrations/*.php`, `composer.json`.
- `tools/gdrive-mirror-cli/` (Go): `main.go`, toàn bộ `internal/mirror/*.go`, `internal/report/*.go`
  (lướt), không đọc `*_test.go` chi tiết, không đọc `dist/` (binary).
- `tests/` (toàn bộ `tests/Unit/*.php`, `tests/bootstrap.php`, `phpunit.xml`), cấu trúc thư mục repo.

**Chưa đào sâu (nêu rõ để không tưởng nhầm là đã audit):**
- `src/Commands/GDriveMirrorSync.php` (**3058 dòng**) — chỉ grep nhanh, thấy là lớp dispatcher gọi
  binary Go, **chưa đọc chi tiết từng dòng**. Đây là 1 trong các file lớn nhất repo, nên audit riêng
  nếu cần.
- Không chạy profiler/EXPLAIN thật trên DB production — mọi nhận định "N+1"/"không dùng index" là
  **đọc code suy ra**, chưa đo bằng số liệu thật.
- Không audit bảo mật sâu (OWASP, injection...) — chỉ ghi nhận các điểm bảo mật *lộ ra tự nhiên*
  trong lúc đọc logic/hiệu năng, không phải mục tiêu chính của lượt này.

---

## 1. Bug thật / rủi ro crash — mức ảnh hưởng cao nhất

1. **`helpers/helpers.php::apps_leadgen_prepare_data()` (~dòng 3134)** — gán nhầm vào biến `$data`
   (không tồn tại trong scope hàm) thay vì `$lead['mau_xe_ma_ban_quan_tam']`. Giá trị rơi vào biến
   chết, PHP chỉ warning (không chết tiến trình), nhưng field `mau_xe_ma_ban_quan_tam` **không bao
   giờ** có mặt trong dữ liệu lead trả về — bug âm thầm, không phải giả thuyết.
2. **`src/Notifications/MemberBirthdayNotification.php::toMail()` (~dòng 56–92)** — biến
   `$emailHandler` chỉ được gán **bên trong** `if (!blank($notifiable->email))`, nhưng dòng 87–88
   **ngoài** nhánh `if` đó vẫn gọi `$emailHandler->getContent()`. Member không có email (hoặc email
   rỗng) → lỗi biến chưa định nghĩa, job queue fail.
3. **Cùng file, dòng 89–91**: `catch (\Throwable $th) { dd($th->getMessage()); }` — `dd()` **dừng
   tiến trình** (`exit`) bên trong một job hàng đợi. Không throw lại để queue tự retry, không log —
   worker chạy job này có thể dừng bất thường thay vì job fail sạch sẽ theo cơ chế Laravel Queue.
4. **`routes/web.php` (route `GET /test`) → `KernelController@test`** — `KernelController` là class
   rỗng, không có method `test` → gọi route này luôn `BadMethodCallException`. Route chết.
5. **`src/Commands/TestCommand.php` (330 dòng)** — mở đầu bằng `dd()` nên mọi dòng sau không bao
   giờ chạy, nhưng **được đăng ký thật** trong `CommandServiceProvider` và gọi được qua
   `php artisan cms:test-command` trên production. Trong file có vẻ có access-token mẫu hardcode
   (dòng ~194) — cần xác nhận đó là token thật hay token giả/demo trước khi coi là rò rỉ.
6. **`src/Commands/CheckMiddlewareCommand.php` (~dòng 69–80)** vs **`KernelServiceProvider.php`
   (~dòng 85–92)** — command kỳ vọng `ValidateSignature`/`VerifyCsrfToken` có trong middleware group
   `api`, nhưng provider đang **comment out** 2 middleware này (có chủ đích). Lệnh check vì vậy
   **luôn báo sai** "MISSING" cho 2 middleware bị tắt chủ ý — false alarm tự tạo ra, làm giảm độ tin
   cậy của lệnh check khi có vấn đề thật.
7. **`KernelServiceProvider.php` (~dòng 123 và 126)** — `loadMigrations()` gọi **2 lần** trong cùng
   `boot()`. Không gây lỗi chức năng, nhưng là dấu hiệu copy-paste sót, nên rà lại.

## 2. Bảo mật lộ ra khi đọc logic/hiệu năng (không phải audit bảo mật đầy đủ)

8. **`helpers/helpers.php::apps_facebook_parse_signed_request()` (~dòng 2114)** — App secret
   Facebook hardcode thẳng trong code nguồn (chuỗi hex) thay vì đọc từ `config()`/`.env`. Secret này
   **nằm vĩnh viễn trong lịch sử git** kể cả sau khi đổi secret thật trên Facebook.
9. **`routes/api.php` (~dòng 75–78), endpoint `GET /api/v1/test/middleware-check`** — không có
   middleware nào bảo vệ, handler trả **toàn bộ session, cookie, header** của request ra JSON công
   khai. Không ảnh hưởng tốc độ, nhưng là route debug lộ thông tin nhạy cảm nếu còn mở trên
   production.
   - **Đính chính 29/10/2026:** dữ liệu trả về là **của chính người gọi**, không phải của người khác.
     Rủi ro thật là phản chiếu cookie HttpOnly / `Authorization` (khai thác được khi có XSS). Xem
     `tasks/audit-he-thong-toi-uu-chuyen-sau.md` mục 5.
10. **`src/Http/Middleware/TrustProxies.php` (~dòng 24)** — `protected $proxies = '*'`: tin mọi
    proxy, cho phép client tự set `X-Forwarded-For` giả nếu request không chắc chắn luôn đi qua một
    lớp reverse-proxy tin cậy trước. Ảnh hưởng tới bất kỳ logic nào dùng `$request->ip()` (vd
    rate-limiting ở `KernelServiceProvider.php` ~dòng 180) — IP có thể bị giả mạo.
11. **5 hàm `apps_*_crm_login()` (`apps_pull_login`, `apps_vtiger_login`, `apps_toyota_crm_login`,
    `apps_mmv_crm_login`, `apps_apollo_crm_login`)** — một số đặt `CURLOPT_SSL_VERIFYPEER => false`
    (bỏ qua xác thực TLS, rủi ro MITM với hệ thống CRM đối tác).

## 3. Hiệu năng — ảnh hưởng tần suất cao (chạy mỗi lead / mỗi request / mỗi cron)

12. **`src/Traits/FieldHelper.php::saveCustomFields()` (~dòng 19–27)** — loop qua 6 custom field,
    mỗi field gọi `saveCustomField()` riêng → mỗi field tốn ~1 SELECT + 1 INSERT/UPDATE ⇒ ~12 query
    tuần tự cho **mỗi lần lưu 1 lead**. Có thể gộp 1 query `whereIn('slug', [...])` lấy hết record
    hiện có trước, rồi ghi hàng loạt — nhưng cách gộp cụ thể (upsert / batch) cần cân nhắc theo
    đúng hành vi hiện tại (có cần giữ đúng side-effect từng field không).
13. **`helpers/helpers.php::apps_cache_get_key()` + `apps_cache_store/get/flush` (~dòng 2293–2708)**
    — mỗi lần **ĐỌC** cache cũng kích hoạt ghi lại danh sách group/app list (để phục vụ flush theo
    nhóm) → mỗi lượt đọc cache tốn thêm 2–4 round-trip tới cache backend thay vì 1, và không có
    lock nên có khả năng race giữa các worker đồng thời làm mất entry trong danh sách theo dõi.
14. **`helpers/helpers.php::apps_get_provinces_districts_rawdata()` (~dòng 2015–2096)** — mảng dữ
    liệu tỉnh/huyện cỡ lớn được **khởi tạo lại từ đầu mỗi lần gọi hàm** (không cache trong request,
    không cache Laravel), kèm `array_combine`/`array_map` chạy lại toàn bộ mỗi lần dù dữ liệu gần
    như tĩnh. Có 1 key `"Bạc Liêu"` khai báo trùng trong literal (dòng ~2027 và ~2075) — vô hại vì
    giá trị giống nhau, nhưng là dấu hiệu copy-paste cần dọn.
15. **`src/Commands/MemberBirthdayNotificationCommand.php` (~dòng 69)** — dùng
    `whereRaw('DATE_FORMAT(`dob`, "%m-%d") = ...')`: bọc hàm quanh cột `dob` khiến MySQL **không
    dùng được index** trên cột này, full table scan mỗi lần cron chạy (hàng ngày). Cùng file, dòng
    ~74–77 còn lặp lại kiểm tra điều kiện ngày trong PHP sau khi SQL đã lọc — trùng lặp điều kiện,
    có thể dư thừa (cần xác nhận logic 2 lớp này có khác nhau thật không, hay chỉ copy-paste).
16. **5 hàm CRM login** không cache token — mỗi lần cần gọi CRM là authenticate lại từ đầu, tốn
    1–2 round-trip HTTP thừa mỗi lần, **không có `CURLOPT_TIMEOUT`/`CURLOPT_CONNECTTIMEOUT`** → có
    thể treo worker vô thời hạn nếu CRM đối tác không phản hồi (so sánh: `apps_google_sheet()`'s
    OAuth token ĐÃ có cơ chế cache theo TTL — các hàm CRM login chưa áp dụng cùng pattern).
17. **`tools/gdrive-mirror-cli/internal/mirror/push.go::pushWithGoCopier`** (nhánh non-Windows của
    `--push-to`) — copy **tuần tự** qua `filepath.WalkDir`, không dùng worker pool như luồng
    download chính (`--concurrency`). Windows có `robocopy /MT:8` chạy song song, Linux/macOS thì
    không — bất đối xứng hiệu năng giữa các OS cho cùng 1 tính năng, đáng chú ý nếu `--push-to`
    được dùng nhiều trên Linux/macOS với cây file lớn lần đầu (chưa có gì để skip).
18. **`tools/gdrive-mirror-cli/internal/mirror/list.go::ListFolderRecursive`** — spawn 1
    goroutine/node không giới hạn (chỉ phần gọi API Drive bị chặn bởi `--list-concurrency`); với
    cây hàng trăm nghìn folder, số goroutine sống cùng lúc có thể rất lớn (chi phí bộ nhớ/scheduler
    tăng tuyến tính). Không sai, chỉ đáng cân nhắc nếu quy mô cây Drive thực tế tăng nhiều.

## 4. Trùng lặp code / dọn dẹp

19. **5 hàm `apps_*_crm_login()`** — code cURL gần như giống hệt nhau (~70–100 dòng lặp lại x5),
    có vài nhánh `if ($info['http_code'] == 200) {}` rỗng (dead code), và nhiều nơi trả về
    `"error" => false` **ngay trong nhánh xử lý lỗi** (dòng rải rác ~1444, 1572, 1664, 1752, 1893) —
    tên field `error=false` dù đang ở nhánh thất bại, dễ gây hiểu nhầm phía gọi hàm. Trước khi gộp
    hoặc đổi field này cần rà lại toàn bộ nơi gọi 5 hàm này xem có đang dựa vào field `error` theo
    đúng nghĩa hiện tại không.
20. **`apps_get_worksheet_column_titles()` (helpers.php ~dòng 191–232)** — xác nhận lại là dead
    code (dùng GData API cũ, không còn nơi nào gọi trong code hiện hành).
21. **`tools/gdrive-mirror-cli`: `copyFileAtomic` (push.go) và `downloadItem` (download.go)** —
    gần như trùng logic atomic-write-rename (tạo temp → copy → close → rename → chtimes), chỉ khác
    nguồn dữ liệu (HTTP response vs local file).
22. **`database/migrations/2023_05_21_194154_sample.php`** — migration **rỗng hoàn toàn**
    (`if (Schema::hasTable('sample')) {}` không làm gì), kèm 2 method `listTableIndexes`/
    `listTableForeignKeys` khai báo nhưng không ai gọi. Có vẻ là file scaffold mẫu bỏ sót trong repo
    thật.
23. **Toàn bộ test liên quan gsheet đang chết (orphan)**: `tests/Unit/AppsGsheetWriteTest.php` và
    `AppsGsheetAppendSucceededTest.php` gọi 5 hàm không còn tồn tại trong `helpers.php`
    (`apps_gsheet_order_row`, `apps_gsheet_update_succeeded`, `apps_gsheet_is_grid_limit_error`,
    `apps_gsheet_is_transient_error`, `apps_gsheet_append_succeeded`) — xác nhận bằng grep, 0 match.
    Chạy `phpunit` sẽ fatal error ở 2 file này.
24. **`src/Traits/LanguageAdvancedTrait.php`** — đánh dấu `@deprecated 5.9`, còn giữ cả `v1`
    (comment sẵn) lẫn `v2` song song — chưa rõ có nơi nào còn dùng.

## 5. Vi phạm / lệch quy ước nền tảng đã de-brand

25. **`database/migrations/2023_05_21_194154_sample.php` tạo bảng `licenses`** không có prefix
    `app_` theo quy ước bảng mới (§2 của skill). Có thể là chủ đích (bảng hạ tầng license, không
    phải nội dung CMS) — cần xác nhận với người giữ quy ước có coi đây là ngoại lệ hợp lệ không,
    chứ không tự suy ra là sai.
26. **`src/Traits/FieldHelper.php` (~dòng 24)** — truy cập `$data[$row['slug']]` trực tiếp, không
    qua `Arr::get()`/`data_get()`/toán tử `??` — lệch §5 của skill (truy xuất mảng an toàn), và về
    mặt thực tế sẽ phát PHP warning nếu thiếu key.
27. Không phát hiện Model Eloquent / Enum nào trong phạm vi đã đọc vi phạm quy ước BaseModel/UUID/
    Enum (§3 của skill) — vì `src/` của kernel này không định nghĩa Model nào ngoài phạm vi
    Seeders/migrations đã nêu ở trên.

## 6. Cấu trúc repo / git hygiene (liên quan tốc độ clone/CI, không phải tốc độ runtime)

28. **`tools/gdrive-mirror-cli/dist/`** chứa 5 binary build sẵn (darwin/linux/windows amd64+arm64)
    tổng **~78MB** commit thẳng vào git — là nguyên nhân chính khiến `.git` của repo nặng (quan sát
    thấy ~115MB lúc audit). Build binary thường không nên nằm trong git history của source.
29. **`public/google-drive-guide/`** — 11 ảnh hướng dẫn (~1.9MB) nằm trong thư mục `public/` của
    một **thư viện Composer** (không phải ứng dụng có web server riêng) — vị trí này hơi lạ, có vẻ
    hợp lý hơn nếu đặt cạnh `GOOGLE_DRIVE.md` dưới dạng tài liệu thay vì `public/`.

## 7. Khoảng trống về test (ảnh hưởng tới việc dám tối ưu/refactor các mục trên)

30. **Không có hàm nào trong `helpers/helpers.php` (ngoài `GDrive*`) hiện có test sống bảo vệ** —
    đặc biệt 5 hàm CRM login, `apps_telegram_send_message`, `apps_currency_exchange`,
    `apps_facebook_parse_signed_request`, và `apps_google_sheet()` (hàm phức tạp nhất file, 0 test
    trực tiếp). `src/Commands/*`, `src/Providers/*` cũng chưa có test nào.
31. **Nguyên nhân gốc**: `tests/bootstrap.php` chỉ load `vendor/autoload.php` + `helpers.php`,
    không có Laravel TestCase/Kernel, không DB test, không mock HTTP — nên các hàm gọi `Http::`/
    cURL/DB thật hiện **không thể test được** với hạ tầng test hiện tại, không chỉ là "chưa ai viết
    test". Đây là một giới hạn cần giải quyết trước khi có thể tự tin sửa các mục ở trên mà không
    sợ phá hành vi.
32. Ở phía Go (`tools/gdrive-mirror-cli`), độ phủ test tốt hơn hẳn — 19 file `*_test.go` phủ hầu
    hết module theo tên (sync, download, delta, incremental, preload, push, scan, list, cloudsync,
    report...). `go vet ./...` và `gofmt -l .` đều sạch lúc audit.

---

## 8. Vài hướng đã nghĩ tới (tham khảo — agent nhận việc tự đánh giá lại)

Không phải checklist bắt buộc theo thứ tự — chỉ là các khả năng đã nghĩ ra trong lúc audit:

- Dựng lại hạ tầng test (mục 31) trước, vì nhiều mục ở trên (đặc biệt mục 12, 16, 19) cần test bảo
  vệ hành vi hiện tại mới dám đổi mà không lo phá side-effect đang chạy thật trên production.
- Mục 2–4 (crash thật: `MemberBirthdayNotification`, route `/test` chết, `TestCommand` chạy được
  trên production) có vẻ rẻ để sửa và rủi ro thấp vì đang *rõ ràng là lỗi*, không phải đánh đổi —
  nhưng vẫn nên tự xác nhận lại (vd `TestCommand` có token thật hay không) trước khi hành động.
- Mục 12–16 (hiệu năng chạy thường xuyên) đáng đo bằng số liệu thật (log, APM, EXPLAIN) trước khi
  sửa, vì chỉ đọc code không chắc được mức ảnh hưởng thực tế trên quy mô dữ liệu hiện tại.
- Mục 19 (gộp 5 hàm CRM login) có vẻ hấp dẫn để giảm trùng lặp, nhưng field `error=false` lẫn lộn
  nghĩa khiến việc gộp rủi ro hơn nhìn qua — nên rà nơi gọi trước, không gộp vội.
- Mục 28 (binary 78MB trong git) là việc hạ tầng/git, không phải logic — có thể làm độc lập, không
  phụ thuộc các mục khác.

---
*File do Claude tạo trong phiên audit theo yêu cầu người dùng, tổng hợp từ 4 lượt đọc song song
(helpers.php, src/ kernel, tools/gdrive-mirror-cli, test coverage). Không có thay đổi nào tới code
sản xuất trong phiên này. Nội dung mục 8 là tài liệu hỗ trợ/tham khảo cho agent nhận việc, không
phải chỉ thị.*
