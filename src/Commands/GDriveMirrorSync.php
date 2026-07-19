<?php

/**
 * © 2026 VISUAL WEBER COMPANY LIMITED. All rights reserved.
 * Proprietary software developed and distributed by Visual Weber.
 * Use is permitted only under a valid license agreement.
 *
 * © 2026 CÔNG TY TNHH VISUAL WEBER. Bảo lưu mọi quyền.
 * Phần mềm độc quyền của Visual Weber, chỉ được sử dụng theo Hợp đồng cấp phép.
 */

/**
 * ============================================================
 *  GDriveMirrorSync — Hướng dẫn sử dụng
 * ============================================================
 *
 * CHỨC NĂNG
 * ---------
 *  Đồng bộ một chiều: Google Drive → Local.
 *  - Delta sync: chỉ tải file mới/thay đổi (so sánh MD5 trước, fallback timestamp).
 *  - Tự động convert Google Docs/Sheets/Slides → .docx / .xlsx / .pptx.
 *  - Stream download (không load toàn bộ file vào RAM).
 *  - Retry tự động với exponential backoff khi lỗi mạng.
 *  - Xử lý trùng tên file (name collision) bằng cách thêm Drive ID.
 *  - KHÔNG xoá file local — chỉ thêm/cập nhật.
 *
 * ============================================================
 *  CẤU HÌNH XÁC THỰC — chọn 1 trong 2 cách
 * ============================================================
 *
 *  GOOGLE_DRIVE_ENABLED=true     (mặc định bật; chỉ set =false để tạm tắt)
 *
 *  Thứ tự ưu tiên: Nếu có service account JSON → dùng Cách A. Không có → fallback Cách B.
 *
 *  ─────────────────────────────────────────────────────────────
 *  Cách A — Service Account (KHUYẾN NGHỊ — nhanh, ổn định, không hết hạn)
 *  ─────────────────────────────────────────────────────────────
 *    1) Tạo Service Account tại Google Cloud Console:
 *         https://console.cloud.google.com/iam-admin/serviceaccounts
 *       → Create service account → Add key (JSON) → tải file JSON về.
 *    2) Đặt file JSON vào dự án:
 *         config/google-service-account-credentials.json
 *       (Hoặc set env GOOGLE_SERVICE_ACCOUNT_JSON_LOCATION=/absolute/path.json
 *        nếu muốn để file ngoài project.)
 *    3) Vào Google Drive (web) → chuột phải folder cần sync → Share →
 *       paste EMAIL của service account (dạng xxx@<project>.iam.gserviceaccount.com,
 *       xem trong file JSON ở key "client_email") → quyền Viewer → bỏ tick "Notify".
 *
 *  ⚠️ ĐIỀU KIỆN BẮT BUỘC khi dùng Cách A:
 *    - Service account KHÔNG có "My Drive". Nó chỉ thấy folder/file đã được SHARE
 *      trực tiếp cho email của nó (hoặc nằm trong Shared Drive mà nó là member).
 *    - Ở mode Service Account, lệnh chỉ chấp nhận **Folder ID** (KHÔNG dùng được path
 *      kiểu "Tên Folder/Tên Con"). Identifier dạng path sẽ bị skip với cảnh báo.
 *
 *  Khi nào Cách A KHÔNG dùng được (phải dùng Cách B):
 *    - Folder thuộc My Drive cá nhân và không thể share cho service account.
 *    - Domain Google Workspace cấm share file ra ngoài tổ chức (cấm share cho SA).
 *
 *  ─────────────────────────────────────────────────────────────
 *  Cách B — OAuth2 + Refresh Token (legacy, fallback tự động)
 *  ─────────────────────────────────────────────────────────────
 *    Dùng khi không có service account JSON. Đặt 3 env sau:
 *      GOOGLE_DRIVE_CLIENT_ID=<OAuth2 Client ID>
 *      GOOGLE_DRIVE_CLIENT_SECRET=<OAuth2 Client Secret>
 *      GOOGLE_DRIVE_REFRESH_TOKEN=<Refresh Token>
 *
 *    Hoặc dùng setting trong DB (ưu tiên hơn env):
 *      social_login_google_app_id
 *      social_login_google_app_secret
 *      social_login_google_drive_refresh_token
 *
 *    Cách lấy nhanh refresh token: OAuth Playground
 *      https://developers.google.com/oauthplayground
 *      (Cài Client ID/Secret riêng, scope https://www.googleapis.com/auth/drive)
 *
 *    Hướng dẫn chi tiết (tutorial bên thứ 3):
 *      - Client ID & Secret : https://github.com/ivanvermeyen/laravel-google-drive-demo/blob/master/README/1-getting-your-dlient-id-and-secret.md
 *      - Refresh Token      : https://github.com/ivanvermeyen/laravel-google-drive-demo/blob/master/README/2-getting-your-refresh-token.md
 *      - Root Folder ID     : https://github.com/ivanvermeyen/laravel-google-drive-demo/blob/master/README/3-getting-your-root-folder-id.md
 *
 *    Ở mode OAuth2, identifier có thể là Folder ID HOẶC path kiểu "Tên Folder/Tên Con"
 *    (vì refresh token gắn với user nên có thể tra cứu My Drive theo tên).
 *
 * ============================================================
 *  CÁCH LẤY FOLDER ID / PATH
 * ============================================================
 *
 *  Cách 1 — Folder ID (từ URL trình duyệt) — DÙNG ĐƯỢC CHO CẢ A và B:
 *    Mở folder trên drive.google.com → URL có dạng:
 *    https://drive.google.com/drive/folders/0Bw6yYZTQJcm3aGoxTGJuY1p1ZU0
 *                                           ^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^
 *    Phần sau "/folders/" chính là Folder ID.
 *
 *  Cách 2 — Đường dẫn tương đối (CHỈ dùng được ở mode OAuth2 — Cách B):
 *    Dùng cú pháp "Tên Folder Cha/Tên Folder Con"
 *    Ví dụ: "Báo cáo 2025/Tháng 1"
 *    → Ở mode Service Account, dùng path sẽ bị skip vì SA không thấy My Drive.
 *
 * ============================================================
 *  CÚ PHÁP LỆNH
 * ============================================================
 *
 *  php artisan gdrive:mirror:sync {folder_id_hoặc_path...}
 *      [--path=<local_dir>]         Thư mục đích local (mặc định: storage/app/google_drive_mirror)
 *      [--retry=<n>]                Số lần retry khi lỗi mạng (mặc định: 3, tối đa nên dùng: 10)
 *      [--force]                    Tải lại tất cả file dù đã tồn tại (bỏ qua delta check)
 *      [--dry-run]                  Chỉ liệt kê 20 item đầu (debug), KHÔNG tải
 *      [--limit=<n>]                Chỉ xử lý N item đầu tiên (test)
 *      [--retry-failed=<json>]      Re-run chỉ các file lỗi từ JSON report của lần chạy trước
 *                                    (mặc định BỎ QUA item lỗi permanent — 403 vĩnh viễn)
 *      [--include-permanent]        Dùng cùng --retry-failed: nạp CẢ item permanent (bình thường
 *                                    bị bỏ qua vì retry vô ích, vd exportSizeLimitExceeded)
 *
 *  ── KHI FILE LOCAL ĐÃ TỒN TẠI (skip vs re-download)
 *
 *     1) Collision check (trong cùng 1 lần sync):
 *        Nếu 2 file Drive khác ID nhưng cùng tên cùng folder → file thứ 2 thêm hậu tố
 *        ID 8 ký tự đầu (vd: "file.pdf" → "file_1JP7CIBW.pdf"). KHÔNG so với file đã có
 *        sẵn từ run trước — chỉ track trong session hiện tại.
 *
 *     2) Delta check (quyết định skip hay tải lại):
 *          --force                                       → tải lại (đè).
 *          Regular file & size(local) ≠ size(remote)     → tải lại (đè) — bắt truncated.
 *          Có MD5 (file thường):
 *            md5(local) == remote                        → SKIP ✋
 *            md5 khác                                    → tải lại (đè) — file đã đổi.
 *          Không có MD5 (Google Native: Docs/Sheets/Slides):
 *            mtime(local) >= mtime(remote)               → SKIP ✋
 *            mtime(local) <  mtime(remote)               → tải lại (đè) — Drive mới hơn.
 *        Sau khi tải thành công: touch(local) = mtime(remote) để lần sau so chính xác.
 *
 *     3) Cơ chế ghi đè — ATOMIC write-then-rename (đổi 2026-07-19, khớp bản Go):
 *        • MỌI đường ghi nội dung (Google Native export, regular file stream, service-account
 *          API stream — xem atomicPutContents()/atomicWriteStream()) đều ghi ra 1 file TẠM
 *          trong CÙNG THƯ MỤC với file đích trước, rồi rename() đè vào path thật CHỈ KHI tải
 *          xong TRỌN VẸN không lỗi.
 *        • Tên file tạm có ĐỘ DÀI CỐ ĐỊNH, KHÔNG nối vào tên đích — dạng
 *          ".<8 hex đầu sha256(targetPath)>-<8 hex run id>.tmp" (xem atomicTempPath()). Không
 *          ăn vào ngân sách 255 byte/component mà truncateNameComponent() quản lý cho tên đích
 *          (khác lược đồ cũ kiểu "tên.tmp-runId" từng làm được — mirror đúng finding L1 bản Go).
 *        • Lỗi bất kỳ lúc nào giữa chừng (mở file tạm / ghi / rename) → file tạm bị xoá ngay,
 *          file ĐÍCH CŨ giữ nguyên KHÔNG bị đụng tới.
 *        • VÌ SAO ĐỔI khỏi "ghi thẳng, KHÔNG tạo .bak/.tmp" (hành vi cũ): ghi thẳng khiến
 *          crash/mất mạng/rút ổ giữa chừng để lộ NGAY 1 file CỤT tại path thật cho tới lần sync
 *          kế tiếp mới tự chữa (self-heal, xem mục 4) — trong lúc đó người dùng thấy 1 file
 *          trông như file thật nhưng hỏng. Atomic write-then-rename loại bỏ hẳn khoảng hở này:
 *          path thật LUÔN LÀ hoặc bản CŨ đầy đủ, hoặc bản MỚI đầy đủ, không bao giờ dở dang.
 *        • File tạm sót lại từ 1 run bị crash/kill giữa chừng được quét dọn tự động ở đầu run
 *          kế tiếp — CHỈ file khớp đúng mẫu tên tạm ở trên VÀ đủ cũ (xem
 *          cleanupStaleAtomicTempFiles()); KHÔNG BAO GIỜ đụng file nào khác của người dùng.
 *        • Permission/owner của file local: file MỚI (sau rename) mang permission mặc định lúc
 *          tạo file tạm — khác hành vi truncate-in-place cũ (giữ nguyên inode/permission cũ).
 *          Đánh đổi chấp nhận được để có atomic write; bản Go có cùng đánh đổi này.
 *
 *     4) An toàn khi crash giữa chừng (ATOMIC — xem mục 3):
 *        Crash/mất mạng/rút ổ giữa chừng chỉ để lại 1 file TẠM (.tmp) cạnh file đích — file đích
 *        tại path thật KHÔNG BAO GIỜ bị lộ ở trạng thái dở dang (khác hẳn hành vi ghi-thẳng cũ).
 *        File tạm mồ côi được tool tự dọn ở đầu lần chạy kế tiếp (cleanupStaleAtomicTempFiles()).
 *        Nếu file đích trước đó CHƯA từng tồn tại (lần tải đầu) → đơn giản không có file nào ở
 *        path đó cho tới khi 1 lần chạy thành công trọn vẹn. Self-heal vẫn đúng như trước: lần
 *        sync kế tiếp coi như chưa tải, tải lại từ đầu.
 *
 *     5) Các tình huống đặc biệt:
 *        • Local file người dùng đã sửa, Drive không đổi  → SKIP (giữ bản sửa).
 *        • Local file người dùng đã sửa, Drive cũng đổi   → bản sửa local BỊ ĐÈ.
 *        • File Drive bị đổi tên → tạo file mới ở path mới; bản cũ ở path cũ KHÔNG bị xoá.
 *        • Local có file mà Drive không có  → giữ nguyên (command chỉ ADD/UPDATE,
 *          không DELETE — xem cleanup "removed for safety" trong handle()).
 *        • Đổi --path giữa các lần           → path mới sync từ đầu, path cũ nguyên vẹn.
 *
 *     6) Lỗi filesystem CỤC BỘ (ổ đích hỏng/unmount/read-only/đầy dung lượng) — KHÁC lỗi
 *        Drive API, xử lý riêng (kèm log thật storage/logs/pull.vn-2026-07-17.log):
 *        • Khâu delta-check (md5_file()/filesize()/lastModified() đọc file local) được bọc
 *          try/catch: lỗi đọc (vd ổ exFAT ngoài rớt kết nối giữa chừng — "errno=5 Input/output
 *          error") KHÔNG làm fatal cả run, chỉ coi như "không verify được", rơi xuống tải lại
 *          (self-heal, giống mục 4 ở trên).
 *        • Trong retry loop (withRetry()): lỗi filesystem cục bộ (mkdir/fopen/md5_file() thất
 *          bại, "Input/output error", "read-only file system", "no space left"…) KHÔNG được
 *          retry (retry vô ích vì ổ vẫn hỏng ở lần thử kế tiếp) — fail ngay, khác lỗi Drive API
 *          (rate-limit/5xx) vẫn retry bình thường.
 *        • Circuit breaker: 20 lỗi filesystem cục bộ LIÊN TIẾP (không có item thành công xen
 *          giữa) → ABORT TOÀN BỘ run (không chỉ 1 file/1 folder) — ổ đích gần như chắc chắn đã
 *          unmount/read-only/hỏng, mirror tiếp chỉ phí thời gian. Vẫn in report + ghi log/JSON
 *          report bình thường trước khi thoát.
 *        • Preflight: đầu handle() (trước khi list Drive), kiểm tra thư mục đích tồn tại/tạo
 *          được + ghi được — fail sớm thay vì cày liệt kê Drive xong mới phát hiện đích hỏng.
 *          Bỏ qua preflight khi --dry-run (không ghi gì ra đích).
 *
 *  ── BÁO CÁO FILE LỖI
 *     Sau mỗi lần chạy, nếu có file lỗi:
 *       • Console: in group theo loại lỗi (Permission / Network / Quota / Export Limit / …)
 *       • Log:    storage/logs/pull.vn-YYYY-MM-DD.log (channel "daily")
 *       • JSON:   storage/app/gdrive-sync/failed/failed-<folderTag>-<YYYYmmdd-HHMMSS>.json
 *                 → file này có đủ meta để re-run qua --retry-failed. Mỗi item được phân loại
 *                 'permanent' (lỗi 403 vĩnh viễn — vd exportSizeLimitExceeded/cannotExportFile,
 *                 KHÔNG retry vì retry vô ích/tốn thời gian) hoặc retryable (lỗi tạm thời —
 *                 network/5xx/quota). Chỉ giữ 10 report gần nhất trong thư mục failed/ (tự động
 *                 xoá report cũ hơn — tránh tích luỹ vô hạn).
 *       • Manifest: storage/app/gdrive-sync/unexportable/<folderTag>.md — bản MỚI NHẤT của
 *                 riêng folder đó (key theo folderTag, GHI ĐÈ mỗi lần chạy — KHÔNG archive theo
 *                 timestamp như failed/ ở trên). Chỉ liệt kê item 'permanent' (lỗi KHÔNG BAO GIỜ
 *                 tự khỏi), nhóm theo lý do, kèm link tải tay + đuôi file gợi ý. File >10MB
 *                 (exportSizeLimitExceeded) PHẢI tải tay qua trình duyệt vì API files.export bị
 *                 Google giới hạn cứng 10MB; file cannotExportFile/fileNotExportable (chủ khoá)
 *                 thì không tải được bằng cách nào, kể cả thủ công. Nếu run sau folder không còn
 *                 permanent-fail (đã vá) → manifest cũ tự bị XOÁ.
 *       • Spreadsheet: storage/app/gdrive-sync/<dmY_His>.xlsx (vd 17072026_231609.xlsx) nếu
 *                 project có OpenSpout/PhpSpreadsheet, ngược lại .csv (dấu `;` + BOM UTF-8 —
 *                 Excel-safe cho locale VN trên macOS). TỰ ĐỘNG xuất mỗi lần chạy có file lỗi,
 *                 KHÔNG cần cờ bật/tắt. Cột: file, path, local_path, folder_root_id, loai,
 *                 google_native, mime_type, size_bytes, size, category, error_reason, permanent,
 *                 attempts, error_message, drive_id, drive_link, run_id, report_time, trung_file,
 *                 dong_dai_dien (2 cột cuối đánh dấu file trùng khi cùng 1 file Drive lặp qua
 *                 nhiều folder gốc lồng nhau — lọc dong_dai_dien=yes để có danh sách file riêng
 *                 biệt). Giữ 10 bản mới nhất (cùng ngưỡng với report JSON ở trên, tự xoá bản cũ
 *                 hơn), lưu ở ROOT thư mục gdrive-sync/ (không phải failed/).
 *
 *  ── RETRY WORKFLOW
 *     php artisan gdrive:mirror:sync \
 *         --retry-failed="storage/app/gdrive-sync/failed/failed-xxxxxxxx-20260528-103000.json" \
 *         --path="/Volumes/WD-DATA1/OneDrive"
 *     (--retry-failed tự skip phần list, chỉ download các item trong JSON.
 *      --path nếu bỏ trống sẽ dùng base_local_path lưu trong JSON.
 *      Mặc định CHỈ nạp item retryable (bỏ qua item 'permanent' — retry vô ích, vd file
 *      >10MB không export được hoặc bị Google lock). Thêm --include-permanent để nạp cả 2 loại.)
 *
 * ============================================================
 *  VÍ DỤ SỬ DỤNG
 * ============================================================
 *
 *  --- Cơ bản ---
 *
 *  # Sync 1 folder theo ID, lưu vào đường dẫn mặc định
 *  php artisan gdrive:mirror:sync 0Bw6yYZTQJcm3aGoxTGJuY1p1ZU0
 *
 *  # Sync 1 folder theo ID, lưu vào ổ cứng ngoài
 *  php artisan gdrive:mirror:sync 0Bw6yYZTQJcm3aGoxTGJuY1p1ZU0 \
 *      --path="/Volumes/WD-DATA1/GDrive-Mirror"
 *
 *  # Sync theo đường dẫn tên folder (không cần ID)
 *  php artisan gdrive:mirror:sync "Báo cáo 2025" \
 *      --path="/home/backup/gdrive"
 *
 *  # Sync folder con theo path lồng nhau
 *  php artisan gdrive:mirror:sync "Projects/Client A/Assets" \
 *      --path="/var/www/storage/assets"
 *
 *  --- Nhiều folder cùng lúc ---
 *
 *  # Sync 2 folder ID cùng lúc vào cùng 1 thư mục đích
 *  php artisan gdrive:mirror:sync \
 *      0Bw6yYZTQJcm3aGoxTGJuY1p1ZU0 \
 *      1Cx7zATRKdm4bHpuYSKvZ2q2AW1 \
 *      --path="/Volumes/WD-DATA1/OneDrive"
 *
 *  # Sync hỗn hợp: 1 folder ID + 1 folder path
 *  php artisan gdrive:mirror:sync \
 *      0Bw6yYZTQJcm3aGoxTGJuY1p1ZU0 \
 *      "Marketing/Creatives" \
 *      --path="/var/www/html/public/files"
 *
 *  --- Retry & Force ---
 *
 *  # Tăng retry khi đường truyền yếu (ví dụ: tải qua 4G)
 *  php artisan gdrive:mirror:sync 0Bw6yYZTQJcm3aGoxTGJuY1p1ZU0 \
 *      --retry=10 \
 *      --path="/Volumes/WD-DATA1/OneDrive"
 *
 *  # Force tải lại toàn bộ (dùng khi nghi ngờ file local bị corrupt)
 *  php artisan gdrive:mirror:sync 0Bw6yYZTQJcm3aGoxTGJuY1p1ZU0 \
 *      --force \
 *      --path="/Volumes/WD-DATA1/OneDrive"
 *
 *  # Force + retry cao (dùng khi cần rebuild toàn bộ mirror sau sự cố)
 *  php artisan gdrive:mirror:sync 0Bw6yYZTQJcm3aGoxTGJuY1p1ZU0 \
 *      --force --retry=10 \
 *      --path="/Volumes/WD-DATA1/OneDrive"
 *
 *  --- Chạy tự động (Cron / Laravel Scheduler) ---
 *
 *  # Thêm vào app/Console/Kernel.php để chạy mỗi giờ:
 *  $schedule->command('gdrive:mirror:sync 0Bw6yYZTQJcm3aGoxTGJuY1p1ZU0 --retry=5 --path=/Volumes/WD-DATA1/OneDrive')
 *           ->hourly()
 *           ->withoutOverlapping()
 *           ->runInBackground();
 *
 *  # Hoặc thêm vào crontab hệ thống (chạy mỗi 30 phút):
 *  *\/30 * * * * php /var/www/html/artisan gdrive:mirror:sync 0Bw6yYZTQJcm3aGoxTGJuY1p1ZU0 \
 *      --path="/Volumes/WD-DATA1/OneDrive" --retry=5 >> /var/log/gdrive-sync.log 2>&1
 *
 * ============================================================
 *  CONVERT TỰ ĐỘNG GOOGLE NATIVE → MICROSOFT OFFICE
 * ============================================================
 *
 *  Google Docs       → .docx  (Word)
 *  Google Sheets     → .xlsx  (Excel)
 *  Google Slides     → .pptx  (PowerPoint)
 *  Google Drawings   → .png
 *  Google Apps Script → .json
 *
 *  File thông thường (PDF, JPG, MP4, ZIP…) được tải nguyên bản.
 *
 *  ⚠️ Google native type NGOÀI danh sách trên (shortcut, Forms, Sites, My Maps, Jamboard…)
 *  KHÔNG tải được qua API:
 *    - application/vnd.google-apps.shortcut → BỊ SKIP CÓ CHỦ ĐÍCH (không phải lỗi): shortcut
 *      chỉ là con trỏ, KHÔNG có nội dung riêng — target thật của nó luôn được Drive liệt kê
 *      như 1 item độc lập trong cùng listing và sync bình thường ở đó.
 *    - Các loại khác (form/site/map/jam...) → báo lỗi permanent 'fileNotDownloadable'
 *      (KHÔNG BAO GIỜ tự khỏi, không retry) và được liệt kê vào manifest
 *      storage/app/gdrive-sync/unexportable/<folderTag>.md.
 *
 * ============================================================
 *  OUTPUT VÀ LOG
 * ============================================================
 *
 *  Sau mỗi lần chạy, lệnh in bảng tổng kết:
 *    📂 Folders Created   — Số thư mục mới được tạo
 *    ✅ Files Updated     — Số file được tải/cập nhật
 *    ⏭  Files Skipped    — Số file không thay đổi (delta skip)
 *    ⚠  Collisions       — Số file trùng tên (đã tự xử lý bằng cách thêm Drive ID)
 *    ❌ Errors            — Số file thất bại kèm lý do
 *
 *  Log chi tiết ghi vào channel "daily" (storage/logs/pull.vn-YYYY-MM-DD.log).
 *  Danh sách file lỗi được log riêng để tiện manual retry. Mỗi lần chạy có 1 run_id
 *  (uniqid ngắn) gắn vào mọi log entry của run đó — kể cả các nhánh skip im lặng trước đây
 *  (0 items, service-account path bị skip, deep-check mimeType lỗi) — để chạy qua cron
 *  vẫn truy vết được thay vì chỉ mất vào console.
 *
 *  Nếu có file permanent-fail (KHÔNG BAO GIỜ mirror được — vd quá 10MB hoặc bị khoá), console
 *  in thêm 1 dòng cảnh báo trỏ tới manifest người-đọc-được:
 *    storage/app/gdrive-sync/unexportable/<folderTag>.md
 *  Khác với JSON report ở trên (archive theo timestamp, phải tự tìm bản mới nhất), manifest
 *  này LUÔN LÀ BẢN MỚI NHẤT của folder (ghi đè mỗi lần chạy, tự xoá khi hết lỗi) — mở lên là
 *  biết ngay hiện đang thiếu file nào, không cần đào JSON. File >10MB (exportSizeLimitExceeded)
 *  là giới hạn CỨNG của API files.export, chỉ tải được bằng tay qua trình duyệt (link kèm sẵn
 *  trong manifest); file bị chủ khoá (cannotExportFile) thì không cách nào tải được, kể cả tay;
 *  file chủ TẮT quyền tải xuống (cannotDownloadFile) gỡ được bằng cách xin chủ bật lại
 *  "Viewers can download" trong setting chia sẻ — manifest ghi rõ để biết đây là việc đi xin
 *  quyền chứ không phải file hỏng.
 *
 *  Nếu ổ đích không ghi được NGAY TỪ ĐẦU (unmount/read-only), preflight sẽ chặn và thoát
 *  Command::FAILURE trước khi list Drive (không tốn thời gian liệt kê). Nếu ổ hỏng GIỮA CHỪNG
 *  (20 lỗi filesystem cục bộ liên tiếp — xem mục 6 phần "KHI FILE LOCAL ĐÃ TỒN TẠI" ở trên),
 *  console in dòng "🛑 ABORT: … lỗi filesystem cục bộ liên tiếp" + Log::error, rồi vẫn in report
 *  tổng kết bình thường (Command::SUCCESS — report phản ánh đúng số đã làm được trước khi abort).
 *
 * ============================================================
 */

