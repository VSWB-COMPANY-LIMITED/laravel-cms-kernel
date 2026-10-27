# Audit: Cột bị "nhảy" khi append lead vào Google Sheet

> Trạng thái: **AUDIT — chưa sửa code.** File này là **tài liệu hỗ trợ**: ghi lại tình trạng lỗi,
> dữ kiện đã kiểm chứng và các phán đoán/phương án đã nghĩ tới. Đây **không phải chỉ thị** cho agent
> nào nhận task này — mọi giả thuyết, mức độ ưu tiên và cách làm ở mục 5–6 là gợi ý tham khảo; agent
> nhận việc tự đo lại từ đầu (đọc log, version history, tái hiện bug...) trước khi quyết định hướng
> xử lý, có quyền bác bỏ hoặc đổi hướng nếu dữ kiện mới không khớp với audit này.

## 1. Tình trạng lỗi (triệu chứng quan sát được)

- Sheet khách: *Thaco CV_Raw Lead* —
  `https://docs.google.com/spreadsheets/d/17_8hXkuEtSPQITpvoAj8N2WRRzxvNuXMhYwt4PfaE2E/edit?gid=0`
- Header tab (A→M): `Date, Name, Phone, Phiên bản, Nhu cầu, Khu vực, Địa chỉ, Showroom, Form Name,
  Form ID, Leadgen ID, Time, Additinal data`.