namespace Dev\Kernel\Commands;

use Google\Client;
use Google\Service\Drive;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GDriveMirrorSync extends Command
{
    /**
     * The name and signature of the console command.
     * php /Users/eugene/Sites/pull-server/artisan gdrive:mirror:sync 0Bw6yYZTQJcm3aGoxTGJuY1p1ZU0 --path="/Volumes/WD-DATA1/OneDrive" --retry=10 --force
     */
    protected $signature = 'gdrive:mirror:sync
        {folders?* : Google_Folder_ID (omit when using --retry-failed)}
        {--force : Force re-download/overwrite all files from Drive to Local (SAFE: does NOT delete files)}
        {--retry=3 : Number of retries for each file operation on network failure}
        {--path= : Custom local storage path (defaults to storage/app/google_drive_mirror)}
        {--dry-run : List remote items only, do NOT download. Prints first 20 items as a table for debugging}
        {--limit=0 : Only process first N items (0 = all). Useful for testing}
        {--retry-failed= : Path to a failed-report JSON (from a previous run); re-downloads only those items, skipping list/scan}
        {--include-permanent : With --retry-failed, also re-attempt items marked permanent (403 vĩnh viễn — retry vô ích by default, skip)}
        {--allow-shrink : Bỏ qua chặn item-count sụt >20% so lần trước (LISTING_SHRINK_ABORT_RATIO) — chỉ dùng khi biết chắc cây đã thu nhỏ THẬT (user xoá bớt trên Drive), KHÔNG phải nghi ngờ Drive API liệt kê cụt}';

    /**
     * The console command description.
     */
    protected $description = 'Mirror GDrive to Local (Google Docs to MS Office) with Delta Sync, Streaming, and Retries.';

    protected $log_channel = 'daily';
    protected $lastError = null;
    protected int $lastAttempts = 0;
    protected ?string $serviceAccountFile = null;
    protected string $runId = '';

    /**
     * Set trong handle() ngay khi resolve xong — writeUnexportableManifest() cần base path
     * cho header manifest nhưng chữ ký method (đã chốt) không nhận nó làm tham số, nên đi
     * qua property thay vì truyền thêm arg.
     */
    protected string $baseLocalPath = '';

    /**
     * BUG A: đếm số lần listFolderRecursiveViaApi() phát hiện listing lần đầu trả 0 item sai
     * (verify lại có >0) — báo ra ở finalReport() để biết Drive API có đang "cụt" hay không,
     * dù mirror lần này vẫn đủ nhờ đã tự khắc phục.
     */
    protected int $listingRetryHits = 0;

    /**
     * C1-C4 audit (2026-07-19): đếm số lần resolveSiblingNames() phải đổi tên vì đụng anh em cùng
     * cấp (file/folder trùng tên sau sanitize, hoặc case-only clash) — cộng dồn trong 1 folder gốc
     * rồi gộp vào $stats['collisions'] sau khi listing xong (thay cho khối "Handle Name Collisions"
     * cũ trong vòng lặp chính, nay đã chuyển hẳn lên tầng listing — xem fetchFolderChildrenViaApi()).
     */
    protected int $listingCollisions = 0;

    /**
     * Số report JSON tối đa giữ lại trong storage/app/gdrive-sync/failed/ — tự prune report
     * cũ hơn sau mỗi lần ghi để tránh tích luỹ vô hạn (command chạy qua cron định kỳ).
     */
    protected const MAX_FAILED_REPORTS = 10;

    /**
     * BUG A-3: ngưỡng chặn khi item-count của 1 folder gốc sụt so với lần chạy trước. Sụt
     * >20% (còn lại <80% so lần trước) → nghi listing bị cụt (Drive API lỗi mà ta CHƯA biết
     * nguyên nhân) → ABORT folder đó thay vì mirror đè lên 1 cây thiếu dữ liệu.
     */
    protected const LISTING_SHRINK_ABORT_RATIO = 0.8;

    /**
     * BUG D: đếm số lỗi filesystem CỤC BỘ (mkdir/fopen/md5_file.../ổ hỏng-unmount, xem
     * isLocalFsError()) liên tiếp chưa gặp 1 item thành công nào xen giữa — reset về 0 mỗi khi
     * 1 item (mkdir hoặc download) thành công. Dùng làm lưới an toàn thứ 2 (circuit breaker):
     * lỗi Drive API có thể tự khỏi giữa các item, nhưng lỗi ổ đĩa cục bộ (unmount/read-only/hỏng)
     * KHÔNG tự khỏi — nhiều lỗi liên tiếp chứng tỏ ổ đã hỏng chứ không phải "vài file xui".
     */
    protected int $consecutiveLocalFsErrors = 0;

    /**
     * Ngưỡng ABORT toàn bộ run khi $consecutiveLocalFsErrors vượt qua (xem
     * shouldAbortOnLocalFsErrors()). 20 lỗi filesystem cục bộ LIÊN TIẾP = ổ đích gần như chắc
     * chắn đã unmount/read-only/hỏng, không phải 20 file xui — mirror tiếp chỉ phí thời gian
     * (log thật 2026-07-17 run f0547f35: hàng chục file liên tiếp cùng lỗi "mkdir(): Permission
     * denied" trong 2 phút, ổ WD-DATA1 exFAT ngoài).
     */
    protected const MAX_CONSECUTIVE_LOCAL_FS_ERRORS = 20;

    /**
     * Google Native MimeTypes to Microsoft Office (OpenXML) Formats.
     * Đổi từ property sang const (2026-07-19, audit C1-C5/L1-L3): resolveSiblingNames() cần đọc
     * map này từ static context (dedup tên diễn ra ở tầng listing, thuần/không có $this).
     */
    protected const EXPORT_MAP = [
        'application/vnd.google-apps.document'   => ['ext' => 'docx', 'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'application/vnd.google-apps.spreadsheet' => ['ext' => 'xlsx', 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'application/vnd.google-apps.presentation' => ['ext' => 'pptx', 'mime' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation'],
        'application/vnd.google-apps.drawing'      => ['ext' => 'png',  'mime' => 'image/png'],
        'application/vnd.google-apps.script'       => ['ext' => 'json', 'mime' => 'application/vnd.google-apps.script+json'],
    ];

    /**
     * ── Name-safety constants (C1-C5/L1-L3 audit, 2026-07-19) ──────────────────────────────
     * Port 1:1 từ tools/gdrive-mirror-cli/internal/localpath/localpath.go — xem docblock các
     * hàm sanitizeNameComponent()/truncateNameComponent()/safeJoinLocalPath() bên dưới.
     */

    /** Ký tự NTFS từ chối trong 1 path component. Drive cho phép tất cả trong tên file (L2). */
    protected const FORBIDDEN_NAME_CHARS = ['<', '>', ':', '"', '|', '?', '*', '\\', '/'];

    /**
     * Tên thiết bị MS-DOS Windows từ chối làm path component bất kể phần mở rộng — "CON.txt"
     * SAI y hệt "CON" trần (L3).
     */
    protected const WINDOWS_RESERVED_NAMES = [
        'CON', 'PRN', 'AUX', 'NUL',
        'COM1', 'COM2', 'COM3', 'COM4', 'COM5', 'COM6', 'COM7', 'COM8', 'COM9',
        'LPT1', 'LPT2', 'LPT3', 'LPT4', 'LPT5', 'LPT6', 'LPT7', 'LPT8', 'LPT9',
    ];

    /**
     * Giới hạn BYTE (KHÔNG PHẢI ký tự) cho 1 path component — giới hạn cứng của NTFS, không lách
     * được (L1). PHP trên Windows cũng dính MAX_PATH 260 cho FULL path, nhưng đó là non-goal ở
     * đây (giống ghi chú maxComponentBytes bản Go) — chỉ cần đảm bảo TỪNG component ≤255 byte.
     */
    protected const MAX_COMPONENT_BYTES = 255;

    /**
     * Trần độ dài 1 "đuôi" `.xxx` còn được coi là extension thật đáng giữ nguyên khi truncate.
     * Cao hơn hẳn extension thật dài nhất thường gặp (".presentation" = 13 byte), thấp hơn hẳn
     * mức ăn hết ngân sách byte — chặn bẫy `pathinfo()` lấy dấu chấm CUỐI làm "extension" (vd
     * "Bao cao Q1.2026 - <text dài>" → coi cả đuôi dài là extension, giữ nguyên verbatim đẩy kết
     * quả VƯỢT 255 byte — lỗi thật đã bắt được khi port bản Go, xem truncateNameComponent()).
     */
    protected const MAX_EXT_BYTES = 32;

    /**
     * Marker nhận diện lỗi do safeJoinLocalPath() ném ra (path thoát khỏi --path) — isInvalidLocalName()
     * so khớp KHÔNG PHÂN BIỆT hoa/thường nên hằng số này giữ nguyên dạng gốc, chỉ cần là SUBSTRING
     * của message thật.
     */
    protected const SAFE_JOIN_ERROR_MARKER = 'localpath.safeJoinLocalPath';

    /**
     * Cụm nhận diện lỗi OS về TÊN (quá dài / EINVAL) — khác lỗi ổ đĩa cục bộ (xem isLocalFsError()).
     * "file name too long" = ENAMETOOLONG->getMessage() phổ biến trên macOS/Linux.
     */
    protected const INVALID_LOCAL_NAME_MARKERS = [
        'file name too long',
        'name too long',
        'enametoolong',
    ];

    /**
     * ── Atomic write constants (2026-07-19) ─────────────────────────────────────────────────
     * Ngưỡng tuổi (giây) để coi 1 file tạm atomic-write (xem atomicTempPath()) là "rác sót lại
     * từ 1 run TRƯỚC bị crash/kill giữa chừng" thay vì đang được 1 run KHÁC (đồng thời, cùng
     * $baseLocalPath) ghi dở. 6 giờ đủ rộng so với thời gian tải THẬT của 1 file (kể cả file vài
     * GB qua mạng chậm) — không xoá nhầm file tạm của 1 run song song còn đang chạy, nhưng vẫn
     * dọn được rác trong thời gian hợp lý thay vì tích luỹ vô hạn qua nhiều lần chạy cron.
     */
    protected const STALE_ATOMIC_TMP_AGE_SECONDS = 6 * 3600;

    /**
     * Helper to get setting from DB (fallback to ENV)
     */
    protected function getGdriveSetting($key, $envKey)
    {
        if (function_exists('setting')) {
            $val = setting($key);
            if ($val !== null && $val !== '') {
                return $val;
            }
        }
        return env($envKey);
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        set_time_limit(0);
        $startedAt = microtime(true);
        $this->runId = substr(uniqid(), -8);
        $this->listingRetryHits = 0;

        try {
            // 1. Initial Checks
            $isEnabled = $this->getGdriveSetting('social_login_google_drive_enable', 'GOOGLE_DRIVE_ENABLED');
            if (in_array($isEnabled, ['0', 0, false, 'false'], true)) {
                $this->warn("⚠️ GDrive Mirror Sync is disabled.");
                return Command::FAILURE;
            }

            // Log START ngay khi chắc chắn sẽ chạy — chạy qua cron thì đây là bằng chứng
            // duy nhất command thực sự có khởi động (console output không ai xem).
            Log::channel($this->log_channel)->info("GDrive Mirror Sync STARTED", [
                'run_id' => $this->runId,
                'folders' => (array) $this->argument('folders'),
                'path' => $this->option('path'),
                'force' => (bool) $this->option('force'),
                'retry_failed' => (string) $this->option('retry-failed'),
                'dry_run' => (bool) $this->option('dry-run'),
                'limit' => (int) $this->option('limit'),
                'mode' => $this->resolveServiceAccountFile() ? 'service_account' : 'oauth2',
            ]);

            // 2. Prepare Dynamic Disk Configuration
            $this->initGoogleDisk(null, 'google_drive_mirror');

            // 2b. --retry-failed: load items from JSON, override targets/path
            $preloadedItems = null;
            $retryFailedPath = $this->option('retry-failed');
            if ($retryFailedPath) {
                if (! is_file($retryFailedPath) || ! is_readable($retryFailedPath)) {
                    $this->error("Cannot read --retry-failed file: {$retryFailedPath}");
                    return Command::FAILURE;
                }
                $json = json_decode((string) file_get_contents($retryFailedPath), true);
                if (! is_array($json) || ! isset($json['items']) || ! is_array($json['items'])) {
                    $this->error("Invalid retry-failed JSON: missing 'items' array.");
                    return Command::FAILURE;
                }
                $preloadedItems = $json['items'];
                $totalLoaded = count($preloadedItems);
                $this->info("\n🔁 RETRY-FAILED mode: loaded {$totalLoaded} item(s) from " . basename($retryFailedPath));

                // Mặc định CHỈ nạp item retryable — permanent (403 vĩnh viễn: file quá lớn để
                // export, file bị lock…) sẽ KHÔNG BAO GIỜ tự khỏi, retry lại chỉ tốn thời gian.
                // Item cũ không có field 'permanent' (report từ trước khi có phân loại này) =
                // coi như retryable, để không âm thầm bỏ sót (backward-compat).
                if (! $this->option('include-permanent')) {
                    $skipped = array_filter($preloadedItems, fn ($item) => ! empty($item['permanent']));
                    if (! empty($skipped)) {
                        $preloadedItems = array_values(array_filter($preloadedItems, fn ($item) => empty($item['permanent'])));
                        $this->warn("⏭️  Skipped " . count($skipped) . " permanent item(s) (use --include-permanent to force retry).");
                    }
                }

                if (empty($preloadedItems)) {
                    $this->info("Nothing left to retry after filtering permanent errors.");
                    return Command::SUCCESS;
                }
            }

            $rawPath = $this->option('path')
                ?: ($preloadedItems !== null && ! empty($json['base_local_path']) ? $json['base_local_path'] : storage_path('app/google_drive_mirror'));
            $baseLocalPath = realpath($rawPath) ?: $rawPath;
            $this->baseLocalPath = $baseLocalPath;

            // PREFLIGHT (BUG D): kiểm tra đích ghi được TRƯỚC khi list Drive — tránh lặp lại
            // kịch bản run b5200616 08:54 (log thật 2026-07-17): cày liệt kê Drive xong xuôi rồi
            // MỚI phát hiện đích không ghi được (ổ exFAT ngoài đã unmount/đổi quyền), phí thời
            // gian oan. Nếu thư mục CHƯA tồn tại (lần sync đầu tiên) → thử tạo mới thay vì abort
            // ngay, vì "chưa có" là bình thường, khác với "có nhưng không ghi được". --dry-run
            // chỉ liệt kê để debug, KHÔNG ghi gì ra đích → bỏ qua preflight cho chế độ này.
            if (! $this->option('dry-run')) {
                if (! is_dir($baseLocalPath) && ! @mkdir($baseLocalPath, 0755, true) && ! is_dir($baseLocalPath)) {
                    $this->error("\n🛑 Không thể tạo thư mục đích: {$baseLocalPath}");
                    $this->error("   Kiểm tra: ổ đĩa đã mount chưa? Đường dẫn cha có tồn tại/ghi được không?");
                    Log::channel($this->log_channel)->error("GDrive Sync: preflight thất bại — không tạo được thư mục đích", [
                        'run_id' => $this->runId,
                        'base_local_path' => $baseLocalPath,
                    ]);
                    return Command::FAILURE;
                }
                // Ghi-thử THẬT thay cho is_writable(): is_writable() chỉ đọc bit quyền, KHÔNG
                // phát hiện được ổ "báo ghi-được nhưng ghi-thật-lỗi". Sự cố THẬT 2026-07-18 (run
                // 7fe28ffb): ổ exFAT ngoài /Volumes/WD-DATA1 hỏng I/O — is_writable()=true nhưng
                // mkdir/ghi thật ném "Input/output error" (errno=5); preflight cũ lọt qua → list
                // 227 item Drive → đụng circuit breaker sau 20 lỗi (~1 phút phí). Ghi thử 1 file
                // thật bắt ổ wedged/read-only/hỏng NGAY, trước khi tốn công liệt kê Drive.
                if (! self::writeProbe($baseLocalPath, $this->runId)) {
                    $this->error("\n🛑 Thư mục đích không ghi được (read-only / lỗi I/O / ổ treo): {$baseLocalPath}");
                    $this->error("   Ổ đĩa (đặc biệt exFAT ngoài) có thể đang lỗi I/O — thử rút/cắm lại, hoặc chạy Disk Utility First Aid; kiểm tra ổ còn read-write không.");
                    Log::channel($this->log_channel)->error("GDrive Sync: preflight thất bại — đích không ghi thật được (read-only/I/O error)", [
                        'run_id' => $this->runId,
                        'base_local_path' => $baseLocalPath,
                    ]);
                    return Command::FAILURE;
                }

                // Dọn file tạm atomic-write (xem docblock đầu file mục 3/4) sót lại từ 1 run
                // TRƯỚC bị crash/kill giữa chừng — chạy 1 lần ở đầu run, SAU khi đã xác nhận
                // đích ghi được (writeProbe ở trên). Bỏ qua ở --dry-run (khối này vốn đã được
                // bọc trong "if (! dry-run)") vì dry-run không ghi gì ra đích, kể cả dọn rác.
                $this->cleanupStaleAtomicTempFiles($baseLocalPath);
            }

            $googleDisk = Storage::disk("google_drive_mirror");

            if ($preloadedItems !== null) {
                // Use folder_ids from JSON for naming the new report (single synthetic iteration).
                $targetIdentifiers = ! empty($json['folder_ids']) ? (array) $json['folder_ids'] : ['__retry_failed__'];
                // Force single-pass: don't re-list per folder; treat all items as one batch.
                $targetIdentifiers = [reset($targetIdentifiers) ?: '__retry_failed__'];
            } else {
                $targetIdentifiers = (array) $this->argument('folders');
                if (empty($targetIdentifiers)) {
                    $this->error("No folder IDs given. Pass at least one Folder ID, or use --retry-failed=<json>.");
                    return Command::FAILURE;
                }
            }

            $stats = ['processed' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0, 'folders' => 0, 'failed_files' => [], 'collisions' => 0, 'total_listed' => 0];

            foreach ($targetIdentifiers as $identifier) {
                // Lớp PHÒNG THỦ CUỐI (không còn là cơ chế dedup chính — xem C1-C4 audit
                // 2026-07-19): dedup THẬT sự diễn ra ở tầng listing (resolveSiblingNames(), khoá
                // theo Drive ID tăng dần, chung namespace file+folder). Nếu $targetLocalPath vẫn
                // đụng ở đây thì đó là bug Ở TẦNG TRÊN — xem guard ngay trước withRetry() download.
                $usedLocalPaths = []; // Reset per-folder to avoid cross-folder collision false-positives
                $localPrefix = '';
                $this->listingCollisions = 0; // Reset per-folder — cộng vào $stats['collisions'] sau khi listing xong
                $currentDiskName = "gdrive_tmp_" . substr(md5((string) $identifier), 0, 8);

                // Check if $identifier is an ID or a Path
                $isId = (strpos((string) $identifier, '/') === false && strlen((string) $identifier) > 20);

                if ($preloadedItems !== null) {
                    // Retry-failed: items already have full relative paths (incl. folder name).
                    // Reuse the base google_drive_mirror disk for service access.
                    /** @var mixed $googleDisk */
                    $googleDisk = Storage::disk('google_drive_mirror');
                    $exploringPath = '';
                    $localPrefix = '';
                } elseif ($this->serviceAccountFile && $isId) {
                    // Service account: configure disk with folder ID as root; bypass path resolution.
                    // Why: service account has no "My Drive" of the user, so getPathFromId returns a
                    // folder name that listContents() can't resolve. Using the ID as the adapter root
                    // works regardless of who shared the folder.
                    $this->initGoogleDisk($identifier, $currentDiskName);
                    /** @var mixed $googleDisk */
                    $googleDisk = Storage::disk($currentDiskName);

                    try {
                        $folderInfo = $googleDisk->getAdapter()->getService()->files->get($identifier, ['fields' => 'name']);
                        // L2/L3: $localPrefix là 1 path component thật (segment gốc của cây local)
                        // — cùng rủi ro tên-bẩn như bất kỳ tên con nào khác, phải qua sanitize +
                        // truncate giống resolveSiblingNames() làm cho con cháu.
                        $localPrefix = self::truncateNameComponent(self::sanitizeNameComponent($folderInfo->getName()), 0);
                        $this->info("\n🚀 SYNCING (Service Account) — Folder: {$localPrefix} ({$identifier})");
                    } catch (\Throwable $e) {
                        $localPrefix = self::truncateNameComponent(self::sanitizeNameComponent((string) $identifier), 0);
                        $this->warn("\n🚀 SYNCING (Service Account) — Folder ID: {$identifier} (name lookup failed: {$e->getMessage()})");
                    }
                    $exploringPath = '';
                } elseif ($this->serviceAccountFile && !$isId) {
                    $this->warn("\n⚠️ Skipping '{$identifier}': service account mode requires a Folder ID, not a path.");
                    // Skip im lặng trước đây = mất dấu vết khi chạy qua cron (không ai xem console).
                    Log::channel($this->log_channel)->warning("GDrive Sync: skipped identifier (service account mode requires Folder ID, not path)", [
                        'run_id' => $this->runId,
                        'identifier' => $identifier,
                    ]);
                    continue;
                } elseif ($isId) {
                    $this->info("\n🚀 RESOLVING PATH FOR GOOGLE DRIVE ID: {$identifier}");

                    /** @var mixed $googleDisk */
                    $googleDisk = Storage::disk('google_drive_mirror');
                    try {
                        $resolvedPath = $this->getPathFromId($googleDisk, $identifier);
                        if ($resolvedPath) {
                            $this->info("Resolved Path: {$resolvedPath}");
                            $exploringPath = $resolvedPath;
                            // When using resolved path, we don't need a localPrefix because the path itself contains all segments
                            $localPrefix = '';
                        } else {
                            $this->warn("Could not resolve path for ID. Falling back to ID direct scan.");
                            $exploringPath = $identifier;
                        }
                    } catch (\Throwable $e) {
                        $this->warn("Path resolution failed: " . $e->getMessage() . ". Falling back to ID direct scan.");
                        $exploringPath = $identifier;
                    }
                } else {
                    $this->info("\n🚀 SCANNING GOOGLE DRIVE PATH: {$identifier}");
                    $this->initGoogleDisk(null, $currentDiskName); // Reset to base root
                    /** @var mixed $googleDisk */
                    $googleDisk = Storage::disk($currentDiskName);
                    $exploringPath = $identifier;
                }

                if ($preloadedItems !== null) {
                    $remoteItems = $preloadedItems;
                    $this->info("Using " . count($remoteItems) . " preloaded item(s) from --retry-failed JSON.");
                } else {
                    $this->info("Fetching remote item list (Recursive) via " . ($this->serviceAccountFile && $isId ? 'Drive API' : 'masbug adapter') . "...");
                    $remoteItems = [];

                    if ($this->serviceAccountFile && $isId) {
                        // Service account: list via Drive API directly (masbug's listContents
                        // returns 0 items when root is a folder ID).
                        /** @var mixed $googleDisk */
                        $service = $googleDisk->getAdapter()->getService();
                        $remoteItems = $this->listFolderRecursiveViaApi($service, $identifier);
                    } else {
                        /** @var mixed $googleDisk */
                        $iterator = $googleDisk->listContents($exploringPath, true);
                        foreach ($iterator as $item) {
                            $remoteItems[] = $item;
                        }

                        // Fallback for some adapters where ID disk root is '/'
                        if ($isId && count($remoteItems) === 0 && $exploringPath === '') {
                            $this->info("Retrying with root path '/'...");
                            $iterator = $googleDisk->listContents('/', true);
                            foreach ($iterator as $item) {
                                $remoteItems[] = $item;
                            }
                        }
                    }
                }

                $totalItems = count($remoteItems);
                $this->info("Found {$totalItems} items.");
                $stats['total_listed'] += $totalItems;
                // C1-C4: collision đã được resolveSiblingNames() đếm+log NGAY tại tầng listing
                // (fetchFolderChildrenViaApi()) — gộp vào stats tổng của identifier này. Với
                // masbug/--retry-failed (không đi qua listing mới) $listingCollisions luôn 0, += vô hại.
                $stats['collisions'] += $this->listingCollisions;

                // BUG A-3 — LƯỚI AN TOÀN CUỐI CÙNG: so item-count folder gốc với lần chạy
                // trước, KHÔNG PHỤ THUỘC nguyên nhân sụt giảm. BUG A-2 ở trên chỉ vá được cái
                // ta ĐÃ biết (listing trả 0 sai); check này bắt cả nguyên nhân ta CHƯA biết
                // (vd sụt một phần thay vì về hẳn 0). Đặt TRƯỚC --limit (tránh --limit làm
                // remoteItems bị cắt rồi hiểu nhầm là "cây thu nhỏ thật") và TRƯỚC --dry-run.
                // Bỏ qua khi không phải sync đầy đủ: --retry-failed nạp từ JSON (không phải từ
                // listing thật, count không so được) và --dry-run (chỉ debug, không muốn ghi đè
                // state bằng 1 lần chạy thử).
                $isFullSync = $preloadedItems === null && ! $this->option('dry-run');
                if ($isFullSync) {
                    $folderTag = substr(md5((string) $identifier), 0, 8);
                    $prevState = $this->readListingState($folderTag);
                    $prevCount = isset($prevState['item_count']) ? (int) $prevState['item_count'] : null;

                    if (! $this->option('allow-shrink') && self::shouldAbortOnShrink($prevCount, $totalItems)) {
                        $dropPct = $prevCount > 0 ? round((1 - ($totalItems / $prevCount)) * 100, 1) : 0.0;
                        $this->error("\n🛑 ABORT '{$identifier}': lần trước {$prevCount} item, lần này {$totalItems} item (giảm {$dropPct}%) — nghi liệt kê cụt, BỎ QUA để không mirror đè lên cây thiếu. Dùng --allow-shrink nếu chắc chắn cây đã thu nhỏ THẬT.");
                        Log::channel($this->log_channel)->error("GDrive Sync: ABORT — item count sụt bất thường", [
                            'run_id' => $this->runId,
                            'folder_id' => (string) $identifier,
                            'prev_count' => $prevCount,
                            'current_count' => $totalItems,
                            'drop_pct' => $dropPct,
                        ]);
                        continue;
                    }

                    // Chỉ cập nhật state khi listing được chấp nhận — folder bị abort ở trên
                    // GIỮ NGUYÊN state cũ (con số tốt lần trước), không ghi đè bằng số nghi ngờ.
                    $this->writeListingState($folderTag, (string) $identifier, $totalItems);
                }

                if ($totalItems === 0) {
                    // Im lặng trước đây → false-green khi chạy cron (list rỗng có thể là bug
                    // thật: quyền bị thu hồi, ID sai, folder bị xoá — không chỉ "folder trống").
                    Log::channel($this->log_channel)->warning("GDrive Sync: 0 remote items found for identifier", [
                        'run_id' => $this->runId,
                        'identifier' => $identifier,
                    ]);
                    continue;
                }

                // --limit: trim for testing
                $limit = (int) $this->option('limit');
                if ($limit > 0 && count($remoteItems) > $limit) {
                    $remoteItems = array_slice($remoteItems, 0, $limit);
                    $totalItems = count($remoteItems);
                    $this->warn("⚙️  --limit={$limit}: processing first {$totalItems} items only.");
                }

                // --dry-run: just print first 20 items as a table and skip download.
                if ($this->option('dry-run')) {
                    $this->info("🧪 DRY RUN — listing first 20 items, no download.");
                    $sample = array_slice($remoteItems, 0, 20);
                    $rows = [];
                    foreach ($sample as $it) {
                        $rows[] = [
                            is_array($it) ? ($it['type'] ?? '?') : (method_exists($it, 'type') ? $it->type() : '?'),
                            is_array($it) ? ($it['id'] ?? '-') : '-',
                            substr((string) (is_array($it) ? ($it['path'] ?? '') : (method_exists($it, 'path') ? $it->path() : '')), 0, 80),
                            is_array($it) ? ($it['mimeType'] ?? '-') : '-',
                            is_array($it) ? ($it['size'] ?? 0) : '-',
                            is_array($it) ? (substr((string) ($it['md5Checksum'] ?? ''), 0, 12) ?: '-') : '-',
                        ];
                    }
                    $this->table(['type', 'id', 'path', 'mimeType', 'size', 'md5'], $rows);
                    continue;
                }

                $this->info("Starting synchronization...");
                $bar = $this->output->createProgressBar($totalItems);
                $bar->start();

                foreach ($remoteItems as $item) {
                    $stats['processed']++;

                    // Support both Flysystem 1 (array) and Flysystem 3 (object)
                    $relativePath = is_array($item) ? ($item['path'] ?? null) : (method_exists($item, 'path') ? $item->path() : ($item->path ?? null));
                    if ($relativePath === null) {
                        $bar->advance();
                        continue;
                    }

                    // Adjust local path if using ID (prefix with folder name)
                    $syncPath = $localPrefix ? $localPrefix . '/' . $relativePath : $relativePath;
                    $type = is_array($item) ? ($item['type'] ?? 'file') : (method_exists($item, 'type') ? $item->type() : 'file');

                    // C5/L2 (audit 2026-07-19): mọi component trong $syncPath đã đi qua
                    // sanitizeNameComponent()/truncateNameComponent() ở tầng listing (xem
                    // fetchFolderChildrenViaApi()) hoặc ở $localPrefix ngay phía trên — nhưng đây
                    // vẫn là điểm PHÒNG THỦ CUỐI CÙNG trước khi chạm filesystem thật (masbug/
                    // --retry-failed KHÔNG đi qua resolveSiblingNames(), và path từ report JSON cũ
                    // có thể sinh ra TRƯỚC khi fix này tồn tại). safeJoinLocalPath() bảo đảm kết
                    // quả không bao giờ thoát khỏi $baseLocalPath, y hệt localpath.SafeJoin() bản Go.
                    try {
                        $absoluteLocalPath = self::safeJoinLocalPath($baseLocalPath, $syncPath);
                    } catch (\Throwable $e) {
                        // Lỗi TÊN (traversal/escape), KHÔNG PHẢI lỗi ổ đĩa cục bộ — permanent,
                        // KHÔNG tính vào $consecutiveLocalFsErrors (circuit breaker chỉ dành cho ổ
                        // đích hỏng/unmount, xem isInvalidLocalName()/classifyDriveError()).
                        $stats['errors']++;
                        $itemId = is_array($item) ? ($item['id'] ?? null) : null;
                        $classified = self::classifyDriveError($e->getMessage());
                        $stats['failed_files'][] = [
                            'type' => $type,
                            'path' => $relativePath,
                            'id' => $itemId,
                            'mimeType' => is_array($item) ? ($item['mimeType'] ?? null) : null,
                            'md5Checksum' => is_array($item) ? ($item['md5Checksum'] ?? null) : null,
                            'timestamp' => is_array($item) ? ($item['timestamp'] ?? 0) : 0,
                            'size' => is_array($item) ? (int) ($item['size'] ?? 0) : 0,
                            'attempts' => 0,
                            'reason' => $e->getMessage(),
                            'permanent' => ! $classified['retryable'],
                            'error_reason' => $classified['reason'],
                            'category' => $classified['category'],
                        ];
                        $this->error("\n   ❌ Rejected unsafe path (traversal/escape): {$relativePath} | Reason: {$e->getMessage()}");
                        Log::channel($this->log_channel)->error("GDrive Sync: safeJoinLocalPath rejected path", [
                            'run_id' => $this->runId,
                            'path' => $relativePath,
                            'sync_path' => $syncPath,
                            'base_local_path' => $baseLocalPath,
                            'error' => $e->getMessage(),
                        ]);
                        $bar->advance();
                        continue;
                    }

                    if ($type === 'dir') {
                        if (!File::isDirectory($absoluteLocalPath)) {
                            // BUG B (đã chứng minh bằng log thật): mkdir() Permission denied trên
                            // 1 thư mục ném lên try/catch NGOÀI CÙNG của handle() → 💥 Fatal Error
                            // → giết TOÀN BỘ mirror (5760 item) kể cả những item đã sẵn sàng.
                            // Return value của File::makeDirectory() cũng bị bỏ qua trước đây —
                            // $stats['folders']++ chạy bất kể thành công hay không. Giờ bọc
                            // try/catch + kiểm kết quả: 1 thư mục hỏng chỉ ghi nhận lỗi cho riêng
                            // nó rồi tiếp tục, KHÔNG được phép huỷ cả lần chạy.
                            try {
                                $created = File::makeDirectory($absoluteLocalPath, 0755, true);
                            } catch (\Throwable $e) {
                                $created = false;
                                $this->lastError = $e->getMessage();
                            }

                            if ($created) {
                                $stats['folders']++;
                                // BUG D: item thành công → ổ đích còn ghi được, xoá dấu vết lỗi liên tiếp.
                                $this->consecutiveLocalFsErrors = 0;
                            } else {
                                $stats['errors']++;
                                $reason = $this->lastError ?? 'mkdir failed (unknown reason)';
                                $classified = self::classifyDriveError($reason);
                                $stats['failed_files'][] = [
                                    'type' => 'dir',
                                    'path' => $relativePath,
                                    'id' => null,
                                    'mimeType' => null,
                                    'md5Checksum' => null,
                                    'timestamp' => 0,
                                    'size' => 0,
                                    'attempts' => 0,
                                    'reason' => $reason,
                                    'permanent' => ! $classified['retryable'],
                                    'error_reason' => $classified['reason'],
                                    'category' => $classified['category'],
                                ];
                                Log::channel($this->log_channel)->error("GDrive Sync: mkdir failed", [
                                    'run_id' => $this->runId,
                                    'path' => $relativePath,
                                    'absolute_path' => $absoluteLocalPath,
                                    'error' => $reason,
                                ]);
                                $this->error("\n   ❌ Failed to create folder: {$relativePath} | Reason: {$reason}");

                                // BUG D: mkdir() luôn là lỗi filesystem cục bộ (không phải Drive API)
                                // — đếm vào circuit breaker riêng, ABORT nếu vượt ngưỡng.
                                if (self::isLocalFsError($reason)) {
                                    $this->consecutiveLocalFsErrors++;
                                    if (self::shouldAbortOnLocalFsErrors($this->consecutiveLocalFsErrors)) {
                                        $bar->finish();
                                        $this->abortForLocalFsErrors($baseLocalPath);
                                        break 2;
                                    }
                                }
                            }
                        }
                        $bar->advance();
                        continue;
                    }

                    // FILE SYNC LOGIC
                    if ($type === 'file') {
                        // is_object() guards required: $item may be array (from Drive API listing)
                        // OR object (from masbug listContents). method_exists() throws on arrays in PHP 8+.
                        $meta = (is_object($item) && method_exists($item, 'extraMetadata')) ? $item->extraMetadata() : $item;
                        $fileId = is_array($meta) ? ($meta['id'] ?? 'N/A') : ((is_object($meta) && method_exists($meta, 'getId')) ? $meta->getId() : 'N/A');
                        $mimeType = is_array($meta) ? ($meta['mimeType'] ?? '') : ($meta->mimeType ?? '');
                        $remoteTimestamp = is_array($item)
                            ? ($item['timestamp'] ?? 0)
                            : ($item instanceof \League\Flysystem\StorageAttributes ? $item->lastModified() : 0);
                        $remoteMd5 = is_array($meta) ? ($meta['md5Checksum'] ?? null) : ($meta->md5Checksum ?? null);
                        $remoteSize = is_array($meta) ? (int) ($meta['size'] ?? 0) : (int) ($meta->size ?? 0);

                        // Decide target path (Normal vs Export)
                        $targetLocalPath = $absoluteLocalPath;
                        $exportSpec = self::EXPORT_MAP[$mimeType] ?? null;

                        // Deep Check for Google Native Files if MimeType is missing or generic
                        $fileMeta = null;
                        if (!$exportSpec && $isId) {
                            try {
                                /** @var mixed $googleDisk */
                                $service = $googleDisk->getAdapter()->getService();
                                $fileMeta = $service->files->get($fileId, ['fields' => 'id, name, mimeType, md5Checksum, shortcutDetails']);
                                $mimeType = $fileMeta->getMimeType();
                                $remoteMd5 = $fileMeta->getMd5Checksum();
                                $exportSpec = self::EXPORT_MAP[$mimeType] ?? null;
                            } catch (\Throwable $e) {
                                // Nuốt lỗi trước đây → mất dấu vết khi deep-check thất bại (vd file
                                // vừa bị xoá/mất quyền); không fail cả sync, chỉ giữ mimeType gốc.
                                Log::channel($this->log_channel)->warning("GDrive Sync: deep mimeType check failed", [
                                    'run_id' => $this->runId,
                                    'file_id' => $fileId,
                                    'error' => $e->getMessage(),
                                ]);
                            }
                        }

                        // Shortcut = con trỏ tới file thật, KHÔNG có nội dung riêng — GET nó trả
                        // JSON lỗi 403 "Only files with binary content can be downloaded" (reason
                        // fileNotDownloadable) mà KHÔNG throw ở đường raw-Guzzle (xem
                        // streamDownloadViaApi()), nên trước đây bị ghi thẳng lên đĩa như file rác
                        // rồi báo "thành công". Target thật của shortcut được Drive liệt kê như 1
                        // item độc lập trong cùng listing (đã verify: file thật cùng tên vẫn
                        // export/tải đúng) → bỏ qua an toàn, không mất dữ liệu.
                        if ($mimeType === 'application/vnd.google-apps.shortcut') {
                            $stats['skipped']++;
                            Log::channel($this->log_channel)->info("GDrive Sync: skipped shortcut (pointer, not content)", [
                                'run_id' => $this->runId,
                                'path' => $relativePath,
                                'id' => $fileId,
                                'shortcut_target_mime' => $this->extractShortcutTargetMime($meta, $fileMeta),
                            ]);
                            $bar->advance();
                            continue;
                        }

                        // Đuôi export (.docx/.xlsx/…) BÌNH THƯỜNG đã có SẴN trong $absoluteLocalPath
                        // — resolveSiblingNames() quyết định nó ngay ở tầng listing, dùng mimeType
                        // từ files.list (xem fetchFolderChildrenViaApi()). Nhánh append dưới đây CHỈ
                        // còn cần thiết cho 1 kẽ hở kiến trúc RIÊNG CỦA PHP (bản Go không có): "Deep
                        // Check" phía trên có thể phát hiện ra mimeType Google Native mà listing
                        // KHÔNG biết trước (field mimeType từ files.list bị thiếu/generic — hiếm).
                        // Chỉ append khi tên hiện tại CHƯA có đúng đuôi đó, để không nối lặp/nối sai
                        // so với cái resolveSiblingNames() đã làm đúng ở đa số trường hợp.
                        if ($exportSpec) {
                            $expectedSuffix = '.' . $exportSpec['ext'];
                            if (! str_ends_with($targetLocalPath, $expectedSuffix)) {
                                $targetLocalPath = $absoluteLocalPath . $expectedSuffix;
                            }
                        }

                        // C1-C4 audit (2026-07-19): dedup THẬT sự đã chuyển hẳn lên tầng listing
                        // (resolveSiblingNames(), khoá theo Drive ID tăng dần, chung namespace
                        // file+folder — xem fetchFolderChildrenViaApi()). $usedLocalPaths ở đây giờ
                        // chỉ còn là LỚP PHÒNG THỦ: nếu vẫn đụng thì đó là BUG Ở TẦNG TRÊN (không
                        // phải trùng tên Drive thật) — KHÔNG được âm thầm đổi tên/ghi đè (đúng lỗi
                        // gốc C1-C4 đã sửa), phải log ERROR + đưa vào failed_files (permanent) để
                        // điều tra. File cũ tại path đó (nếu có, từ item trước trong CÙNG lần chạy)
                        // giữ nguyên, không bị ghi đè bởi item thứ 2 này.
                        if (isset($usedLocalPaths[$targetLocalPath])) {
                            $stats['errors']++;
                            $reason = "internal collision guard tripped: '{$targetLocalPath}' already claimed by drive id {$usedLocalPaths[$targetLocalPath]} in this listing";
                            $stats['failed_files'][] = [
                                'type' => 'file',
                                'path' => $relativePath,
                                'id' => $fileId,
                                'mimeType' => $mimeType,
                                'md5Checksum' => $remoteMd5,
                                'timestamp' => $remoteTimestamp,
                                'size' => $remoteSize,
                                'attempts' => 0,
                                'reason' => $reason,
                                'permanent' => true,
                                'error_reason' => 'internalCollisionGuard',
                                'category' => 'Internal collision guard (upstream bug, cần điều tra)',
                            ];
                            $this->error("\n   ❌ Internal collision guard tripped (KHÔNG ghi đè): {$targetLocalPath} — báo bug, xem log.");
                            Log::channel($this->log_channel)->error("GDrive Sync: usedLocalPaths guard tripped — trùng tên lọt qua resolveSiblingNames()", [
                                'run_id' => $this->runId,
                                'path' => $relativePath,
                                'target_local_path' => $targetLocalPath,
                                'claimed_by_id' => $usedLocalPaths[$targetLocalPath],
                                'this_id' => $fileId,
                            ]);
                            $bar->advance();
                            continue;
                        }
                        $usedLocalPaths[$targetLocalPath] = $fileId;

                        // Delta Sync: size mismatch is a cheap pre-check (detects truncation/corruption
                        // without reading whole file). MD5 is authoritative when available. Timestamp
                        // is fallback for Google Native files (which have no md5Checksum).
                        //
                        // 🔴 BUG C (đã chứng minh bằng log thật, storage/logs/pull.vn-2026-07-17.log
                        // run f0547f35 01:19:20): md5_file() đọc file trên ổ exFAT ngoài rớt kết nối
                        // giữa chừng ném E_WARNING "md5_file(): Read of 8192 bytes failed with
                        // errno=5 Input/output error". Laravel HandleExceptions biến WARNING thành
                        // ErrorException → KHÔNG có try/catch nào trong vòng lặp bắt được (trước đây)
                        // → thoát thẳng ra try/catch NGOÀI CÙNG của handle() → 💥 Fatal Error giết CẢ
                        // run (5760 item, kể cả item hoàn toàn khoẻ mạnh). File::lastModified() cũng
                        // đọc filesystem nên chịu rủi ro tương tự. Bọc try/catch: 1 lỗi đọc local
                        // KHÔNG được phép huỷ cả run — coi như "không verify được bản local", rơi
                        // xuống nhánh download lại bên dưới (self-heal, khớp docblock mục 4 "An toàn
                        // khi crash giữa chừng" — vốn đã coi size/MD5 mismatch là tín hiệu tải lại).
                        if (!$this->option('force') && File::exists($targetLocalPath)) {
                            try {
                                $localSize = (int) @filesize($targetLocalPath);

                                // Size pre-check: only meaningful for regular files. Google Native files
                                // have a "native" remote size that differs from the post-export local size,
                                // so comparing them would falsely trigger re-download every run.
                                if (! $exportSpec && $remoteSize > 0 && $localSize > 0 && $localSize !== $remoteSize) {
                                    // size mismatch → re-download (don't waste md5 hash on a wrong-size file)
                                } elseif ($remoteMd5) {
                                    // MD5 available: skip only if content is identical
                                    if (md5_file($targetLocalPath) === $remoteMd5) {
                                        $stats['skipped']++;
                                        $bar->advance();
                                        continue;
                                    }
                                    // MD5 mismatch means file changed → must re-download, ignore timestamp
                                } elseif (File::lastModified($targetLocalPath) >= $remoteTimestamp) {
                                    // No MD5 (Google Native): fall back to timestamp
                                    $stats['skipped']++;
                                    $bar->advance();
                                    continue;
                                }
                            } catch (\Throwable $e) {
                                Log::channel($this->log_channel)->warning("GDrive Sync: delta-check I/O error, forcing re-download", [
                                    'run_id' => $this->runId,
                                    'path' => $relativePath,
                                    'error' => $e->getMessage(),
                                ]);
                                // Không skip, không fatal — rơi xuống nhánh download lại bên dưới.
                            }
                        }

                        // Download/Export with Retry Logic
                        $useApi = (bool) $this->serviceAccountFile;
                        $success = $this->withRetry(function() use ($googleDisk, $relativePath, $targetLocalPath, $exportSpec, $fileId, $useApi) {
                            File::ensureDirectoryExists(dirname($targetLocalPath));

                            if ($exportSpec) {
                                // Export Google Native File
                                /** @var mixed $googleDisk */
                                $service = $googleDisk->getAdapter()->getService();
                                $this->line("\n   ✨ Exporting Google Native: " . basename($targetLocalPath));
                                // AN TOÀN (đã verify bằng đọc code thật, không phải đoán): $service->files->export()
                                // là lời gọi generated qua Google\Service\Resource::call() → Client::execute() →
                                // Http\REST::execute() → REST::decodeHttpResponse(), nơi CÓ check
                                // `if ($code >= 400) throw new GoogleServiceException($body, $code, ...)`.
                                // Khác với streamDownloadViaApi() (dùng Guzzle RAW, http_errors=false, không tự
                                // throw), nhánh export này KHÔNG cần check status tay — lỗi (kể cả 403
                                // fileNotDownloadable/cannotExportFile) đã throw đúng để withRetry() bắt được.
                                $response = $service->files->export($fileId, $exportSpec['mime'], ['alt' => 'media']);
                                // Ghi ATOMIC (xem docblock đầu file mục 3 + atomicPutContents()):
                                // ra file tạm rồi rename() vào đích, chỉ khi thành công trọn vẹn.
                                $this->atomicPutContents($targetLocalPath, $response->getBody()->getContents());
                            } elseif ($useApi) {
                                // Service account: download via Drive API (masbug readStream
                                // doesn't work when adapter root is a folder ID). Atomic write
                                // được xử lý bên trong streamDownloadViaApi() qua atomicWriteStream().
                                /** @var mixed $googleDisk */
                                $service = $googleDisk->getAdapter()->getService();
                                $this->streamDownloadViaApi($service, $fileId, $targetLocalPath);
                            } else {
                                // Stream Download for regular files (Memory Efficient)
                                $readStream = $googleDisk->readStream($relativePath);
                                if (!$readStream) {
                                    throw new \Exception("Could not open read stream");
                                }
                                try {
                                    // Ghi ATOMIC (xem atomicWriteStream()): stream vào file tạm,
                                    // rename() vào đích chỉ khi copy xong không lỗi.
                                    $this->atomicWriteStream($targetLocalPath, function ($handle) use ($readStream) {
                                        stream_copy_to_stream($readStream, $handle);
                                    });
                                } finally {
                                    if (is_resource($readStream)) fclose($readStream);
                                }
                            }
                            return true;
                        }, $relativePath);

                        if ($success) {
                            @touch($targetLocalPath, $remoteTimestamp);
                            $stats['updated']++;
                            // BUG D: item thành công → ổ đích còn ghi được, xoá dấu vết lỗi liên tiếp.
                            $this->consecutiveLocalFsErrors = 0;
                        } else {
                            $stats['errors']++;
                            $reason = $this->lastError ?? 'Unknown Error';
                            $classified = self::classifyDriveError($reason);
                            $stats['failed_files'][] = [
                                // 'path' matches listFolderRecursiveViaApi shape, so this entry
                                // can be fed straight back through the main loop via --retry-failed.
                                'type' => 'file',
                                'path' => $relativePath,
                                'id' => $fileId,
                                'mimeType' => $mimeType,
                                'md5Checksum' => $remoteMd5,
                                'timestamp' => $remoteTimestamp,
                                'size' => $remoteSize,
                                'attempts' => $this->lastAttempts,
                                'reason' => $reason,
                                // permanent=true → item cũ (không có field này) coi như retryable
                                // khi đọc lại report cũ (backward-compat, xem loadRetryFailedItems()).
                                'permanent' => ! $classified['retryable'],
                                'error_reason' => $classified['reason'],
                                'category' => $classified['category'],
                            ];
                            $this->error("\n   ❌ Failed to sync: {$relativePath} | Attempts: {$this->lastAttempts} | Reason: {$reason}");

                            // BUG D: withRetry() đã đếm $consecutiveLocalFsErrors khi đây là lỗi
                            // filesystem cục bộ (xem withRetry()) — kiểm ngưỡng, ABORT nếu vượt.
                            if (self::shouldAbortOnLocalFsErrors($this->consecutiveLocalFsErrors)) {
                                $bar->finish();
                                $this->abortForLocalFsErrors($baseLocalPath);
                                break 2;
                            }
                        }
                    }
                    $bar->advance();
                }
                $bar->finish();
                $this->info("");

                // Cleanup Logic removed for safety
            }

            $this->finalReport($stats, $baseLocalPath, $targetIdentifiers, $startedAt);

        } catch (\Throwable $th) {
            $this->error("\n💥 Fatal Error: " . $th->getMessage());
            // run_id: entry này TỪNG là log duy nhất của command thiếu run_id — đúng cái entry
            // quan trọng nhất (fatal). Điều tra sự cố 2026-07-17 phải suy ra run bằng cách so
            // giờ với các dòng STARTED lân cận (và suy nhầm). Fatal cần truy vết được như mọi
            // entry khác — xem docblock "OUTPUT VÀ LOG".
            Log::channel($this->log_channel)->error("GDrive Mirror Fatal Exception", ['run_id' => $this->runId, 'msg' => $th->getMessage(), 'trace' => $th->getTraceAsString()]);
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * BUG D: in + log thông báo ABORT toàn bộ run khi $consecutiveLocalFsErrors vượt ngưỡng
     * (xem shouldAbortOnLocalFsErrors()). Tách riêng vì được gọi từ 2 chỗ trong handle() (nhánh
     * mkdir thư mục và nhánh download file) — cả 2 đều phải break khỏi cả 2 vòng foreach ngay
     * sau khi gọi, nên method này chỉ báo/log, KHÔNG tự break (không thể break thay caller).
     */
    protected function abortForLocalFsErrors(string $baseLocalPath): void
    {
        $this->error("\n🛑 ABORT: {$this->consecutiveLocalFsErrors} lỗi filesystem cục bộ liên tiếp — ổ đích có thể đã unmount/read-only/hỏng, kiểm tra: {$baseLocalPath}");
        Log::channel($this->log_channel)->error("GDrive Sync: ABORT — quá nhiều lỗi filesystem cục bộ liên tiếp", [
            'run_id' => $this->runId,
            'consecutive_local_fs_errors' => $this->consecutiveLocalFsErrors,
            'base_local_path' => $baseLocalPath,
        ]);
    }

    /**
     * Wrapper for retry logic
     */
    protected function withRetry(callable $callback, $path)
    {
        $maxRetries = (int) $this->option('retry');
        $attempts = 0;
        $this->lastAttempts = 0;

        while ($attempts <= $maxRetries) {
            try {
                $result = $callback();
                $this->lastAttempts = $attempts; // 0 = succeeded on first try, N = succeeded after N retries
                return $result;
            } catch (\Throwable $e) {
                $attempts++;
                $this->lastAttempts = $attempts;
                $msg = $e->getMessage();
                $this->lastError = $msg;

                // BUG D: lỗi filesystem CỤC BỘ (mkdir/fopen/md5_file.../ổ hỏng) KHÔNG PHẢI lỗi
                // Drive API — retry vô ích vì nguyên nhân (ổ unmount/read-only/hỏng) không tự
                // khỏi giữa các lần thử cách nhau vài giây. PHẢI kiểm TRƯỚC classifyDriveError():
                // message "mkdir(): Permission denied" rơi vào nhánh string-match
                // "permission"/"forbidden" của classifyDriveError() → bị gán retryable=true (ĐÚNG
                // cho lỗi Drive thật, SAI cho lỗi ổ đĩa cục bộ) → tốn 3 lần retry × backoff
                // (2+4+8=14s) MỖI FILE trong khi ổ đã hỏng. Log thật (run f0547f35 2026-07-17
                // 01:19-01:21): hàng chục file liên tiếp lặp lại y hệt "mkdir(): Permission denied",
                // đốt ~2 phút chỉ để fail — không retry giúp fail nhanh, đúng bản chất lỗi.
                if (self::isLocalFsError($msg)) {
                    $this->consecutiveLocalFsErrors++;
                    $this->warn("\n      ⚠️  Local filesystem error — không retry (ổ đích có thể hỏng/unmount).");
                    Log::channel($this->log_channel)->warning("GDrive Sync: local filesystem error, not retrying", [
                        'run_id' => $this->runId,
                        'path' => $path,
                        'error' => $msg,
                        'consecutive_local_fs_errors' => $this->consecutiveLocalFsErrors,
                    ]);
                    return false;
                }

                $classified = self::classifyDriveError($msg);

                // Lỗi permanent (403 vĩnh viễn: exportSizeLimitExceeded, cannotExportFile,
                // insufficientFilePermissions…) sẽ KHÔNG BAO GIỜ tự khỏi dù retry bao nhiêu lần
                // — retry chỉ tốn backoff (2+4+8=14s...) vô ích cho mỗi file. Dừng ngay lần đầu.
                if (! $classified['retryable']) {
                    $this->warn("\n      ⚠️  Permanent error ({$classified['category']}). Skipping, no retry.");
                    Log::channel($this->log_channel)->warning("GDrive Sync: permanent error, not retrying", [
                        'run_id' => $this->runId,
                        'path' => $path,
                        'category' => $classified['category'],
                        'reason' => $classified['reason'],
                        'error' => $msg,
                    ]);
                    return false;
                }

                if ($attempts > $maxRetries) {
                    Log::channel($this->log_channel)->error("GDrive Sync: Final failure after {$maxRetries} retries", [
                        'run_id' => $this->runId,
                        'path' => $path,
                        'category' => $classified['category'],
                        'error' => $msg,
                    ]);
                    return false;
                }

                $delay = min(2 ** $attempts, 30); // Exponential backoff: 2s, 4s, 8s… max 30s
                $this->comment("      ⏳ Attempt {$attempts} failed, retrying in {$delay}s...");
                // Log mỗi lần retry thất bại — trước đây chỉ có console comment() nên chạy qua
                // cron là mất sạch, không biết command có đang phải retry nhiều hay không.
                Log::channel($this->log_channel)->warning("GDrive Sync: retry attempt failed", [
                    'run_id' => $this->runId,
                    'path' => $path,
                    'attempt' => $attempts,
                    'max_retries' => $maxRetries,
                    'delay_sec' => $delay,
                    'error' => $msg,
                ]);
                sleep($delay);
            }
        }
        return false;
    }

    /**
     * Phân loại lỗi Drive API theo `reason` field trong body JSON (KHÔNG string-match message
     * chung chung — vd message "cannotExportFile" từng bị dán nhãn nhầm 'Permission denied' chỉ
     * vì string chứa "403"). Pure/static để unit-test không cần boot Laravel.
     *
     * Google trả lỗi dạng:
     *   {"error":{"code":403,"message":"...","errors":[{"reason":"cannotExportFile",...}]}}
     *
     * 🔴 NGUYÊN TẮC: permanent = ALLOWLIST reason đã biết chắc; MỌI thứ còn lại retryable=true.
     * Lý do — hai chiều sai KHÔNG cân xứng:
     *   • Đoán nhầm "permanent" → file bị bỏ hẳn, âm thầm mất khỏi mirror (nặng).
     *   • Đoán nhầm "retryable" → phí ~14s backoff rồi cũng fail (nhẹ).
     * → Khi không chắc, LUÔN nghiêng về retryable. Đừng suy "403 ⇒ permanent": Drive dùng 403
     *   cho cả rate-limit tạm thời (dailyLimitExceeded, sharingRateLimitExceeded…).
     *   Thêm reason mới vào $permanentReasons chỉ khi đã xác nhận nó KHÔNG BAO GIỜ tự khỏi.
     *
     * @return array{category: string, retryable: bool, reason: ?string}
     */
    public static function classifyDriveError(string $msg): array
    {
        // L1-L3/C5 audit (2026-07-19): kiểm TRƯỚC MỌI THỨ KHÁC — lỗi TÊN (quá dài/EINVAL/bị
        // safeJoinLocalPath() từ chối) là lỗi Drive-side data problem VĨNH VIỄN, không được lẫn
        // với lỗi Drive API thật (bị retry vô ích) hay lỗi ổ đĩa cục bộ (bị đếm vào circuit
        // breaker oan — xem isLocalFsError()). Mirror classify.DriveError() bản Go.
        if (self::isInvalidLocalName($msg)) {
            return ['category' => 'Invalid or too-long local name', 'retryable' => false, 'reason' => 'invalidLocalName'];
        }

        $decoded = json_decode($msg, true);
        $reason = null;
        $code = null;

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $reason = $decoded['error']['errors'][0]['reason'] ?? null;
            $code = $decoded['error']['code'] ?? null;
        }

        // Permanent (403 vĩnh viễn) — retry sẽ KHÔNG BAO GIỜ thành công, chỉ tốn thời gian.
        $permanentReasons = [
            'exportSizeLimitExceeded' => 'Export size limit',
            'cannotExportFile' => 'Not exportable (locked/unsupported)',
            'fileNotExportable' => 'Not exportable (locked/unsupported)',
            'notFound' => 'Not found',
            'insufficientFilePermissions' => 'Permission denied',
            'appNotAuthorizedToFile' => 'Permission denied',
            'domainPolicy' => 'Permission denied',
            'forbidden' => 'Permission denied',
            'fileNotDownloadable' => 'Not downloadable (Docs Editors/shortcut)',
            // Bằng chứng THẬT (run 9cd3158b 2026-07-17, report failed-66c5cc45-20260717-150730.json):
            // run fail 28 file = 23 permanent + 5 retryable, và 5 retryable đó ĐÚNG BẰNG 5 file
            // 403 reason=cannotDownloadFile ("This file cannot be downloaded by the user") =
            // CHỦ FILE TẮT quyền tải xuống cho viewer. Retry sau 2/4/8s KHÔNG BAO GIỜ khỏi —
            // chỉ khỏi khi chủ đổi setting chia sẻ, việc mà command không tác động được. Trước
            // đây reason này KHÔNG có trong allowlist → rơi xuống nhánh 403-fallback → gán
            // retryable=true → mỗi file đốt 14s backoff rồi vẫn fail, VÀ (nặng hơn) không được
            // đánh permanent nên KHÔNG lọt vào manifest unexportable → âm thầm thiếu khỏi mirror
            // mà không ai thấy. Đã đo tận nơi: manifest 66c5cc45.md liệt kê ĐÚNG 23 file (13+7+3),
            // 5 file cannotDownloadFile KHÔNG xuất hiện dòng nào — tức chúng thiếu khỏi cả mirror
            // LẪN cái tài liệu sinh ra để báo "đang thiếu gì". Đánh permanent làm TỐT HƠN cả hai
            // chiều: bỏ retry vô ích + hiện trong manifest để người đọc biết đang thiếu gì.
            'cannotDownloadFile' => 'Not downloadable (chủ tắt quyền tải)',
        ];
        if ($reason !== null && isset($permanentReasons[$reason])) {
            return ['category' => $permanentReasons[$reason], 'retryable' => false, 'reason' => $reason];
        }

        // Transient (tạm thời) — retry có cơ hội thành công. LƯU Ý: Drive trả rate-limit dưới
        // CẢ 429 lẫn 403, nên không được suy ra "403 = permanent" (xem fallback bên dưới).
        $transientReasons = [
            'rateLimitExceeded' => 'Quota / rate limit',
            'userRateLimitExceeded' => 'Quota / rate limit',
            'sharingRateLimitExceeded' => 'Quota / rate limit',
            'dailyLimitExceeded' => 'Quota / rate limit',
            'quotaExceeded' => 'Quota / rate limit',
            'backendError' => 'Server error (5xx)',
            'internalError' => 'Server error (5xx)',
        ];
        if ($reason !== null && isset($transientReasons[$reason])) {
            return ['category' => $transientReasons[$reason], 'retryable' => true, 'reason' => $reason];
        }

        if (in_array($code, [500, 502, 503, 504], true)) {
            return ['category' => 'Server error (5xx)', 'retryable' => true, 'reason' => $reason];
        }
        if ($code === 403) {
            // 403 với reason KHÔNG nằm trong allowlist permanent ở trên (hoặc không đọc được
            // reason) → KHÔNG đủ chắc để coi là permanent. Drive dùng 403 cho cả rate-limit
            // (dailyLimitExceeded, sharingRateLimitExceeded…) lẫn lỗi quyền thật, và Google có
            // thể thêm reason mới bất cứ lúc nào. Mặc định retryable=true (xem ghi chú cân
            // nhắc hai chiều sai ở cuối hàm).
            return ['category' => 'Permission denied', 'retryable' => true, 'reason' => $reason];
        }

        // Không phải JSON hợp lệ (lỗi network/curl/Guzzle raw string) → fallback heuristic
        // string-match như logic cũ.
        $m = strtolower($msg);
        if (str_contains($m, 'exportsizelimitexceeded') || str_contains($m, 'too large to export')) {
            return ['category' => 'Export size limit', 'retryable' => false, 'reason' => 'exportSizeLimitExceeded'];
        }
        if (str_contains($m, 'cannotexportfile') || str_contains($m, 'not exportable')) {
            return ['category' => 'Not exportable (locked/unsupported)', 'retryable' => false, 'reason' => 'cannotExportFile'];
        }
        if (str_contains($m, '404') || str_contains($m, 'not found') || str_contains($m, 'notfound')) {
            return ['category' => 'Not found', 'retryable' => false, 'reason' => 'notFound'];
        }
        // Rate/quota PHẢI kiểm TRƯỚC 403: Drive trả rate-limit dưới cả 429 lẫn 403, nếu để
        // nhánh 403 chặn trên thì rate-limit (tạm thời) bị nuốt thành 'Permission denied'.
        if (str_contains($m, '429') || str_contains($m, 'rate') || str_contains($m, 'quota') || str_contains($m, 'userratelimit')) {
            return ['category' => 'Quota / rate limit', 'retryable' => true, 'reason' => null];
        }
        if (str_contains($m, '403') || str_contains($m, 'forbidden') || str_contains($m, 'permission') || str_contains($m, 'insufficient')) {
            // retryable=true: chuỗi 403 trần (không parse được reason) KHÔNG chứng minh được là
            // permanent. Chỉ reason permanent đã biết ở allowlist trên mới được phép skip hẳn.
            return ['category' => 'Permission denied', 'retryable' => true, 'reason' => null];
        }
        if (str_contains($m, 'timeout') || str_contains($m, 'timed out') || str_contains($m, 'curl error') || str_contains($m, 'connection')) {
            return ['category' => 'Network / timeout', 'retryable' => true, 'reason' => null];
        }
        if (str_contains($m, '500') || str_contains($m, '502') || str_contains($m, '503') || str_contains($m, 'internal error') || str_contains($m, 'backenderror')) {
            return ['category' => 'Server error (5xx)', 'retryable' => true, 'reason' => null];
        }

        // Lỗi lạ/không rõ → mặc định retryable=true để không mất lỗi mạng thật (an toàn hơn
        // là âm thầm bỏ file, vì đại đa số lỗi lạ trong thực tế là hiccup mạng/API tạm thời).
        return ['category' => 'Other', 'retryable' => true, 'reason' => null];
    }

    /**
     * BUG D: nhận diện lỗi filesystem CỤC BỘ (mkdir/fopen/md5_file()/filesize() thất bại, ổ đầy,
     * ổ read-only, I/O error khi ổ ngoài rớt kết nối…) — KHÁC HẲN lỗi Drive API mà
     * classifyDriveError() xử lý. Pure/static (không đụng $this/facade) để unit test không cần
     * boot Laravel — xem GDriveLocalFsErrorTest.
     *
     * Vì sao cần tách riêng khỏi classifyDriveError(): lỗi ổ đĩa cục bộ (unmount/read-only/hỏng)
     * KHÔNG tự khỏi giữa các lần retry cách nhau vài giây — khác hẳn lỗi Drive API (rate-limit,
     * 5xx) vốn CÓ cơ hội tự khỏi. Để lỗi này lọt qua classifyDriveError() sẽ bị nhánh string-match
     * "permission"/"forbidden" gán retryable=true (đúng cho Drive, sai cho ổ đĩa) → phí backoff.
     *
     * 🔴 NGUYÊN TẮC BẤT ĐỐI XỨNG (giống classifyDriveError): hai chiều đoán sai KHÔNG cân xứng —
     *   • Đoán nhầm lỗi Drive THÀNH local-FS → ABORT OAN cả run (mất mirror thật, NẶNG).
     *   • Đoán nhầm lỗi local-FS THÀNH Drive → chỉ phí vài lần retry vô ích (NHẸ, ~14s/file).
     * → Khi không chắc chắn, LUÔN nghiêng về FALSE (không phải local-FS). Vì vậy "permission
     *   denied" TRẦN (không kèm tên hàm PHP hay đường dẫn tuyệt đối) bị coi là KHÔNG PHẢI
     *   local-FS — chuỗi này cũng xuất hiện ở lỗi Drive 403 (forbidden/insufficient permissions).
     */
    public static function isLocalFsError(string $msg): bool
    {
        if ($msg === '') {
            return false;
        }
        // Guard belt-and-suspenders (L1-L3/C5 audit 2026-07-19): 1 tên bẩn/quá dài/bị
        // safeJoinLocalPath() từ chối KHÔNG BAO GIỜ được coi là bằng chứng ổ đĩa hỏng — dù các
        // marker bên dưới có được mở rộng sau này theo cách vô tình chồng lấn. Xem isInvalidLocalName().
        if (self::isInvalidLocalName($msg)) {
            return false;
        }
        $m = strtolower($msg);

        // Marker cứng — luôn là lỗi filesystem cục bộ dù không kèm gì khác. Drive API KHÔNG BAO
        // GIỜ trả các cụm này trong message JSON (message của Google luôn là câu tiếng Anh mô tả
        // lỗi quyền/quota/export, không phải lỗi đọc/ghi đĩa vật lý).
        $hardMarkers = [
            'input/output error',
            'errno=5',
            'read-only file system',
            'no space left',
        ];
        foreach ($hardMarkers as $marker) {
            if (str_contains($m, $marker)) {
                return true;
            }
        }

        // Tên hàm PHP filesystem xuất hiện trong message dạng "mkdir(): Permission denied" hay
        // "fopen(/path/...): Failed to open stream: ...". Message lỗi Drive API (JSON hoặc câu
        // tiếng Anh của Google) không bao giờ chứa "tên_hàm(" ngay sau tên hàm PHP như vậy.
        $fsFunctionMarkers = [
            'mkdir(', 'fopen(', 'md5_file(', 'filesize(', 'file_put_contents(',
            'file_get_contents(', 'rename(', 'unlink(', 'copy(', 'chmod(', 'touch(',
            'is_writable(', 'is_readable(', 'rmdir(', 'stream_copy_to_stream(', 'fwrite(',
        ];
        foreach ($fsFunctionMarkers as $marker) {
            if (str_contains($m, $marker)) {
                return true;
            }
        }

        // "permission denied" TRẦN (không có tên hàm PHP đi kèm) — CHỈ coi là local-FS khi kèm
        // một đường dẫn tuyệt đối (dấu hiệu rõ ràng đây là lỗi hệ điều hành, không phải Drive
        // API). Xem nguyên tắc bất đối xứng ở trên: không chắc chắn → nghiêng về false.
        if (str_contains($m, 'permission denied') && preg_match('#(?:^|[\s(:])/[\w./\-]+#', $msg) === 1) {
            return true;
        }

        return false;
    }

    /**
     * L1-L3/C5 audit (2026-07-19): nhận diện lỗi OS về chính cái TÊN (quá dài/EINVAL) hoặc lỗi
     * safeJoinLocalPath() từ chối (traversal/escape) — LUÔN permanent, và LUÔN KHÔNG PHẢI bằng
     * chứng ổ đĩa cục bộ đang hỏng (cùng nguyên tắc bất đối xứng ở docblock isLocalFsError(): 1
     * item tên bẩn không được phép ABORT OAN cả run và đổ lỗi cho phần cứng). Mirror
     * classify.IsInvalidLocalName() bản Go.
     */
    public static function isInvalidLocalName(string $msg): bool
    {
        if ($msg === '') {
            return false;
        }
        $m = strtolower($msg);

        if (str_contains($m, strtolower(self::SAFE_JOIN_ERROR_MARKER))) {
            return true;
        }
        foreach (self::INVALID_LOCAL_NAME_MARKERS as $marker) {
            if (str_contains($m, $marker)) {
                return true;
            }
        }
        // EINVAL trần ("invalid argument") quá chung chung để tin một mình — chỉ tính khi kèm 1
        // đường dẫn tuyệt đối, cùng guard mà isLocalFsError() áp cho "permission denied" trần.
        if (str_contains($m, 'invalid argument') && preg_match('#(?:^|[\s(:])/[\w./\-]+#', $msg) === 1) {
            return true;
        }

        return false;
    }

    /**
     * Làm cho $name AN TOÀN khi dùng làm 1 PATH COMPONENT ĐƠN (không tự chứa dấu phân cách thư
     * mục). Port 1:1 `localpath.SanitizeComponent()` bản Go (tools/gdrive-mirror-cli/internal/
     * localpath/localpath.go) — xem docblock ở đó cho lý do đầy đủ. Pure/static, không I/O.
     *
     *  1. Thay ký tự control và bất kỳ ký tự nào trong `< > : " | ? * \ /` bằng `_` (L2).
     *  2. Cắt dấu chấm/khoảng trắng CUỐI — Windows tự động bỏ chúng, nếu không xử lý thì 2 tên
     *     Drive khác nhau ("foo" và "foo.") sẽ đụng nhau âm thầm trên NTFS.
     *  3. Rỗng sau bước trên → fallback "_" — đây là cách "." và ".." (và tên toàn dấu chấm/toàn
     *     ký tự cấm) không bao giờ được trả về nguyên văn là "." hoặc ".." (filesystem sẽ hiểu
     *     thành self/parent-directory, không phải 1 tên thật).
     *  4. Tên thiết bị Windows dành riêng (CON, PRN, COM1…) được chèn "_" — xét PHẦN TRƯỚC dấu
     *     chấm ĐẦU TIÊN nên "CON.txt" cũng bị bắt (L3).
     */
    public static function sanitizeNameComponent(string $name): string
    {
        $replaced = str_replace(self::FORBIDDEN_NAME_CHARS, '_', $name);
        $replaced = (string) preg_replace('/[\x00-\x1F]/', '_', $replaced);
        $cleaned = rtrim($replaced, ". ");

        if ($cleaned === '') {
            return '_';
        }

        $dotPos = strpos($cleaned, '.');
        $base = $dotPos === false ? $cleaned : substr($cleaned, 0, $dotPos);
        $rest = $dotPos === false ? '' : substr($cleaned, $dotPos);

        if (in_array(strtoupper($base), self::WINDOWS_RESERVED_NAMES, true)) {
            return $base . '_' . $rest;
        }

        return $cleaned;
    }

    /**
     * Giới hạn $name còn tối đa 255 BYTE UTF-8 trừ $reserve (chỗ chừa cho thứ caller sẽ nối thêm
     * sau). Port 1:1 `localpath.TruncateComponent()` bản Go. Cắt LUÔN LUÔN đúng ranh giới ký tự
     * UTF-8 (cắt giữa ký tự sẽ phá hỏng ký tự nhiều byte — dấu tiếng Việt là 2-3 byte/ký tự). Khi
     * có cắt THẬT, nối "~" + 6 ký tự hex đầu của sha256(name) TRƯỚC phần mở rộng: 2 tên dài khác
     * nhau vô tình cùng tiền tố sau khi cắt KHÔNG được phép gộp thành 1 file local.
     *
     * 🔴 Bẫy ĐÃ BẮT ĐƯỢC khi port bản Go (audit 2026-07-18/19): `pathinfo(...,PATHINFO_EXTENSION)`
     * (tương đương `filepath.Ext` bên Go) lấy phần sau dấu chấm CUỐI CÙNG — với tên kiểu
     * "Bao cao Q1.2026 - <text dài>" (rất phổ biến ở VN) thì "phần mở rộng" chính là cả cái đuôi
     * dài, giữ nguyên nó verbatim đẩy kết quả VƯỢT 255 byte (đo thật: tên 311 byte ra 308 byte).
     * MAX_EXT_BYTES chặn bẫy này: quá ngưỡng thì coi như văn bản thường, cho cắt như phần còn lại.
     */
    public static function truncateNameComponent(string $name, int $reserve = 0): string
    {
        $limit = self::MAX_COMPONENT_BYTES - $reserve;
        if ($limit < 0) {
            $limit = 0;
        }
        if (strlen($name) <= $limit) {
            return $name;
        }

        $suffix = '~' . substr(hash('sha256', $name), 0, 6);

        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $ext = $ext !== '' ? '.' . $ext : '';
        if (strlen($ext) > self::MAX_EXT_BYTES) {
            $ext = '';
        }
        $base = $ext !== '' ? substr($name, 0, strlen($name) - strlen($ext)) : $name;

        // Nếu ngay cả suffix+ext cũng không vừa, bỏ luôn phần mở rộng, và nếu vẫn không vừa thì
        // hard-cut cả $name — trần byte là INVARIANT mà caller trông cậy vào, luôn thắng mọi mối
        // quan tâm thẩm mỹ (giữ extension) khác.
        if ($limit < strlen($suffix) + strlen($ext)) {
            $ext = '';
        }
        if ($limit < strlen($suffix)) {
            return self::truncateOnByteBoundary($name, $limit);
        }

        $base = self::truncateOnByteBoundary($base, $limit - strlen($suffix) - strlen($ext));

        return $base . $suffix . $ext;
    }

    /**
     * Cắt $s còn tối đa $n byte, LUI dần nếu byte tại vị trí cắt là continuation byte UTF-8
     * (10xxxxxx, tức 0x80-0xBF) — không bao giờ cắt giữa 1 ký tự nhiều byte. Tương đương
     * `truncateOnRuneBoundary()` bản Go (dùng utf8.RuneStart).
     */
    private static function truncateOnByteBoundary(string $s, int $n): string
    {
        if ($n <= 0) {
            return '';
        }
        if (strlen($s) <= $n) {
            return $s;
        }
        while ($n > 0 && (ord($s[$n]) & 0xC0) === 0x80) {
            $n--;
        }

        return substr($s, 0, $n);
    }

    /**
     * Nối $root và $relPath rồi ĐẢM BẢO kết quả nằm TRONG $root — ném InvalidArgumentException
     * nếu không. Port 1:1 `localpath.SafeJoin()` bản Go. Lớp phòng thủ CUỐI CÙNG chống 1 tên Drive
     * thoát khỏi --path hoàn toàn (finding C5) — dù sanitizeNameComponent() đã lọc "/" và
     * rtrim() đã khiến ".." không bao giờ sống sót như 1 component riêng lẻ.
     *
     * 🔴 Khác bản Go (dùng `filepath.Abs`+`filepath.Clean`, dựa vào syscall thật): PHP's
     * `realpath()` trả `false` khi path CHƯA TỒN TẠI trên đĩa (rất thường — item đang sync còn
     * chưa được tạo) → KHÔNG dùng được để chuẩn hoá. Chuẩn hoá bằng logic CHUỖI THUẦN
     * (normalizePathComponents(), tự resolve "."/".." theo segment) thay vì gọi hệ thống file.
     */
    public static function safeJoinLocalPath(string $root, string $relPath): string
    {
        $rootAbs = self::normalizePathComponents($root);
        $joined = self::normalizePathComponents($rootAbs . '/' . $relPath);

        if ($joined !== $rootAbs && ! str_starts_with($joined, $rootAbs . '/')) {
            throw new \InvalidArgumentException(
                self::SAFE_JOIN_ERROR_MARKER . ": path escapes root (root={$root} relPath={$relPath} resolved={$joined})"
            );
        }

        return $joined;
    }

    /**
     * Chuẩn hoá $path bằng logic CHUỖI THUẦN (không đụng filesystem — xem lý do ở safeJoinLocalPath()):
     * tách theo "/", bỏ segment rỗng/".", resolve ".." bằng cách pop segment liền trước (path
     * tương đối không pop được nữa thì GIỮ LẠI ".." — đúng ngữ nghĩa "chưa biết gốc ở đâu"; path
     * tuyệt đối thì DROP vì không thể đi lên trên "/"). $path không tuyệt đối được neo vào cwd
     * hiện tại trước khi xử lý (khớp `filepath.Abs` bản Go).
     */
    private static function normalizePathComponents(string $path): string
    {
        if ($path === '') {
            $path = '.';
        }
        if (! str_starts_with($path, '/')) {
            $path = rtrim((string) (getcwd() ?: '/'), '/') . '/' . $path;
        }

        $stack = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if (! empty($stack) && end($stack) !== '..') {
                    array_pop($stack);
                }
                // Tuyệt đối: ".." vượt quá gốc bị DROP (không thể đi lên trên "/"). Không có
                // nhánh "giữ lại .." vì $path ở đây LUÔN đã được neo tuyệt đối ở trên.
                continue;
            }
            $stack[] = $segment;
        }

        return '/' . implode('/', $stack);
    }

    /**
     * BUG D: quyết định có nên ABORT toàn bộ run vì quá nhiều lỗi filesystem cục bộ LIÊN TIẾP
     * hay không (circuit breaker). Pure/static (không đụng $this/facade) để unit test không cần
     * boot Laravel — xem GDriveLocalFsErrorTest. Cùng pattern với shouldAbortOnShrink().
     *
     * @param int $consecutive số lỗi filesystem cục bộ liên tiếp (chưa gặp item thành công nào xen giữa)
     * @param int $threshold   ngưỡng chặn (mặc định MAX_CONSECUTIVE_LOCAL_FS_ERRORS = 20)
     */
    public static function shouldAbortOnLocalFsErrors(int $consecutive, int $threshold = self::MAX_CONSECUTIVE_LOCAL_FS_ERRORS): bool
    {
        return $consecutive >= $threshold;
    }

    /**
     * Ghi-thử THẬT vào thư mục: tạo 1 file probe → ghi → đọc lại → xoá. Trả về true CHỈ khi cả
     * chuỗi thành công. Dùng cho preflight thay `is_writable()` — hàm chuẩn đó chỉ đọc bit quyền
     * nên KHÔNG bắt được ổ "báo ghi-được nhưng ghi-thật-lỗi" (ổ exFAT ngoài hỏng I/O: is_writable
     * =true nhưng file_put_contents/mkdir ném "Input/output error"). Suppress lỗi + bọc try/catch:
     * mọi trục trặc ghi/đọc/xoá đều quy về false (KHÔNG throw ra ngoài — đây là hàm kiểm tra).
     *
     * $token (dùng runId) để 2 run song song không đụng file probe của nhau.
     *
     * ⚠️ GIỚI HẠN (đo thật 2026-07-18): probe fail-fast khi ổ trả lỗi NGAY (errno=5, read-only) —
     * đó là ca của sự cố 7fe28ffb và là lúc probe hữu ích nhất (abort trước khi list Drive). NHƯNG
     * khi ổ wedged NẶNG hơn (I/O treo hẳn, đến `mkdir`/`diskutil unmount` cũng đơ), file_put_contents
     * cũng TREO — PHP không timeout được I/O file cục bộ mà không cần pcntl. Đây KHÔNG phải regression:
     * ổ treo thì code cũ cũng treo ở lần ghi thật đầu tiên, probe chỉ dời điểm treo lên preflight.
     * Ổ treo cứng = pathology phần cứng/OS, phải rút-cắm-lại/reboot; command không tự gỡ được.
     */
    public static function writeProbe(string $dir, string $token): bool
    {
        $probe = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.gdrive_write_probe_' . $token;
        try {
            if (@file_put_contents($probe, 'probe') === false) {
                return false;
            }
            $ok = (@file_get_contents($probe) === 'probe');
            @unlink($probe);

            return $ok;
        } catch (\Throwable $e) {
            @unlink($probe);

            return false;
        }
    }

    /**
     * BUG A-3: quyết định có nên ABORT một folder gốc vì item-count sụt bất thường so với lần
     * chạy trước hay không. Pure/static (không đụng $this/facade) để unit test không cần boot
     * Laravel — xem GDriveListingGuardTest.
     *
     * $prevCount === null (chưa từng chạy/chưa có state) hoặc === 0 (tránh chia cho 0, và lần
     * trước vốn đã 0 thì không có gì để "sụt" thêm) → luôn KHÔNG abort.
     *
     * @param int|null $prevCount    item-count lần chạy trước (null = chưa có state)
     * @param int      $currentCount item-count lần chạy này
     * @param float    $ratio       ngưỡng chấp nhận tối thiểu (0.8 = còn lại ≥80% mới KHÔNG abort)
     */
    public static function shouldAbortOnShrink(?int $prevCount, int $currentCount, float $ratio = self::LISTING_SHRINK_ABORT_RATIO): bool
    {
        if ($prevCount === null || $prevCount === 0) {
            return false;
        }

        return $currentCount < $prevCount * $ratio;
    }

    /**
     * Đọc state item-count lần chạy trước của 1 folder gốc (BUG A-3). Không có file/không đọc
     * được/JSON hỏng → coi như chưa có state (null), KHÔNG làm fail cả sync vì lý do này.
     */
    protected function readListingState(string $folderTag): ?array
    {
        $path = storage_path('app/gdrive-sync/state/' . $folderTag . '.json');
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : null;
    }

    /**
     * Ghi state item-count của lần chạy này (BUG A-3) — chỉ gọi khi listing đã được CHẤP NHẬN
     * (không bị abort). Lỗi ghi chỉ warn, không làm fail cả sync (state chỉ là lưới an toàn cho
     * lần chạy SAU, không phải dữ liệu bắt buộc cho lần chạy hiện tại).
     */
    protected function writeListingState(string $folderTag, string $folderId, int $itemCount): void
    {
        $stateDir = storage_path('app/gdrive-sync/state');
        if (! is_dir($stateDir) && ! @mkdir($stateDir, 0755, true) && ! is_dir($stateDir)) {
            $this->warn("⚠️  Could not create state dir: {$stateDir}");
            return;
        }

        $payload = [
            'folder_id' => $folderId,
            'item_count' => $itemCount,
            'listed_at' => date('c'),
            'run_id' => $this->runId,
        ];

        $path = $stateDir . DIRECTORY_SEPARATOR . $folderTag . '.json';
        if (@file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === false) {
            $this->warn("⚠️  Could not write listing state: {$path}");
        }
    }

    /**
     * Recursively list a folder via Drive API. Returns items in masbug-compatible shape
     * (arrays with type/path/id/mimeType/md5Checksum/timestamp). Used in service-account mode
     * because masbug's listContents on a folder-id root returns nothing.
     *
     * 🔴 BUG A (đã chứng minh bằng dữ liệu thật): Drive `files.list` thỉnh thoảng trả mảng
     * RỖNG cho một folder CÓ con (nghi do áp lực/rate-limit khi nhiều job gọi API song song —
     * đo được: cùng 1 folder lúc 0 con, lúc 3041 con). fetchFolderChildrenViaApi() tin ngay kết
     * quả rỗng thì bỏ nguyên cây con mà KHÔNG có dấu vết gì (không exception, không file lỗi).
     * → Ở đây, nếu 1 lần gọi trả về 0 item, XÁC MINH LẠI 1 lần trước khi chấp nhận là thật.
     * Chi phí 1 request thừa (folder rỗng thật là hiếm) rẻ hơn rất nhiều so với mất cả cây con
     * trong im lặng. Áp dụng ở MỌI cấp đệ quy (không chỉ folder gốc) vì bug xảy ra ở subfolder.
     */
    protected function listFolderRecursiveViaApi($service, string $folderId, string $relativePath = ''): array
    {
        $items = $this->fetchFolderChildrenViaApi($service, $folderId, $relativePath);

        if (! empty($items)) {
            return $items;
        }

        $logPath = $relativePath !== '' ? $relativePath : '(root)';
        sleep(1);
        $secondItems = $this->fetchFolderChildrenViaApi($service, $folderId, $relativePath);

        if (empty($secondItems)) {
            // Folder rỗng thật (hợp lệ, không phải bug) — đừng spam warning ở mức log lỗi.
            Log::channel($this->log_channel)->info("GDrive Sync: folder xác nhận rỗng (0 item cả 2 lần)", [
                'run_id' => $this->runId,
                'path' => $logPath,
                'folder_id' => $folderId,
            ]);
            return [];
        }

        // Lần 2 ra >0 → đây chính là BUG A: lần liệt kê đầu bị Drive API cụt.
        $this->listingRetryHits++;
        Log::channel($this->log_channel)->warning("GDrive Sync: listing trả 0 nhưng verify lại có item — Drive API cụt", [
            'run_id' => $this->runId,
            'path' => $logPath,
            'folder_id' => $folderId,
            'second_count' => count($secondItems),
        ]);

        return $secondItems;
    }

    /**
     * Gom TOÀN BỘ trang con TRỰC TIẾP (không đệ quy) của $folderId qua Drive API — RAW, chưa qua
     * sanitize/dedup tên. Tách khỏi fetchFolderChildrenViaApi() (audit C1-C5/L1-L3, 2026-07-19)
     * để resolveSiblingNames() có đủ TOÀN BỘ tập anh em cùng cấp trước khi quyết định tên cuối —
     * thiết kế cũ (list-while-recurse) quyết định tên ngay khi gặp từng item nên KHÔNG BAO GIỜ
     * thấy được 1 anh em xuất hiện ở trang phân trang SAU, khiến quyết định dedup không ổn định
     * (C4). Mirror `collectRawChildren()` bản Go 1:1.
     *
     * GIỮ NGUYÊN withRetry() cho mỗi trang + hành vi "lỗi thì dừng phân trang nhưng vẫn trả về
     * những gì đã gom được" (BUG A-1/BUG B) — KHÔNG được ném exception giết cả sync.
     *
     * @return array<int, array{id:string,name:string,mimeType:string,md5Checksum:?string,timestamp:int,size:int,isFolder:bool}>
     */
    protected function collectRawChildrenViaApi($service, string $folderId, string $logPath): array
    {
        $raw = [];
        $pageToken = null;
        $query = sprintf("'%s' in parents and trashed = false", str_replace("'", "\\'", $folderId));

        do {
            // BUG A-1: đây là chỗ DUY NHẤT không được bọc withRetry() — mọi download đều có
            // retry, riêng khâu liệt kê thì trước đây không, nên 1 lỗi mạng thoáng qua rơi
            // thẳng ra ngoài. Nếu retry hết lượt vẫn lỗi (hoặc lỗi permanent), withRetry() đã
            // tự log đầy đủ — ở đây chỉ dừng phân trang, trả về những gì đã gom được thay vì
            // ném exception giết cả sync (xem BUG B).
            $response = $this->withRetry(function () use ($service, $query, $pageToken) {
                return $service->files->listFiles([
                    'q' => $query,
                    'pageSize' => 1000,
                    'fields' => 'nextPageToken, files(id, name, mimeType, modifiedTime, md5Checksum, size)',
                    'pageToken' => $pageToken,
                    'supportsAllDrives' => true,
                    'includeItemsFromAllDrives' => true,
                ]);
            }, $logPath);

            if ($response === false) {
                break;
            }

            foreach ($response->getFiles() as $file) {
                $raw[] = [
                    'id' => $file->getId(),
                    'name' => $file->getName(),
                    'mimeType' => $file->getMimeType(),
                    'md5Checksum' => $file->getMd5Checksum(),
                    'timestamp' => $file->getModifiedTime() ? strtotime($file->getModifiedTime()) : 0,
                    'size' => (int) ($file->getSize() ?? 0),
                    'isFolder' => $file->getMimeType() === 'application/vnd.google-apps.folder',
                ];
            }

            $pageToken = $response->getNextPageToken();
        } while ($pageToken);

        return $raw;
    }

    /**
     * Liệt kê 1 lượt (đủ phân trang) các con TRỰC TIẾP của $folderId, quyết định tên cuối AN
     * TOÀN + KHÔNG TRÙNG cho toàn bộ tập anh em (resolveSiblingNames() — chung 1 namespace
     * file+folder, khoá theo Drive ID tăng dần, fixes C1-C4), RỒI MỚI đệ quy vào từng folder con
     * bằng path đã resolve. Tách khỏi listFolderRecursiveViaApi() để lời gọi có thể được lặp lại
     * (verify-on-zero ở trên) mà không đệ quy lại chính nó.
     *
     * 🔴 Tái cấu trúc (audit C1-C5/L1-L3, 2026-07-19) từ thiết kế list-while-recurse cũ: tên được
     * quyết định ngay trong lúc vẫn còn đang phân trang khiến 1 anh em trùng tên xuất hiện ở
     * trang SAU không bao giờ được nhìn thấy → quyết định dedup không thể ổn định. Gom đủ tập
     * anh em TRƯỚC (collectRawChildrenViaApi()) là điều kiện bắt buộc để dedup xác định được
     * (resolveSiblingNames()).
     *
     * `path` của mỗi item trả về giờ đã là local path AN TOÀN (đã sanitize + truncate + dedup) —
     * caller (handle()) chỉ cần nối với $baseLocalPath qua safeJoinLocalPath() làm lớp phòng thủ
     * cuối, KHÔNG cần tự sanitize gì thêm.
     */
    protected function fetchFolderChildrenViaApi($service, string $folderId, string $relativePath): array
    {
        $logPath = $relativePath !== '' ? $relativePath : '(root)';

        $raw = $this->collectRawChildrenViaApi($service, $folderId, $logPath);
        $resolved = self::resolveSiblingNames($raw);

        $items = [];
        foreach ($resolved as $child) {
            if ($child['collided']) {
                $this->listingCollisions++;
                $this->warn("\n   ⚠️ Trùng tên trong '{$logPath}': '{$child['name']}' (id={$child['id']}) phải đổi thành '{$child['localName']}' để không ghi đè/gộp với anh em cùng cấp.");
                Log::channel($this->log_channel)->warning("GDrive Sync: name collision resolved at listing time", [
                    'run_id' => $this->runId,
                    'parent_path' => $logPath,
                    'original_name' => $child['name'],
                    'resolved_name' => $child['localName'],
                    'id' => $child['id'],
                ]);
            }

            $childPath = $relativePath !== '' ? $relativePath . '/' . $child['localName'] : $child['localName'];

            $items[] = [
                'type' => $child['isFolder'] ? 'dir' : 'file',
                'path' => $childPath,
                'id' => $child['id'],
                'mimeType' => $child['mimeType'],
                'md5Checksum' => $child['md5Checksum'],
                'timestamp' => $child['timestamp'],
                'size' => $child['size'],
            ];

            if ($child['isFolder']) {
                foreach ($this->listFolderRecursiveViaApi($service, $child['id'], $childPath) as $descendant) {
                    $items[] = $descendant;
                }
            }
        }

        return $items;
    }

    /**
     * Quyết định tên local CUỐI CÙNG, an toàn-trên-mọi-OS, KHÔNG TRÙNG cho MỌI con TRỰC TIẾP của
     * 1 folder cha — MỘT namespace chung cho cả file LẪN folder (1 folder và 1 file trùng tên
     * đụng nhau y hệt 2 file trùng tên — fixes C2: file ghi đè lên thư mục; C3: 2 folder gộp nội
     * dung âm thầm). Pure/static (không gọi API) → test được không cần Drive thật, xem
     * GDriveResolveSiblingNamesTest.
     *
     * Thứ tự quyết định theo DRIVE FILE ID TĂNG DẦN — KHÔNG theo thứ tự `files.list` trả về: Drive
     * không đảm bảo thứ tự ổn định giữa 2 lần gọi. Quyết định theo thứ tự-đến-trước (arrival
     * order) nghĩa là "kẻ thua" của 1 va chạm đổi tùy lúc Drive trả kết quả khác thứ tự — và vì
     * tool này KHÔNG BAO GIỜ xoá file local, mỗi lần đổi để lại 1 bản rác mồ côi VĨNH VIỄN
     * (fixes C4).
     *
     * Khoá dedup viết THƯỜNG vì ổ đích có thể là NTFS/APFS — cả 2 đều KHÔNG phân biệt hoa/thường
     * theo mặc định: 2 tên Drive chỉ khác hoa/thường là 2 file KHÁC NHAU với Drive nhưng là CÙNG 1
     * file trên đĩa đó — không xử lý sẽ âm thầm ghi đè (fixes C1).
     *
     * @param array<int, array{id:string,name:string,mimeType:string,md5Checksum:?string,timestamp:int,size:int,isFolder:bool}> $rawChildren
     * @return array<int, array{id:string,name:string,mimeType:string,md5Checksum:?string,timestamp:int,size:int,isFolder:bool,localName:string,collided:bool}>
     */
    public static function resolveSiblingNames(array $rawChildren): array
    {
        $sorted = $rawChildren;
        usort($sorted, static fn (array $a, array $b) => $a['id'] <=> $b['id']);

        $used = [];
        $resolved = [];

        foreach ($sorted as $child) {
            $name = self::sanitizeNameComponent((string) $child['name']);
            // 'isFolder' được collectRawChildrenViaApi() điền sẵn, nhưng suy lại từ mimeType khi
            // thiếu để hàm thuần này dùng được với mảng item thô bất kỳ (test/retry-failed) mà
            // không phát warning "Undefined array key" trên PHP 8.
            $isFolder = $child['isFolder'] ?? ($child['mimeType'] === 'application/vnd.google-apps.folder');
            $child['isFolder'] = $isFolder;
            if (! $isFolder) {
                $exportSpec = self::EXPORT_MAP[$child['mimeType']] ?? null;
                if ($exportSpec) {
                    $name .= '.' . $exportSpec['ext'];
                }
            }
            // reserve=0: PHP giờ CŨNG ghi atomic qua file tạm rồi rename() (xem docblock đầu file
            // mục 3 + atomicTempPath()), NHƯNG tên file tạm là HASH của $targetLocalPath (độ dài
            // cố định), KHÔNG nối vào $name — giống hệt cách bản Go tránh vấn đề này ở
            // tempDownloadPath() — nên vẫn không cần chừa byte cho bất kỳ hậu tố tạm nào ở đây.
            $name = self::truncateNameComponent($name, 0);

            $key = mb_strtolower($name);
            $collided = isset($used[$key]);
            if ($collided) {
                $name = self::disambiguateLocalName($name, (string) $child['id'], $used);
            }
            $used[mb_strtolower($name)] = true;

            $resolved[] = $child + ['localName' => $name, 'collided' => $collided];
        }

        return $resolved;
    }

    /**
     * Chèn (dần nới rộng) Drive file ID của item ĐỤNG HÀNG vào trước phần mở rộng cho tới khi kết
     * quả không trùng ai trong $used. Bắt đầu 8 ký tự (khớp convention hậu tố cũ của tool —
     * "file.pdf" → "file_1JP7CIBW.pdf"); chỉ nới rộng khi 8 ký tự đầu ID CŨNG đụng (cực hiếm), và
     * fallback bộ đếm số nếu cả ID đầy đủ cũng đụng (Drive ID vốn unique nên nhánh này không thể
     * xảy ra thật — tồn tại chỉ để chứng minh vòng lặp CHẮC CHẮN dừng).
     */
    private static function disambiguateLocalName(string $name, string $id, array $used): string
    {
        $idLen = strlen($id);
        // Bắt đầu ở min(8, $idLen), KHÔNG phải 8 cứng: một ID ngắn hơn 8 ký tự làm vòng lặp
        // không chạy lần nào và rơi thẳng xuống nhánh bộ đếm bên dưới — tên nhận hậu tố xấu
        // ("_ID-2") dù nhánh ID hoàn toàn đủ dùng. (Bắt được khi review port PHP 2026-07-19;
        // bản Go có y hệt khiếm khuyết này và đã sửa cùng lúc.)
        for ($n = min(8, $idLen); $n <= $idLen; $n += 4) {
            $candidate = self::withIdSuffix($name, $id, $n);
            if (! isset($used[mb_strtolower($candidate)])) {
                return $candidate;
            }
        }
        for ($i = 2; ; $i++) {
            // Bộ đếm phải nằm TRƯỚC phần mở rộng như mọi hậu tố khác — nối vào cuối tên sẽ ra
            // "bao cao_ID.pdf-2", tức file mất luôn đuôi .pdf và OS/Explorer không còn nhận ra
            // kiểu file nữa. (Cùng đợt review 2026-07-19.)
            $candidate = self::withSuffixBeforeExt($name, '_' . $id . '-' . $i);
            if (! isset($used[mb_strtolower($candidate)])) {
                return $candidate;
            }
        }
    }

    private static function withIdSuffix(string $name, string $id, int $n): string
    {
        return self::withSuffixBeforeExt($name, '_' . substr($id, 0, min($n, strlen($id))));
    }

    /**
     * Chèn $suffix vào NGAY TRƯỚC phần mở rộng của $name rồi cap lại về trần 255 byte.
     * Dùng chung cho mọi kiểu hậu tố chống trùng (ID rút gọn, ID đầy đủ, bộ đếm) để không
     * chỗ nào lỡ tay nối ra sau đuôi file.
     */
    private static function withSuffixBeforeExt(string $name, string $suffix): string
    {
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $ext = $ext !== '' ? '.' . $ext : '';
        $base = $ext !== '' ? substr($name, 0, strlen($name) - strlen($ext)) : $name;

        return self::truncateNameComponent($base . $suffix . $ext, 0);
    }

    /**
     * ── Atomic write helpers (2026-07-19) ───────────────────────────────────────────────────
     * Write-then-rename cho MỌI đường ghi nội dung file mirror — xem docblock đầu file mục 3/4.
     * Port cùng tinh thần `tempDownloadPath()`/`downloadItem()` bản Go (tools/gdrive-mirror-cli/
     * internal/mirror/download.go), khác ở chỗ PHP dùng callback cho phần "đổ nội dung" vì 2
     * điểm gọi (streamDownloadViaApi() và nhánh stream_copy_to_stream() thường) có cách ghi
     * khác nhau (fwrite theo chunk thủ công vs stream_copy_to_stream) nhưng cả hai cần chung 1
     * cơ chế mở-file-tạm/rename/dọn-khi-lỗi — gom về đây để không lệch nhau giữa 2 chỗ.
     */

    /**
     * Đường dẫn file tạm dùng cho ghi atomic — CÙNG THƯ MỤC với $targetLocalPath (để rename()
     * luôn nằm trong cùng filesystem, không rơi vào lỗi EXDEV cross-device) nhưng tên có ĐỘ DÀI
     * CỐ ĐỊNH, KHÔNG nối vào tên đích: dùng hash($targetLocalPath) để định danh duy nhất thay vì
     * chính tên đích. Nối trực tiếp vào tên đích (vd cũ "$targetLocalPath . '.tmp-' . $runId")
     * sẽ cộng thêm byte lên 1 tên component có thể đã sát trần 255 byte (xem
     * truncateNameComponent()) — mirror đúng cách tempDownloadPath() bản Go tránh lỗi này
     * (finding L1, xem download.go). $runId (không phải $this->runId) để hàm test được thuần —
     * xem GDriveAtomicWriteTest.
     */
    public static function atomicTempPath(string $targetLocalPath, string $runId): string
    {
        $dir = dirname($targetLocalPath);
        $name = '.' . substr(hash('sha256', $targetLocalPath), 0, 8) . '-' . $runId . '.tmp';

        return rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name;
    }

    /**
     * Nhận diện tên file có khớp ĐÚNG mẫu file tạm atomic-write của CHÍNH TOOL NÀY hay không
     * (xem atomicTempPath()): '.' + 8 hex (hash rút gọn target path) + '-' + 8 hex (run id,
     * xem `$this->runId = substr(uniqid(), -8)`) + '.tmp'. Dùng bởi cleanupStaleAtomicTempFiles()
     * để quyết định file nào ĐƯỢC PHÉP xoá. Pure/static để unit test không cần I/O — xem
     * GDriveAtomicWriteTest.
     *
     * Cố ý CHẶT (không dùng regex lỏng kiểu '.*\.tmp$'): tool này KHÔNG BAO GIỜ được xoá nhầm
     * file của người dùng (nguyên tắc "KHÔNG xoá file local" ở docblock đầu file) — "abc.tmp"
     * (thiếu dấu chấm dẫn đầu + hash/runId), ".hidden" (không khớp phần đuôi), hay
     * ".12345678-abcdef01.tmp.pdf" (còn đuôi thật SAU .tmp) đều PHẢI bị từ chối.
     */
    public static function isOwnAtomicTempFilename(string $basename): bool
    {
        return preg_match('/^\.[0-9a-f]{8}-[0-9a-f]{8}\.tmp$/', $basename) === 1;
    }

    /**
     * Ghi atomic phần content ĐÃ CÓ SẴN trong RAM (nhánh export Google Native — response body
     * export() đã là 1 string đầy đủ, không stream theo chunk) — cùng cơ chế file tạm + rename
     * với atomicWriteStream() bên dưới, chỉ khác cách đổ nội dung (File::put 1 lần).
     */
    protected function atomicPutContents(string $targetLocalPath, string $contents): void
    {
        File::ensureDirectoryExists(dirname($targetLocalPath));
        $tmpPath = self::atomicTempPath($targetLocalPath, $this->runId);

        try {
            File::put($tmpPath, $contents);
            rename($tmpPath, $targetLocalPath);
        } catch (\Throwable $e) {
            @unlink($tmpPath);
            throw $e;
        }
    }

    /**
     * Ghi atomic theo stream: mở file tạm (xem atomicTempPath()), gọi $writer($handle) để đổ
     * nội dung, rồi rename() vào đúng path đích CHỈ KHI $writer chạy xong KHÔNG lỗi. Lỗi bất kỳ
     * lúc nào (mở file tạm / ghi / rename) → xoá file tạm, KHÔNG đụng file đích cũ (file cũ dù
     * lệch còn hơn null — xem docblock đầu file mục 3/4).
     *
     * KHÔNG tự bắt lỗi mở/ghi/rename thành 1 loại riêng: để nguyên Throwable gốc (ErrorException
     * từ fopen()/fwrite()/rename() thất bại) đi lên withRetry() — message vẫn chứa tên hàm PHP
     * gốc ("fopen(", "fwrite(", "rename(" đều đã có sẵn trong fsFunctionMarkers của
     * isLocalFsError()) nên vẫn được phân loại/đếm circuit-breaker ĐÚNG như lỗi ghi file hiện
     * nay — không cần sửa gì ở classifyDriveError()/isLocalFsError().
     */
    protected function atomicWriteStream(string $targetLocalPath, callable $writer): void
    {
        File::ensureDirectoryExists(dirname($targetLocalPath));
        $tmpPath = self::atomicTempPath($targetLocalPath, $this->runId);

        try {
            $handle = fopen($tmpPath, 'w');
            try {
                $writer($handle);
            } finally {
                fclose($handle);
            }
            rename($tmpPath, $targetLocalPath);
        } catch (\Throwable $e) {
            @unlink($tmpPath);
            throw $e;
        }
    }

    /**
     * Dọn file tạm atomic-write (xem atomicTempPath()) SÓT LẠI từ 1 run TRƯỚC bị crash/kill
     * giữa chừng — chạy 1 lần ở ĐẦU run (preflight, xem handle()), quét ĐỆ QUY trong
     * $baseLocalPath của lần chạy này. CHỈ xoá file khớp ĐÚNG mẫu isOwnAtomicTempFilename() VÀ
     * cũ hơn STALE_ATOMIC_TMP_AGE_SECONDS — ngưỡng đủ rộng để không xoá nhầm file tạm của 1 run
     * KHÁC đang chạy song song trên cùng $baseLocalPath (xem docblock hằng số).
     *
     * 🔴 Vi phạm "KHÔNG xoá file local" (docblock đầu file) là lỗi nghiêm trọng nhất tool này có
     * thể mắc — vì vậy: (1) match tên bằng regex CHẶT trước, KHÔNG suy đoán qua mtime/kích thước
     * đơn thuần; (2) bỏ qua symlink (không theo link ra ngoài cây mirror); (3) mọi lỗi quét/xoá
     * đều bị nuốt + log, KHÔNG fatal cả run — dọn rác thất bại thì để lại cho lần sau, không
     * đáng để phá cả lần sync.
     */
    protected function cleanupStaleAtomicTempFiles(string $baseLocalPath): void
    {
        if (! is_dir($baseLocalPath)) {
            return;
        }

        $now = time();
        $removed = 0;

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($baseLocalPath, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($iterator as $fileInfo) {
                /** @var \SplFileInfo $fileInfo */
                if ($fileInfo->isLink() || ! $fileInfo->isFile()) {
                    continue;
                }
                if (! self::isOwnAtomicTempFilename($fileInfo->getFilename())) {
                    continue;
                }
                $mtime = @filemtime($fileInfo->getPathname());
                if ($mtime === false || ($now - $mtime) < self::STALE_ATOMIC_TMP_AGE_SECONDS) {
                    continue;
                }
                if (@unlink($fileInfo->getPathname())) {
                    $removed++;
                }
            }
        } catch (\Throwable $e) {
            // Quét thư mục lỗi (permission/I-O chập chờn trên ổ ngoài) KHÔNG được làm fatal cả
            // run chỉ vì dọn rác thất bại — bỏ qua, để lại cho lần chạy sau.
            Log::channel($this->log_channel)->warning("GDrive Sync: stale atomic temp cleanup scan error", [
                'run_id' => $this->runId,
                'base_local_path' => $baseLocalPath,
                'error' => $e->getMessage(),
            ]);
            return;
        }

        if ($removed > 0) {
            Log::channel($this->log_channel)->info("GDrive Sync: cleaned up stale atomic temp files", [
                'run_id' => $this->runId,
                'base_local_path' => $baseLocalPath,
                'removed' => $removed,
                'age_threshold_seconds' => self::STALE_ATOMIC_TMP_AGE_SECONDS,
            ]);
        }
    }

    /**
     * Stream-download a regular (non Google-native) file via Drive API to disk.
     * Avoids loading the whole file into memory.
     */
    protected function streamDownloadViaApi($service, string $fileId, string $targetLocalPath): void
    {
        $httpClient = $service->getClient()->authorize();
        $url = sprintf('https://www.googleapis.com/drive/v3/files/%s?alt=media&supportsAllDrives=true', urlencode($fileId));

        $response = $httpClient->request('GET', $url, ['stream' => true]);
        $status = $response->getStatusCode();

        // 🔴 Request này đi thẳng qua Guzzle RAW (KHÔNG qua Resource::call()/
        // REST::decodeHttpResponse() — nơi google/apiclient tự check status + throw
        // Google\Service\Exception). Google\Client::createDefaultHttpClient() set
        // 'http_errors' => false khi dựng Guzzle client, nên Guzzle IM LẶNG ở 4xx/5xx thay vì
        // ném exception. Bug thực tế: 403 "Only files with binary content can be downloaded"
        // (shortcut/Docs-Editors) bị ghi thẳng JSON lỗi lên đĩa như thể là nội dung file thật,
        // rồi command báo "thành công". Phải tự kiểm status tay ở đây.
        if ($status < 200 || $status >= 300) {
            // Giới hạn 8KB — đủ chứa JSON lỗi của Google, không tốn RAM đọc hết body khi lỗi.
            self::assertDownloadOk($status, substr((string) $response->getBody(), 0, 8192));
        }

        // 🔴 Ghi ATOMIC (xem atomicWriteStream() ở trên): file tạm chỉ được mở SAU khi đã xác
        // nhận status OK ở trên, và file ĐÍCH hoàn toàn không bị đụng cho tới khi rename() thành
        // công — khác hẳn `fopen($targetLocalPath, 'w')` cũ (TRUNCATE thẳng file đích, phá file
        // tốt sẵn có thành rỗng nếu code chạy tới đây mà request lỗi — dù nhánh đó đã được chặn
        // bởi status-check ở trên, atomic write vẫn triệt để hơn: không còn cách nào chạm tới
        // file đích ngoài đường rename() sau khi ghi xong).
        $body = $response->getBody();
        $this->atomicWriteStream($targetLocalPath, function ($handle) use ($body) {
            while (! $body->eof()) {
                fwrite($handle, $body->read(8192));
            }
        });
    }

    /**
     * Kiểm status code của response tải qua streamDownloadViaApi() và ném lỗi nếu KHÔNG phải
     * 2xx. Tách riêng pure/static (không đụng $this/facade) để unit test được mà không cần
     * dựng response HTTP thật — xem GDriveErrorClassificationTest.
     *
     * Message ném ra là RAW BODY (JSON lỗi của Google) để classifyDriveError() parse được
     * `error.errors[0].reason` — giữ đúng contract với withRetry()/finalReport() đang dùng.
     *
     * @throws \RuntimeException khi $statusCode không phải 2xx
     */
    public static function assertDownloadOk(int $statusCode, string $body): void
    {
        if ($statusCode >= 200 && $statusCode < 300) {
            return;
        }
        throw new \RuntimeException($body);
    }

    /**
     * Resolve service account JSON file path. Returns null if not present/readable.
     * Priority: env GOOGLE_SERVICE_ACCOUNT_JSON_LOCATION → config('google.service.file') → config_path default.
     */
    protected function resolveServiceAccountFile(): ?string
    {
        $candidates = array_filter([
            env('GOOGLE_SERVICE_ACCOUNT_JSON_LOCATION'),
            function_exists('config') ? config('google.service.file') : null,
            function_exists('config_path') ? config_path('google-service-account-credentials.json') : null,
        ]);

        foreach ($candidates as $path) {
            $resolved = str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : (function_exists('config_path') ? config_path($path) : $path);
            if (is_file($resolved) && is_readable($resolved)) {
                return $resolved;
            }
        }
        return null;
    }

    /**
     * Initializing the Google Drive Disk Config
     */
    protected function initGoogleDisk($specificFolderId = null, $diskName = 'google_drive_mirror')
    {
        $serviceAccountFile = $this->resolveServiceAccountFile();
        $this->serviceAccountFile = $serviceAccountFile;

        if ($serviceAccountFile) {
            $config = [
                'driver' => 'google',
                'serviceAccountFile' => $serviceAccountFile,
            ];
        } else {
            $config = [
                'driver' => 'google',
                'clientId' => $this->getGdriveSetting('social_login_google_app_id', 'GOOGLE_DRIVE_CLIENT_ID'),
                'clientSecret' => $this->getGdriveSetting('social_login_google_app_secret', 'GOOGLE_DRIVE_CLIENT_SECRET'),
                'refreshToken' => $this->getGdriveSetting('social_login_google_drive_refresh_token', 'GOOGLE_DRIVE_REFRESH_TOKEN'),
            ];
        }

        if ($specificFolderId) {
            $config['folder'] = $specificFolderId;
        }

        config(["filesystems.disks.{$diskName}" => $config]);

        // Force Laravel to forget the disk instance so it picks up the new config
        if (app()->resolved('filesystem')) {
            Storage::forgetDisk($diskName);
        }
    }

    /**
     * Display final report
     */

    protected function finalReport($stats, $baseLocalPath, array $targetIdentifiers = [], float $startedAt = 0.0)
    {
        $permanentCount = 0;
        $retryableCount = 0;
        foreach ($stats['failed_files'] as $f) {
            if (! empty($f['permanent'])) {
                $permanentCount++;
            } else {
                $retryableCount++;
            }
        }
        $durationSec = $startedAt > 0 ? round(microtime(true) - $startedAt, 2) : null;

        // Manifest KHÔNG-thể-mirror: tính TRƯỚC log summary (để log summary có path) và luôn
        // gọi (kể cả failed_files rỗng) — folder trước đó có permanent-fail nhưng run này đã
        // hết lỗi thì manifest cũ phải được XOÁ, không chỉ khi có lỗi mới.
        $folderTag = $targetIdentifiers ? substr(md5(implode(',', $targetIdentifiers)), 0, 8) : 'unknown';
        $unexportableManifestPath = $this->writeUnexportableManifest($stats['failed_files'], $folderTag, $targetIdentifiers);

        $this->info("\n" . str_repeat("=", 50));
        $this->info("✨ MIRROR SYNC COMPLETED");
        $this->info(str_repeat("=", 50));
        $this->comment("📂 Folders Created:  {$stats['folders']}");
        $this->comment("✅ Files Updated:    {$stats['updated']}");
        $this->comment("⏭️ Files Skipped:    {$stats['skipped']}");
        $this->comment("⚠️ Collisions:       {$stats['collisions']}");
        $this->comment("❌ Errors encountered: {$stats['errors']} (permanent: {$permanentCount}, retryable: {$retryableCount})");

        if ($this->listingRetryHits > 0) {
            $this->warn("⚠️ Listing trả 0 sai {$this->listingRetryHits} lần (đã tự verify + khắc phục) — Drive API không ổn định, mirror lần này vẫn đủ.");
        }

        // Log Summary
        Log::channel($this->log_channel)->info("GDrive Mirror Sync COMPLETED", [
            'run_id' => $this->runId,
            'folders' => $stats['folders'],
            'updated' => $stats['updated'],
            'skipped' => $stats['skipped'],
            'errors'  => $stats['errors'],
            'collisions' => $stats['collisions'],
            'permanent_count' => $permanentCount,
            'path'    => $baseLocalPath,
            'duration_sec' => $durationSec,
            'unexportable_manifest' => $unexportableManifestPath,
            'listing_retry_hits' => $this->listingRetryHits,
            'item_count' => $stats['total_listed'] ?? 0,
        ]);

        if (!empty($stats['failed_files'])) {
            // Group failed files by error category for quick triage.
            $grouped = [];
            foreach ($stats['failed_files'] as $f) {
                $cat = $f['category'] ?? $this->categorizeError($f['reason'] ?? '');
                $grouped[$cat][] = $f;
            }

            $this->error("\n🔴 LIST OF FAILED FILES (grouped by error type)");
            foreach ($grouped as $category => $files) {
                $count = count($files);
                $this->warn("\n  ▸ {$category} ({$count} file" . ($count > 1 ? 's' : '') . ")");
                foreach ($files as $index => $file) {
                    $num = $index + 1;
                    $name = $file['path'] ?? ($file['name'] ?? '?'); // tolerate old key for backward compat
                    $this->line("    {$num}. {$name}");
                    $this->line("       ↳ ID: {$file['id']} | Attempts: " . ($file['attempts'] ?? '?') . " | Size: " . $this->humanSize((int) ($file['size'] ?? 0)));
                    $this->line("       ↳ Reason: {$file['reason']}");
                }
            }

            // Persist failed list as JSON for automated retry (--retry-failed).
            $reportPath = $this->writeFailedReport($stats['failed_files'], $baseLocalPath, $targetIdentifiers);
            if ($reportPath) {
                $this->info("\n📄 Failed-list saved to:");
                $this->info("   {$reportPath}");
                $this->info("   → Retry with: php artisan gdrive:mirror:sync --retry-failed=\"{$reportPath}\" --path=\"{$baseLocalPath}\"");
            }

            // Spreadsheet TỰ ĐỘNG (mặc định BẬT, không cần cờ) — dễ mở/lọc bằng Excel hơn JSON.
            $spreadsheetPath = $this->writeFailedSpreadsheet($stats['failed_files'], $baseLocalPath, $targetIdentifiers);
            if ($spreadsheetPath) {
                $this->info("📊 Bảng file lỗi: {$spreadsheetPath}");
                Log::channel($this->log_channel)->info("GDrive Sync: đã ghi bảng file lỗi", [
                    'run_id' => $this->runId,
                    'path' => $spreadsheetPath,
                    'row_count' => count($stats['failed_files']),
                ]);
            }

            // Log full error list for persistence
            Log::channel($this->log_channel)->error("GDrive Sync: List of failed files", [
                'failed_files' => $stats['failed_files'],
                'report_path' => $reportPath ?? null,
            ]);
        }

        if ($unexportableManifestPath) {
            $this->warn("\n⚠️  {$permanentCount} file KHÔNG THỂ mirror (permanent) — chi tiết + link tải tay:");
            $this->warn("   {$unexportableManifestPath}");
        }

        $this->info("\n" . str_repeat("=", 50));
        $this->info("Storage: {$baseLocalPath}");
    }

    /**
     * Categorize a Drive API error message into a coarse bucket for grouping.
     * Thin wrapper kept for backward-compat (may still be called elsewhere) — the real
     * classification logic (JSON reason-based, retryable/permanent) lives in classifyDriveError().
     */
    protected function categorizeError(string $msg): string
    {
        return self::classifyDriveError($msg)['category'];
    }

    /**
     * Write failed-files list as JSON for `--retry-failed`. Returns the absolute path,
     * or null if write failed.
     */
    protected function writeFailedReport(array $failedFiles, string $baseLocalPath, array $targetIdentifiers = []): ?string
    {
        if (empty($failedFiles)) {
            return null;
        }

        $reportDir = storage_path('app/gdrive-sync/failed');
        if (! is_dir($reportDir) && ! @mkdir($reportDir, 0755, true) && ! is_dir($reportDir)) {
            $this->warn("⚠️  Could not create report dir: {$reportDir}");
            return null;
        }

        $folderTag = $targetIdentifiers ? substr(md5(implode(',', $targetIdentifiers)), 0, 8) : 'unknown';
        $fileName = sprintf('failed-%s-%s.json', $folderTag, date('Ymd-His'));
        $absolutePath = $reportDir . DIRECTORY_SEPARATOR . $fileName;

        $permanentCount = 0;
        foreach ($failedFiles as $f) {
            if (! empty($f['permanent'])) {
                $permanentCount++;
            }
        }

        $payload = [
            'generated_at' => date('c'),
            'run_id' => $this->runId,
            'folder_ids' => array_values($targetIdentifiers),
            'base_local_path' => $baseLocalPath,
            'count' => count($failedFiles),
            'permanent_count' => $permanentCount,
            'retryable_count' => count($failedFiles) - $permanentCount,
            'items' => $failedFiles,
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (@file_put_contents($absolutePath, $json) === false) {
            $this->warn("⚠️  Could not write report file: {$absolutePath}");
            return null;
        }

        $this->pruneOldFailedReports($reportDir);

        return $absolutePath;
    }

    /**
     * Giữ tối đa MAX_FAILED_REPORTS report mới nhất trong thư mục failed/, xoá phần còn lại.
     * Trước đây tích luỹ vô hạn (đã thấy 3 file trùng byte-for-byte trong thực tế). Lỗi xoá
     * chỉ warn, không được làm fail cả command — report vừa ghi xong vẫn dùng được bình thường.
     */
    protected function pruneOldFailedReports(string $reportDir): void
    {
        $files = glob($reportDir . DIRECTORY_SEPARATOR . 'failed-*.json') ?: [];
        if (count($files) <= self::MAX_FAILED_REPORTS) {
            return;
        }

        usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));
        $toDelete = array_slice($files, self::MAX_FAILED_REPORTS);

        foreach ($toDelete as $file) {
            if (! @unlink($file)) {
                $this->warn("⚠️  Could not prune old report: {$file}");
            }
        }
    }

    /**
     * Build danh sách dòng cho spreadsheet file lỗi của MỘT RUN hiện tại ($stats['failed_files']).
     * KHÁC 2 script standalone tham khảo (export-failed-csv.php / export-failed-xlsx.php) vốn quét
     * TOÀN BỘ thư mục failed/*.json của nhiều lần chạy — ở đây chỉ có DUY NHẤT report của run vừa
     * xong, nên không cần bước gom "report mới nhất mỗi folder".
     *
     * Pure/static (không đụng $this/facade) để unit test không cần boot Laravel — cùng pattern
     * buildUnexportableManifest()/classifyDriveError().
     *
     * Thứ tự cột CỐ ĐỊNH (không phải thứ tự PHP tự nhiên) để spreadsheet ổn định giữa các lần
     * chạy: file, path, local_path, folder_root_id, loai, google_native, mime_type, size_bytes,
     * size, category, error_reason, permanent, attempts, error_message, drive_id, drive_link,
     * run_id, report_time, trung_file, dong_dai_dien.
     *
     * @param array<int, array<string, mixed>> $failedFiles $stats['failed_files'] của run hiện tại
     * @param array{base_local_path: string, folder_ids: array, run_id: string, generated_at: string} $meta
     * @return array<int, array<string, string>> LIST theo đúng thứ tự cột trên, đã sort
     *         permanent trước → category → size_bytes giảm dần.
     */
    public static function buildFailedRows(array $failedFiles, array $meta): array
    {
        if (empty($failedFiles)) {
            return [];
        }

        $base = rtrim((string) ($meta['base_local_path'] ?? ''), '/');
        $folders = implode(',', (array) ($meta['folder_ids'] ?? []));
        $runId = (string) ($meta['run_id'] ?? '');
        $reportTime = (string) ($meta['generated_at'] ?? '');

        $rows = [];
        foreach ($failedFiles as $item) {
            $path = (string) ($item['path'] ?? '');
            $mime = $item['mimeType'] ?? null;
            $isNative = $mime && str_starts_with((string) $mime, 'application/vnd.google-apps.');
            $id = (string) ($item['id'] ?? '');

            $rows[] = [
                'file' => $path === '' ? '' : basename($path),
                'path' => $path,
                'local_path' => $base !== '' && $path !== '' ? $base . '/' . $path : '',
                'folder_root_id' => $folders,
                'loai' => (string) ($item['type'] ?? ''),
                'google_native' => $isNative ? 'yes' : 'no',
                'mime_type' => (string) ($mime ?? ''),
                'size_bytes' => (string) ($item['size'] ?? 0),
                'size' => self::humanSize((int) ($item['size'] ?? 0)),
                'category' => (string) ($item['category'] ?? ''),
                'error_reason' => (string) ($item['error_reason'] ?? ''),
                'permanent' => ! empty($item['permanent']) ? 'yes' : 'no',
                'attempts' => (string) ($item['attempts'] ?? ''),
                // buildManualDownloadUrl() đã có sẵn cùng mapping mime→URL của script gốc
                // (docs.google.com/{type}/d cho Google Native, drive.google.com/file/d cho file
                // thường) — tái dùng thay vì viết lại driveLink() riêng.
                'error_message' => self::extractErrorMessage($item['reason'] ?? ''),
                'drive_id' => $id,
                'drive_link' => $id !== '' ? self::buildManualDownloadUrl($id, (string) ($mime ?? ''), $path)[0] : '',
                'run_id' => $runId,
                'report_time' => $reportTime,
            ];
        }

        // Cùng 1 file Drive có thể lặp qua nhiều folder gốc lồng nhau trong 1 run → đánh dấu để
        // lọc được cả hai cách thay vì tự ý bỏ dòng (bỏ = mất thông tin job nào đang thiếu file
        // đó — xem giải thích gốc trong export-failed-csv.php).
        $idCount = [];
        foreach ($rows as $r) {
            if ($r['drive_id'] !== '') {
                $idCount[$r['drive_id']] = ($idCount[$r['drive_id']] ?? 0) + 1;
            }
        }
        $seen = [];
        foreach ($rows as &$r) {
            $id = $r['drive_id'];
            $isDup = $id !== '' && ($idCount[$id] ?? 0) > 1;
            $r['trung_file'] = $isDup ? 'yes' : 'no';
            // Đúng 1 dòng/file được đánh 'yes' → lọc cột này = danh sách file RIÊNG BIỆT.
            $r['dong_dai_dien'] = (! $isDup || ! isset($seen[$id])) ? 'yes' : 'no';
            $seen[$id] = true;
        }
        unset($r);

        // Sắp: permanent trước (cần hành động thủ công), rồi theo category, rồi size giảm dần.
        usort($rows, static function (array $a, array $b) {
            return [$b['permanent'], $a['category'], (int) $b['size_bytes']]
                <=> [$a['permanent'], $b['category'], (int) $a['size_bytes']];
        });

        return $rows;
    }

    /**
     * Rút message người-đọc-được từ 'reason' (chuỗi JSON lỗi Google, hoặc raw string lỗi mạng/
     * filesystem). Tách riêng khỏi error_reason (machine key từ classifyDriveError()) — cột này
     * dành cho message hiển thị cho người dùng cuối đọc trong spreadsheet.
     */
    private static function extractErrorMessage($reason): string
    {
        if (! is_string($reason) || $reason === '') {
            return '';
        }
        $decoded = json_decode($reason, true);
        if (json_last_error() === JSON_ERROR_NONE && isset($decoded['error']['message'])) {
            return (string) $decoded['error']['message'];
        }

        // Không phải JSON (lỗi mạng/filesystem dạng chuỗi trần) → rút gọn 1 dòng.
        return trim(preg_replace('/\s+/', ' ', $reason));
    }

    /**
     * Render rows (từ buildFailedRows()) thành chuỗi CSV Excel-safe cho locale VN: BOM UTF-8 +
     * dấu phân cách `;`. Tách khỏi writeFailedSpreadsheet() (không đụng storage_path()/facade) để
     * unit test được mà không cần boot Laravel — xem GDriveFailedRowsTest.
     *
     * Vì sao `;` thay vì `,`: Excel trên macOS locale Việt Nam coi `,` là dấu thập phân, nên CSV
     * chuẩn dùng `,` bị Excel dồn hết vào 1 cột khi mở — đây là nguyên nhân chính user gặp phải.
     * BOM UTF-8 để Excel đọc đúng charset (không có BOM, Excel tự đoán theo locale hệ điều hành →
     * tiếng Việt trong path/tên file thành ký tự rác).
     *
     * @param array<int, array<string, string>> $rows từ buildFailedRows()
     */
    public static function renderFailedCsv(array $rows): string
    {
        if (empty($rows)) {
            return '';
        }

        $fh = fopen('php://temp', 'r+');
        fwrite($fh, "\xEF\xBB\xBF");
        // escape='' — PHP 8.4 deprecate giá trị mặc định; escape kiểu backslash của PHP vốn KHÔNG
        // chuẩn CSV và làm hỏng path chứa dấu \. Ép rỗng = quote-only đúng RFC 4180.
        fputcsv($fh, array_keys($rows[0]), ';', '"', '');
        foreach ($rows as $row) {
            fputcsv($fh, array_values($row), ';', '"', '');
        }
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);

        return (string) $csv;
    }

    /**
     * Ghi spreadsheet danh sách file lỗi của run hiện tại — TỰ ĐỘNG mỗi lần chạy có failed_files,
     * KHÔNG cần cờ bật/tắt. Tên file `date('dmY_His')` (vd 17072026_231609), đặt tại ROOT của
     * storage/app/gdrive-sync/ (không phải failed/ — chỗ đó dành cho JSON report retry).
     *
     * Chọn writer theo lib có sẵn — kernel package KHÔNG hard-depend OpenSpout/PhpSpreadsheet (chỉ
     * pull-server require), nên PHẢI guard bằng class_exists() để fresh install thiếu lib không bị
     * fatal:
     *   - Có OpenSpout (\OpenSpout\Writer\XLSX\Writer) → ghi .xlsx.
     *   - Không có → ghi .csv qua renderFailedCsv() (`;` + BOM UTF-8, Excel-safe cho locale VN).
     *
     * KHÔNG throw ra ngoài: 1 lỗi ghi spreadsheet không được giết finalReport() — bọc try/catch,
     * chỉ warn + log, giống các method ghi file khác (writeFailedReport/writeUnexportableManifest).
     *
     * @return string|null path đã ghi, hoặc null nếu không có gì để ghi / ghi lỗi.
     */
    protected function writeFailedSpreadsheet(array $failedFiles, string $baseLocalPath, array $targetIdentifiers): ?string
    {
        if (empty($failedFiles)) {
            return null;
        }

        $rows = self::buildFailedRows($failedFiles, [
            'base_local_path' => $baseLocalPath,
            'folder_ids' => array_values($targetIdentifiers),
            'run_id' => $this->runId,
            'generated_at' => date('c'),
        ]);
        if (empty($rows)) {
            return null;
        }

        $dir = storage_path('app/gdrive-sync');
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            $this->warn("⚠️  Could not create failed-spreadsheet dir: {$dir}");
            return null;
        }

        $useXlsx = class_exists(\OpenSpout\Writer\XLSX\Writer::class);
        $path = $dir . DIRECTORY_SEPARATOR . date('dmY_His') . ($useXlsx ? '.xlsx' : '.csv');

        try {
            if ($useXlsx) {
                $writer = new \OpenSpout\Writer\XLSX\Writer();
                $writer->openToFile($path);
                $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues(
                    array_keys($rows[0]),
                    (new \OpenSpout\Common\Entity\Style\Style())->setFontBold()
                ));
                foreach ($rows as $row) {
                    $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues(array_values($row)));
                }
                $writer->close();
            } elseif (@file_put_contents($path, self::renderFailedCsv($rows)) === false) {
                $this->warn("⚠️  Could not write failed spreadsheet: {$path}");
                return null;
            }
        } catch (\Throwable $e) {
            $this->warn("⚠️  Could not write failed spreadsheet ({$path}): {$e->getMessage()}");
            Log::channel($this->log_channel)->warning('GDrive Sync: failed to write failed-files spreadsheet', [
                'run_id' => $this->runId,
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        $this->pruneOldFailedSpreadsheets($dir);

        return $path;
    }

    /**
     * Giữ tối đa MAX_FAILED_REPORTS (tái dùng cùng ngưỡng với pruneOldFailedReports() — cùng lý
     * do "chạy qua cron định kỳ, không prune sẽ tích luỹ vô hạn") bản spreadsheet mới nhất trong
     * storage/app/gdrive-sync/ (root). Match tên file đúng định dạng `date('dmY_His')` để KHÔNG
     * đụng tới các file/thư mục khác cùng cấp (failed/, unexportable/, state/). Lỗi xoá chỉ warn,
     * không làm fail cả command.
     */
    protected function pruneOldFailedSpreadsheets(string $dir): void
    {
        $files = glob($dir . DIRECTORY_SEPARATOR . '*') ?: [];
        $files = array_values(array_filter(
            $files,
            static fn ($f) => is_file($f) && preg_match('/^\d{8}_\d{6}\.(xlsx|csv)$/', basename($f)) === 1
        ));
        if (count($files) <= self::MAX_FAILED_REPORTS) {
            return;
        }

        usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));
        $toDelete = array_slice($files, self::MAX_FAILED_REPORTS);

        foreach ($toDelete as $file) {
            if (! @unlink($file)) {
                $this->warn("⚠️  Could not prune old failed spreadsheet: {$file}");
            }
        }
    }

    /**
     * Ghi manifest Markdown liệt kê file KHÔNG THỂ mirror (permanent) cho một folder — dùng để
     * biết cần tải file nào bằng tay (kèm link) mà không phải đào JSON report thủ công.
     *
     * 🔴 Key theo `$folderTag` (KHÔNG theo timestamp như `failed/`) và LUÔN GHI ĐÈ: mục đích của
     * file này là phản ánh TRẠNG THÁI MỚI NHẤT của folder ("hiện còn thiếu file nào"), không phải
     * lưu lịch sử từng lần chạy như `failed/` (archive theo timestamp, giữ 10 bản gần nhất).
     * Nếu archive theo timestamp thì user phải tự tìm bản mới nhất — đánh mất mục đích "manifest
     * luôn cập nhật, mở lên là biết ngay" mà yêu cầu ban đầu đặt ra.
     *
     * Nếu run này KHÔNG còn permanent-fail nào cho folder → xoá manifest cũ (nếu có), vì lỗ hổng
     * dữ liệu đã được vá (file trước đó lỗi permanent nay đã mirror được, hoặc bị xoá khỏi Drive).
     *
     * @return string|null path manifest vừa ghi (có permanent-fail), hoặc null nếu không có gì
     *                      để ghi (kể cả trường hợp vừa xoá manifest cũ) hoặc ghi/xoá lỗi.
     */
    protected function writeUnexportableManifest(array $failedFiles, string $folderTag, array $targetIdentifiers): ?string
    {
        $manifestDir = storage_path('app/gdrive-sync/unexportable');
        $manifestPath = $manifestDir . DIRECTORY_SEPARATOR . $folderTag . '.md';

        $permanentItems = array_values(array_filter($failedFiles, fn ($f) => ! empty($f['permanent'])));

        if (empty($permanentItems)) {
            if (is_file($manifestPath) && ! @unlink($manifestPath)) {
                $this->warn("⚠️  Could not remove stale unexportable manifest: {$manifestPath}");
            }
            return null;
        }

        if (! is_dir($manifestDir) && ! @mkdir($manifestDir, 0755, true) && ! is_dir($manifestDir)) {
            $this->warn("⚠️  Could not create unexportable manifest dir: {$manifestDir}");
            return null;
        }

        $content = self::buildUnexportableManifest($permanentItems, [
            'generated_at' => date('c'),
            'run_id' => $this->runId,
            'folder_ids' => array_values($targetIdentifiers),
            'base_local_path' => $this->baseLocalPath,
        ]);

        if (@file_put_contents($manifestPath, $content) === false) {
            $this->warn("⚠️  Could not write unexportable manifest: {$manifestPath}");
            return null;
        }

        return $manifestPath;
    }

    /**
     * Build nội dung Markdown của manifest "file không thể mirror". Pure/static (không đụng
     * facade/`$this`) để unit test được mà không cần boot Laravel.
     *
     * Tự lọc lại `permanent === true` NGAY TRONG method (không tin tưởng mù caller đã lọc đúng)
     * — item retryable lọt vào input vẫn KHÔNG được xuất hiện trong manifest, vì manifest này
     * chỉ dành cho lỗi vĩnh viễn (retryable có thể tự khỏi ở lần chạy sau, đưa vào sẽ gây nhiễu).
     *
     * @param array<int, array<string, mixed>> $permanentItems item từ $stats['failed_files']
     *        (mỗi item: path, id, mimeType, size, category, error_reason, permanent, …)
     * @param array{generated_at: string, run_id: string, folder_ids: array, base_local_path: string} $meta
     * @return string Markdown, hoặc chuỗi rỗng nếu không có item permanent nào (caller không ghi file).
     */
    public static function buildUnexportableManifest(array $permanentItems, array $meta): string
    {
        $items = array_values(array_filter($permanentItems, fn ($f) => ! empty($f['permanent'])));
        if (empty($items)) {
            return '';
        }

        // Nhóm theo error_reason (fallback 'category' nếu thiếu reason) — giữ THỨ TỰ nhóm xuất
        // hiện đầu tiên trong input, không sort alphabet, để khớp yêu cầu "đúng thứ tự nhóm".
        $groups = [];
        foreach ($items as $item) {
            $key = $item['error_reason'] ?? ($item['category'] ?? 'other');
            $groups[$key][] = $item;
        }

        // Sort giảm dần theo size TRONG từng nhóm.
        foreach ($groups as $key => $groupItems) {
            usort($groupItems, fn ($a, $b) => (int) ($b['size'] ?? 0) <=> (int) ($a['size'] ?? 0));
            $groups[$key] = $groupItems;
        }

        // Hướng dẫn xử lý riêng cho các reason đã biết chắc (khớp classifyDriveError permanentReasons).
        // Reason khác → chỉ dùng category làm tiêu đề, KHÔNG có ghi chú hướng dẫn riêng.
        $reasonNotes = [
            'exportSizeLimitExceeded' => 'QUÁ LỚN để export qua API (giới hạn 10MB của `files.export`) — **tải tay qua trình duyệt được**.',
            'cannotExportFile' => 'Bị chủ file KHOÁ — không tải được bằng cách nào, kể cả thủ công.',
            'fileNotExportable' => 'Bị chủ file KHOÁ — không tải được bằng cách nào, kể cả thủ công.',
            // Khác cannotExportFile ở CÁCH GỠ: file vẫn tải được BÌNH THƯỜNG nếu chủ bật lại
            // "người xem được tải xuống" trong setting chia sẻ → đáng ghi rõ để người đọc biết
            // đây là việc đi xin quyền, không phải file hỏng vô phương cứu.
            'cannotDownloadFile' => 'Chủ file TẮT quyền tải xuống cho người xem — mirror KHÔNG lấy được. Gỡ bằng cách xin chủ file bật lại "Viewers can download" trong setting chia sẻ.',
        ];

        $lines = [];
        $lines[] = '# File KHÔNG THỂ mirror (permanent)';
        $lines[] = '';
        $lines[] = '- Sinh lúc: ' . ($meta['generated_at'] ?? '-');
        $lines[] = '- Run ID: ' . ($meta['run_id'] ?? '-');
        $lines[] = '- Folder ID(s): ' . (! empty($meta['folder_ids']) ? implode(', ', (array) $meta['folder_ids']) : '-');
        $lines[] = '- Base local path: ' . ($meta['base_local_path'] ?? '-');
        $lines[] = '- Tổng số file: ' . count($items);
        $lines[] = '';

        foreach ($groups as $reasonKey => $groupItems) {
            $categoryLabel = $groupItems[0]['category'] ?? $reasonKey;
            $count = count($groupItems);
            $lines[] = "## {$categoryLabel} ({$count} file" . ($count > 1 ? 's' : '') . ')';
            $lines[] = '';
            if (isset($reasonNotes[$reasonKey])) {
                $lines[] = $reasonNotes[$reasonKey];
                $lines[] = '';
            }

            foreach ($groupItems as $item) {
                $size = self::humanSize((int) ($item['size'] ?? 0));
                $path = $item['path'] ?? '?';
                $lines[] = "- [{$size}] {$path}";

                $id = $item['id'] ?? null;
                if ($id) {
                    [$url, $ext] = self::buildManualDownloadUrl((string) $id, (string) ($item['mimeType'] ?? ''), $path);
                    $lines[] = "  → {$url}  (tải .{$ext})";
                }
            }
            $lines[] = '';
        }

        return rtrim(implode("\n", $lines)) . "\n";
    }

    /**
     * Suy URL tải tay từ id + mimeType — KHÔNG gọi API Google (cả 2 field đã có sẵn trong item
     * từ lần list/lỗi trước đó). Google Native (Docs/Sheets/Slides/Drawings) mở đúng app web,
     * tải nguyên bản mở bằng link file thường.
     *
     * @return array{0: string, 1: string} [url, phần mở rộng gợi ý khi tải]
     */
    protected static function buildManualDownloadUrl(string $id, string $mimeType, string $path = ''): array
    {
        return match ($mimeType) {
            'application/vnd.google-apps.presentation' => ["https://docs.google.com/presentation/d/{$id}", 'pptx'],
            'application/vnd.google-apps.document' => ["https://docs.google.com/document/d/{$id}", 'docx'],
            'application/vnd.google-apps.spreadsheet' => ["https://docs.google.com/spreadsheets/d/{$id}", 'xlsx'],
            'application/vnd.google-apps.drawing' => ["https://docs.google.com/drawings/d/{$id}", 'png'],
            // File thường (không phải Google Native): giữ nguyên bản, không convert qua export
            // API — suy đuôi file từ path gốc thay vì đoán từ mimeType (chính xác hơn, không cần map).
            default => ["https://drive.google.com/file/d/{$id}", pathinfo($path, PATHINFO_EXTENSION) ?: 'file'],
        };
    }

    /**
     * Best-effort đọc `shortcutDetails.targetMimeType` từ metadata shortcut — CHỈ dùng cho
     * log/troubleshoot (log skip shortcut), không ảnh hưởng quyết định skip. `$meta` (item gốc từ
     * masbug listContents) và `$fileMeta` (kết quả deep-check `files->get`, nếu có) đều có thể
     * KHÔNG chứa field này (field list hiện tại không phải lúc nào cũng request 'shortcutDetails')
     * → trả null thay vì lỗi khi thiếu.
     */
    protected function extractShortcutTargetMime($meta, $fileMeta = null): ?string
    {
        if (is_object($fileMeta) && method_exists($fileMeta, 'getShortcutDetails')) {
            $details = $fileMeta->getShortcutDetails();
            if ($details && method_exists($details, 'getTargetMimeType')) {
                return $details->getTargetMimeType();
            }
        }
        if (is_object($meta) && method_exists($meta, 'getShortcutDetails')) {
            $details = $meta->getShortcutDetails();
            if ($details && method_exists($details, 'getTargetMimeType')) {
                return $details->getTargetMimeType();
            }
        }
        if (is_array($meta)) {
            return $meta['shortcutDetails']['targetMimeType'] ?? null;
        }
        return null;
    }

    /**
     * Format bytes for human display.
     * Static (không dùng $this) để buildUnexportableManifest() — method static/pure cho
     * unit test không boot Laravel — gọi lại được qua self::humanSize().
     */
    protected static function humanSize(int $bytes): string
    {
        if ($bytes <= 0) return '-';
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = (int) floor(log($bytes, 1024));
        $i = min($i, count($units) - 1);
        return sprintf('%.1f %s', $bytes / (1024 ** $i), $units[$i]);
    }

    /**
     * Resolve the full path of a Google Drive ID by tracing ancestors
     */
    protected function getPathFromId($googleDisk, $id)
    {
        $service = $googleDisk->getAdapter()->getService();
        $pathSegments = [];
        $currentId = $id;
        $maxDepth = 25; // Guard against unexpected circular references

        while ($currentId && $maxDepth-- > 0) {
            $file = $service->files->get($currentId, ['fields' => 'id, name, parents']);
            if (!$file) break;

            // Stop if we hit the root or a folder without a name (unlikely)
            if ($file->getName() === 'My Drive' || $file->getName() === 'Root') {
                break;
            }

            array_unshift($pathSegments, $file->getName());

            $parents = $file->getParents();
            if (empty($parents)) {
                break;
            }
            $currentId = $parents[0];
        }

        return implode('/', $pathSegments);
    }
}