- Các dòng cũ (vd. dòng 3636–3657) ghi đúng, đủ 13 cột.
- Dòng mới (từ dòng **3658**, ngày 26/10/2026) bị ghi **lệch hẳn sang phải**: dữ liệu đáng lẽ nằm ở
  cột A–E (Date, Name, Phone, …) lại rơi vào cột **I–M** (đúng vị trí của "Form Name, Form ID,
  Leadgen ID, Time, Additinal data"), còn A–H trống trơn.
- Người dùng mô tả: lỗi này **hiếm gặp**, **đã từng xảy ra vài năm trước** (trước khi kernel có các
  bản vá cải tiến ghi Sheet), và **lần nào cũng gắn với việc có ai đó chỉnh sửa cột** trên sheet —
  nhưng không nhớ chính xác kiểu chỉnh sửa nào gây ra.

## 2. Phạm vi đã audit (không sửa code)

Đã xác nhận lỗi **nằm trong kernel này**, không phải do Google Sheets hay Apps Script (repo không
có file `.gs`/Apps Script nào):

- `helpers/helpers.php::apps_google_sheet()` — hàm ghi lead vào Sheet, dòng ~450–1020.
- `helpers/helpers.php::apps_gsheet_header_row()` — đọc header tươi (chỉ dòng 1), dòng ~395–449.
- Khối "sắp cột trước khi `append()`", dòng ~898–938 — đây là nơi nghi vấn chính cho đợt lỗi hiện
  tại (xem mục 3).
- Dependency ghi Sheet thật (`Sheets` facade, gói `revolution/laravel-google-sheets`) **không nằm
  trong repo kernel này** — nó thuộc app tiêu thụ kernel (app pull.vn). Kernel chỉ gọi qua facade.

### Lịch sử liên quan (từ `git log -- helpers/helpers.php` và `CHANGELOG.md`)

| Commit | Ngày (theo message) | Thay đổi |
|---|---|---|
| `82f7561`/`fcccb52`/... (OP#3550-3556) | nhiều năm trước | Đổi qua lại giữa `append()` và `values.update A{last+1}` để chống mất lead do Filter/ẩn — **không liên quan trực tiếp tới lệch CỘT**, chủ yếu là mất/đè DÒNG. |
| `b303f82` | trước 28/09/2026 | Revert hẳn về `append(INSERT_ROWS)` — chốt "không tính dòng cuối thủ công nữa". |
| `0074a20` | 27/09/2026 | Thêm `apps_gsheet_header_row()` — đọc header chỉ 1 dòng thay vì tải cả tab (chữa timeout 60s/tab 41.000 dòng). |
| **`13058ea`** | **28/09/2026** | **Thêm khối "sắp cột trước khi append"** — `array_replace(array_flip($headers), $values)`. Đây là lần đầu tiên kernel tự sắp thứ tự cột trước khi gọi `append()`, thay vì để gói Sheets tự lo. |
| `417457d`, `2ea8dff`, `a7f8191` | 28/09 → 14/10/2026 | Thêm cache 300s + LKG (last-known-good) 7 ngày cho header, vì đọc tươi có thể timeout/quota. Tự nhận trong comment: dùng header cache/LKG có thể làm "dòng ghi lệch cột, và lệch **im lặng**". |

→ Cơ chế **tự sắp cột bằng `array_replace`** là thứ **mới có từ 28/09/2026** — tức là *sau* thời
điểm "nhiều năm trước" mà người dùng nhắc tới. Vậy nhiều khả năng có **hai lớp nguyên nhân khác
nhau nhưng cùng một họ bug** (xem mục 3): một lớp cũ hơn (liên quan hành vi "tự dò bảng" vốn có của
Google Sheets API `append()`), và một lớp mới (do chính cache header của kernel, từ 28/09/2026).

## 3. Phán đoán nguyên nhân — cơ chế kỹ thuật

### 3.1. Vì sao `append()` có thể đặt dữ liệu lệch cột (bản chất gốc, không đổi qua các năm)

Khi gọi `Sheets::...->append([$values])` **không** truyền range cột tường minh (A1:M), Google Sheets
API (`spreadsheets.values.append`) tự "dò bảng": tìm vùng dữ liệu hiện có trong phạm vi được chỉ
định (ở đây là cả sheet) để quyết định **chèn vào cột nào, dòng nào**. Đây là hành vi **cố ý giữ
nguyên** — xem comment "ĐỪNG SỬA KHỐI GHI NÀY" dòng ~882–897: gói từng đổi qua `values.update
A{last+1}` (tự tính dòng) rồi phải revert vì gây mất-lead-im-lặng nặng hơn. Tức là: đội đã **chấp
nhận đánh đổi** "để Google tự dò bảng" đổi lấy việc không bao giờ mất lead do đua ghi (race).

Cái giá của đánh đổi này: nếu vùng dữ liệu trong sheet có một đặc điểm khiến Google nhận diện "bảng"
sai ranh giới cột, `append()` sẽ chèn lệch — và vì **không có bước nào phía kernel verify lại đúng
dòng vừa ghi có đúng cột hay không**, lỗi trôi qua im lặng, HTTP vẫn 200.

**Ứng viên chỉnh sửa cột gây lệch ranh giới bảng** (đều hợp lý về mặt API, xếp theo khả năng cao →
thấp dựa trên triệu chứng "lệch hẳn sang phải, kiểu bước nhảy"):

1. **Dán ("paste") hoặc gõ tay một dòng dữ liệu ở cuối sheet nhưng bắt đầu từ cột I trở đi** (để
   trống A–H) — ví dụ ai đó test nhập liệu, hoặc copy/paste nhầm một đoạn dữ liệu chỉ có 5 cột vào
   hàng cuối. Một dòng như vậy **tự nó trở thành "mỏ neo" bảng mới** ở mắt Google: lần `append()`
   kế tiếp sẽ bám theo vùng I–M đó thay vì A–M. → Khớp triệu chứng nhất, và khớp với chính ảnh chụp
   màn hình (dòng 3658 đang được gõ tay, cell I3658 đang active — rất có thể đây chính là hành động
   kiểm thử/debug gây mỏ neo lệch, hoặc là biểu hiện của lỗi đang diễn ra).
2. **Ẩn cột (hide columns) A–H**, hoặc **đóng băng (freeze) đến cột I** — một số cách triển khai
   "dò bảng" phía Google có thể bỏ qua vùng bị ẩn khi xác định biên trái của bảng (tương tự như
   CHANGELOG đã ghi nhận việc Filter/ẩn **dòng** gây mất dữ liệu — rất có khả năng ẩn **cột** gây
   hiệu ứng tương tự nhưng theo chiều ngang).
3. **Chèn một cột trống ở vị trí A** (không xoá, chỉ chèn thêm) rồi xoá đi ngay sau — nếu việc ghi
   lead xảy ra đúng lúc cột A đang trống/-đang có thao tác chỉnh sửa, Google có thể tạm thời coi A
   là "ngoài bảng".
4. **Xoá nội dung ô A1 (tên header) nhưng để nguyên dữ liệu bên dưới** — dò bảng thường neo theo
   hàng header; mất chữ ở A1 có thể khiến Google không coi cột A là điểm bắt đầu bảng nữa.

### 3.2. Lớp nguyên nhân MỚI (từ 28/09/2026) — khớp đúng cơ chế code hiện tại

Khối sắp cột (dòng ~931–938):

```php
$ordered = array_values(array_replace(array_flip($headers), $values));
```

- `$headers` lấy từ: đọc tươi → nếu lỗi thì cache 300s → nếu vẫn rỗng thì LKG (last-known-good)
  **tối đa 7 ngày tuổi**.
- `$values` được ghép key bằng **đúng chữ header hiện tại** (cho field khớp header) **cộng thêm**
  key từ `$values_mappings` (mapping_key cấu hình riêng, VD `address`, `phone` — chưa chắc trùng
  chữ với tên cột thật).
- `array_replace()` chỉ thay đúng vị trí khi **khớp key tuyệt đối**. Key nào không khớp bị **nối
  vào CUỐI mảng** thay vì bị bỏ hoặc báo lỗi.

**Trường hợp cụ thể gây lệch, gắn với "chỉnh sửa cột":**

- **Đổi tên 2 cột trùng nhau** (hoặc để trống tên ≥2 cột khi chèn cột mới không đặt tên) →
  `array_flip($headers)` dùng *giá trị* (tên cột) làm khoá, nên **tên trùng bị đè, chỉ giữ lại cột
  cuối cùng trùng tên** → một cột "biến mất" khỏi bảng ánh xạ → mọi cột phía sau lệch một bậc.
- **Đổi tên 1 cột trong vòng 300 giây** (hoặc tới 7 ngày nếu đang rơi vào nhánh LKG do Google đang
  chậm/lỗi) **ngay trước/trong lúc** một lead được ghi → header dùng để sắp cột (từ cache/LKG) là
  tên **CŨ**, trong khi key dữ liệu build theo tên **MỚI** (đọc tươi ở một request khác, hoặc do
  code vẫn dùng tên cũ cached nhất quán — tuỳ thời điểm) → mismatch → cột rơi ra cuối mảng.
- **Chèn cột mới ở giữa bảng** đúng lúc hệ thống đang dùng header cache/LKG cũ (không có cột mới):
  `$headers` (cache) có N cột, sheet thật có N+1 cột → `array_flip` lệch index ngay từ cột chèn
  trở về sau.
- **Xoá một cột ở giữa bảng**: tương tự, header cache vẫn "nhớ" cột đã xoá → lệch 1 bậc từ đó trở đi.

**Vì sao "vô hạn" / lệch tiếp tục dồn:** `$values` sau sắp cột là **mảng tuần tự, không khoá**, đưa
thẳng vào `append()` → `append()` lại tự dò bảng theo đúng cơ chế mục 3.1. Một khi **một dòng bị ghi
sai hình dạng/sai vị trí** (ít phần tử hơn số cột thật, hoặc dồn về cuối), nó **thay đổi luôn vùng
mà Google coi là "bảng"** cho các lần ghi kế tiếp — dòng sau cứ bám theo vùng đã lệch, lệch chồng
lệch, không tự phục hồi cho tới khi có ai sửa tay trên sheet. Đây khớp với từ "nhảy cột vô hạn" người
dùng mô tả: không phải một lần lệch cố định, mà là **lệch tích luỹ qua từng lượt ghi**.

### 3.3. Vì sao lỗi "hiếm"

Theo đúng logic ở 3.2, lỗi chỉ xảy ra khi **hội đủ 2 điều kiện cùng lúc**:
1. Có người chỉnh cột (đổi tên / trùng tên / chèn / xoá / để trống tên).
2. Đúng lúc đó (hoặc trong cửa sổ cache 300s, hoặc hiếm hơn là cửa sổ LKG 7 ngày nếu Google đang có
   sự cố đọc) có một lead được ghi vào.

Hai điều kiện trùng nhau là hiếm — khớp với "hiếm khi bị lỗi này".

## 4. Những gì **chưa xác nhận được** (giới hạn của audit này)

- Chưa xem được lịch sử chỉnh sửa thật của sheet khách (Google Sheets Version History) tại các thời
  điểm xảy ra lỗi (cả lần hiện tại lẫn "vài năm trước") — nên **không khẳng định chắc chắn** kiểu
  chỉnh sửa cột nào đã thực sự xảy ra, chỉ liệt kê các ứng viên khớp với cơ chế code.
- Chưa có access vào log sản xuất (`apps_log('daily')`) quanh thời điểm dòng 3658 được ghi, để xem
  dòng đó đi qua nhánh "đọc tươi / cache / LKG" nào — log này sẽ xác nhận trực tiếp giả thuyết 3.2.
- Không rõ dòng 3658 trong ảnh chụp là **do hệ thống tự ghi qua webhook**, hay là **người dùng đang
  gõ tay để thử nghiệm/tái hiện lỗi** (cell I3658 đang active trong ảnh) — nếu là gõ tay thì không
  liên quan tới `apps_google_sheet()` của kernel, cần phân biệt rõ trước khi kết luận.
- `apps_get_worksheet_column_titles()` (dòng 202–232, helpers.php) có logic bỏ qua cột có header
  rỗng hoặc bắt đầu bằng `_`, và dedup cột trùng tên bằng hậu tố `_2`, `_3` — nhưng hàm này **không
  còn được gọi ở đâu trong code hiện tại** (xác nhận bằng grep toàn repo) → là code chết, không ảnh
  hưởng tới đường ghi hiện hành (`apps_gsheet_header_row`), nhưng cho thấy bản thân đội dev *đã từng
  biết* "cột trùng tên / cột rỗng tên" là nguồn lỗi cần xử lý, ở một thế hệ code cũ hơn — khả năng
  liên quan tới ký ức "vài năm trước" của người dùng.

## 5. Các hướng xử lý đã nghĩ tới (tham khảo — agent nhận việc tự đánh giá lại)

Đây là những phương án đã nghĩ ra trong lúc audit, **không phải danh sách việc phải làm theo thứ
tự**. Agent nhận task tự cân nhắc dữ kiện mới (log, version history...) để chọn hướng, có thể bỏ
qua hoặc gộp khác đi. Ghi lại kèm mức độ rủi ro/đụng chạm để tham khảo khi cân nhắc:

- **Log chẩn đoán có chủ đích** (không đổi hành vi ghi): khả năng log lại `$headers` dùng để sắp
  cột (và nguồn: tươi/cache/LKG) cùng `$values` SAU khi sắp, ngay trước lời gọi `append()`. Rủi ro
  thấp — chỉ thêm log, không đổi luồng ghi. Có thể hữu ích để có bằng chứng trực tiếp cho giả thuyết
  3.2 nếu lỗi tái diễn, thay vì suy đoán — nhưng agent tự đánh giá có cần không.
- **Validate trước khi ghi**: một khả năng là nếu số phần tử của `$values` sau sắp cột ít hơn hẳn số
  lượng `$headers`, hoặc có key trong `$values` gốc không khớp phần tử nào trong `$headers` (bị
  `array_replace` dồn ra cuối) — có thể đáng cảnh báo hoặc chặn ghi, tương tự case `$headers` rỗng
  hoàn toàn đã có sẵn (dòng ~955–965). Mức độ nên "chỉ log" hay "chặn ghi" là quyết định cần cân
  nhắc đánh đổi (giống các quyết định trước đó trong lịch sử file này), không mặc định.
- **Phát hiện header trùng tên trước `array_flip`**: `count($headers) !== count(array_unique($headers))`
  có thể là một tín hiệu đáng log — đúng loại lỗi do con người chỉnh sheet gây ra, phát hiện được mà
  không cần đổi cách ghi.
- **Đụng tới khối ghi `append()` (đổi sang truyền range cột tường minh thay vì để Google tự dò
  bảng)**: đây là vùng code có cảnh báo tường minh trong chính file "ĐỪNG SỬA" (mục 3.1) — từng thử
  hướng tương tự (`values.update A{last+1}`) và bị revert vì gây sự cố nặng hơn. Nếu agent cân nhắc
  hướng này, nên đọc kỹ lịch sử ở mục 2 và tự xác minh rủi ro collision/mất-lead trước, không suy ra
  từ audit này là "được phép làm".

## 6. Những việc có thể giúp xác minh thêm (tham khảo, không phải checklist bắt buộc)

Audit này dừng ở phán đoán vì thiếu vài dữ kiện (mục 4). Nếu agent nhận task có khả năng tiếp cận,
các việc sau có thể giúp xác nhận hoặc bác bỏ giả thuyết ở mục 3 — nhưng agent tự quyết định có cần,
theo thứ tự nào, và còn thiếu gì khác mà audit này chưa nghĩ tới:

- Version History của sheet khách quanh thời điểm dòng 3658, để biết thực tế ai/sửa gì trên cột.
- Log `daily` quanh thời điểm ghi dòng 3658 (tìm theo `[GSheet]` / `apps_google_sheet`).
- Xác nhận dòng 3658 là ghi qua webhook hay nhập tay (ảnh chụp cho thấy cell I3658 đang active khi
  chụp, chưa rõ là do hệ thống hay người dùng đang gõ).
- Một test tái hiện (header cache có N cột, sheet thật N+1 cột do chèn cột giữa) có thể khoá lại
  hành vi hiện tại trước khi sửa bất cứ gì — nhưng cách viết, phạm vi test là quyết định của agent
  thực hiện, không phải chỉ định sẵn ở đây.

---
*File do Claude tạo trong phiên audit theo yêu cầu người dùng — tổng hợp lại toàn bộ phần trao đổi,
không có thay đổi nào tới code sản xuất trong phiên này. Nội dung mục 5–6 là tài liệu hỗ trợ/tham
khảo cho agent nhận việc, không phải chỉ thị — agent tự đo lại và tự quyết định cách làm.*

---

## 7. KẾT QUẢ ĐO 27/10/2026 — ĐÃ TÌM RA GỐC RỄ VÀ ĐÃ SỬA

> Phần này do phiên điều tra sau audit bổ sung; **thay thế** các phán đoán ở mục 3.2 (xem 7.3).

### 7.1 Dữ kiện đo được
- DB (`leads.options.pushers.leadgen_notification_spreadsheet.data.updates.updatedRange`): lead Thaco ghi đúng
  `A…:M…` tới dòng 3604 (22/10 07:39). Lead 3605 (22/10 12:38) ghi `I3605:U3605`, các lead sau `Q`, `Y`, `AG`…
  — **mỗi lượt +8 cột**, tới `CK3668:CW3668`. 66 lead lệch. Lệch **bắt đầu 22/10, không phải dòng 3658**.
- Lịch sử phiên bản (Drive revisions, so từng ô): bản 4434 (22/10 00:39 UTC) có **0 dòng trống**; bản 4478
  (02:52 UTC, người sửa tay) có **14 dòng trống hoàn toàn** (3511, 3516, 3520, 3530, 3531, 3556, 3558, 3565,
  3567, 3569, 3572, 3574, 3576, 3580) — xoá NỘI DUNG dòng, không xoá dòng. Cấu trúc tab (cột, lọc, validation)
  không đổi. Cột G/H/M trống toàn bộ (chỉ có tiêu đề).
- Mọi lượt ghi dùng chính xác `append($sheet)` của gói: range = tên tab, `OVERWRITE`.

### 7.2 Tái hiện (sheet thử dữ liệu giả, cùng hình dạng)
| Kịch bản | 3 lượt ghi liên tiếp |
|---|---|
| Không dòng trống, range = tên tab | A3605, A3606, A3607 |
| 14 dòng trống, range = tên tab (cách cũ) | **I3605, Q3606, Y3607** (đúng như production) |
| 14 dòng trống, `INSERT_ROWS` | I, Q, Y (đổi insertDataOption không cứu) |
| 14 dòng trống, `A:M` | I, I, I |
| 14 dòng trống, `A1` / `A1:M1` | ghi vào chính các dòng trống 3511, 3516… |
| 14 dòng trống, **`'Tab'!A:A`** | **A3605, A3606, A3607** |
Thêm: xoá cả dòng cuối, rác sẵn bên phải, ghi liên tiếp — `A:A` đều đúng, ghi mặc định lệch. Dòng ẩn: cả hai
cách như nhau (lỗi cũ đã chấp nhận). Qua chính `apps_google_sheet()`: trước sửa I,Q,Y; sau sửa A,A,A.

### 7.3 Đính chính audit
- Mục 3.2 (cache/LKG header, `array_replace` lệch thứ tự) **KHÔNG phải nguyên nhân**: thứ tự cột trong từng
  dòng luôn đúng, chỉ cột BẮT ĐẦU lệch; log 26/10 không có cảnh báo đọc header của Thaco.
- Mục 3.1 đúng hướng; ứng viên "dán dòng từ cột I" sai, ứng viên đúng là **xoá nội dung dòng giữa bảng**.
- Quy tắc cho khách: xoá dòng bằng "Delete row", không xoá nội dung để lại dòng trống.

### 7.4 Sửa
`'Tab'!A:A` + log phát hiện lệch + dọn range dính — xem CHANGELOG `[Unreleased]`. Chưa có lead Thaco thật nào đi
qua code mới tại thời điểm viết; lead đầu tiên sau 27/10 13:43 phải ghi `A…:M…` (kiểm bằng `updatedRange`).
