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
 *     3) Cơ chế ghi đè (KHÔNG duplicate, KHÔNG backup):
 *        • Google Native: File::put() → ghi đè toàn bộ.
 *        • Regular file:  fopen('w')  → truncate về 0 byte rồi stream nội dung mới.
 *        • Permission/owner của file local KHÔNG bị đổi (chỉ truncate nội dung).
 *        • KHÔNG tạo file .bak / .old / .tmp — đè trực tiếp tại path cũ.
 *
 *     4) An toàn khi crash giữa chừng:
 *        Ghi KHÔNG atomic. Crash giữa stream → file local còn lại partial. Lần sync
 *        kế tiếp: size/MD5 mismatch sẽ được phát hiện → tải lại từ đầu. Self-heal.
 *
 *     5) Các tình huống đặc biệt:
 *        • Local file người dùng đã sửa, Drive không đổi  → SKIP (giữ bản sửa).
 *        • Local file người dùng đã sửa, Drive cũng đổi   → bản sửa local BỊ ĐÈ.
 *        • File Drive bị đổi tên → tạo file mới ở path mới; bản cũ ở path cũ KHÔNG bị xoá.
 *        • Local có file mà Drive không có  → giữ nguyên (command chỉ ADD/UPDATE,
 *          không DELETE — xem cleanup "removed for safety" trong handle()).
 *        • Đổi --path giữa các lần           → path mới sync từ đầu, path cũ nguyên vẹn.
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
 *  trong manifest); file bị chủ khoá (cannotExportFile) thì không cách nào tải được, kể cả tay.
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
        {--include-permanent : With --retry-failed, also re-attempt items marked permanent (403 vĩnh viễn — retry vô ích by default, skip)}';

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
     * Số report JSON tối đa giữ lại trong storage/app/gdrive-sync/failed/ — tự prune report
     * cũ hơn sau mỗi lần ghi để tránh tích luỹ vô hạn (command chạy qua cron định kỳ).
     */
    protected const MAX_FAILED_REPORTS = 10;

    /**
     * Google Native MimeTypes to Microsoft Office (OpenXML) Formats
     */
    protected $exportMap = [
        'application/vnd.google-apps.document'   => ['ext' => 'docx', 'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'application/vnd.google-apps.spreadsheet' => ['ext' => 'xlsx', 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'application/vnd.google-apps.presentation' => ['ext' => 'pptx', 'mime' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation'],
        'application/vnd.google-apps.drawing'      => ['ext' => 'png',  'mime' => 'image/png'],
        'application/vnd.google-apps.script'       => ['ext' => 'json', 'mime' => 'application/vnd.google-apps.script+json'],
    ];

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

            $stats = ['processed' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0, 'folders' => 0, 'failed_files' => [], 'collisions' => 0];

            foreach ($targetIdentifiers as $identifier) {
                $usedLocalPaths = []; // Reset per-folder to avoid cross-folder collision false-positives
                $localPrefix = '';
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
                        $localPrefix = $folderInfo->getName();
                        $this->info("\n🚀 SYNCING (Service Account) — Folder: {$localPrefix} ({$identifier})");
                    } catch (\Throwable $e) {
                        $localPrefix = $identifier;
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
                    $absoluteLocalPath = "{$baseLocalPath}/{$syncPath}";
                    $type = is_array($item) ? ($item['type'] ?? 'file') : (method_exists($item, 'type') ? $item->type() : 'file');

                    if ($type === 'dir') {
                        if (!File::isDirectory($absoluteLocalPath)) {
                            File::makeDirectory($absoluteLocalPath, 0755, true);
                            $stats['folders']++;
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
                        $exportSpec = $this->exportMap[$mimeType] ?? null;

                        // Deep Check for Google Native Files if MimeType is missing or generic
                        $fileMeta = null;
                        if (!$exportSpec && $isId) {
                            try {
                                /** @var mixed $googleDisk */
                                $service = $googleDisk->getAdapter()->getService();
                                $fileMeta = $service->files->get($fileId, ['fields' => 'id, name, mimeType, md5Checksum']);
                                $mimeType = $fileMeta->getMimeType();
                                $remoteMd5 = $fileMeta->getMd5Checksum();
                                $exportSpec = $this->exportMap[$mimeType] ?? null;
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

                        if ($exportSpec) {
                            $targetLocalPath = $absoluteLocalPath . '.' . $exportSpec['ext'];
                        }

                        // IMPROVEMENT 1 & 3: Handle Name Collisions
                        if (isset($usedLocalPaths[$targetLocalPath])) {
                            $stats['collisions']++;
                            $this->warn("\n   ⚠️ Collision: " . basename($targetLocalPath) . " already exists in this sync. Appending ID.");

                            $info = pathinfo($targetLocalPath);
                            $ext = $info['extension'] ?? '';
                            $newName = $info['filename'] . '_' . substr($fileId, 0, 8);
                            $targetLocalPath = $info['dirname'] . DIRECTORY_SEPARATOR . $newName . ($ext ? "." . $ext : "");

                            Log::channel($this->log_channel)->warning("GDrive Sync Collision", [
                                'original' => $absoluteLocalPath,
                                'new' => $targetLocalPath,
                                'id' => $fileId
                            ]);
                        }
                        $usedLocalPaths[$targetLocalPath] = $fileId;

                        // Delta Sync: size mismatch is a cheap pre-check (detects truncation/corruption
                        // without reading whole file). MD5 is authoritative when available. Timestamp
                        // is fallback for Google Native files (which have no md5Checksum).
                        if (!$this->option('force') && File::exists($targetLocalPath)) {
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
                                $response = $service->files->export($fileId, $exportSpec['mime'], ['alt' => 'media']);
                                File::put($targetLocalPath, $response->getBody()->getContents());
                            } elseif ($useApi) {
                                // Service account: download via Drive API (masbug readStream
                                // doesn't work when adapter root is a folder ID).
                                /** @var mixed $googleDisk */
                                $service = $googleDisk->getAdapter()->getService();
                                $this->streamDownloadViaApi($service, $fileId, $targetLocalPath);
                            } else {
                                // Stream Download for regular files (Memory Efficient)
                                $readStream = $googleDisk->readStream($relativePath);
                                if (!$readStream) {
                                    throw new \Exception("Could not open read stream");
                                }
                                $writeStream = fopen($targetLocalPath, 'w');
                                try {
                                    stream_copy_to_stream($readStream, $writeStream);
                                } finally {
                                    fclose($writeStream);
                                    if (is_resource($readStream)) fclose($readStream);
                                }
                            }
                            return true;
                        }, $relativePath);

                        if ($success) {
                            @touch($targetLocalPath, $remoteTimestamp);
                            $stats['updated']++;
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
            Log::channel($this->log_channel)->error("GDrive Mirror Fatal Exception", ['msg' => $th->getMessage(), 'trace' => $th->getTraceAsString()]);
            return Command::FAILURE;
        }

        return Command::SUCCESS;
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
     * Recursively list a folder via Drive API. Returns items in masbug-compatible shape
     * (arrays with type/path/id/mimeType/md5Checksum/timestamp). Used in service-account mode
     * because masbug's listContents on a folder-id root returns nothing.
     */
    protected function listFolderRecursiveViaApi($service, string $folderId, string $relativePath = ''): array
    {
        $items = [];
        $pageToken = null;
        $query = sprintf("'%s' in parents and trashed = false", str_replace("'", "\\'", $folderId));

        do {
            $response = $service->files->listFiles([
                'q' => $query,
                'pageSize' => 1000,
                'fields' => 'nextPageToken, files(id, name, mimeType, modifiedTime, md5Checksum, size)',
                'pageToken' => $pageToken,
                'supportsAllDrives' => true,
                'includeItemsFromAllDrives' => true,
            ]);

            foreach ($response->getFiles() as $file) {
                $isFolder = $file->getMimeType() === 'application/vnd.google-apps.folder';
                $childPath = $relativePath !== '' ? $relativePath . '/' . $file->getName() : $file->getName();

                $items[] = [
                    'type' => $isFolder ? 'dir' : 'file',
                    'path' => $childPath,
                    'id' => $file->getId(),
                    'mimeType' => $file->getMimeType(),
                    'md5Checksum' => $file->getMd5Checksum(),
                    'timestamp' => $file->getModifiedTime() ? strtotime($file->getModifiedTime()) : 0,
                    'size' => (int) ($file->getSize() ?? 0),
                ];

                if ($isFolder) {
                    foreach ($this->listFolderRecursiveViaApi($service, $file->getId(), $childPath) as $child) {
                        $items[] = $child;
                    }
                }
            }

            $pageToken = $response->getNextPageToken();
        } while ($pageToken);

        return $items;
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
        $body = $response->getBody();

        $writeStream = fopen($targetLocalPath, 'w');
        try {
            while (! $body->eof()) {
                fwrite($writeStream, $body->read(8192));
            }
        } finally {
            fclose($writeStream);
        }
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

        // Hướng dẫn xử lý riêng cho 2 reason đã biết chắc (khớp classifyDriveError permanentReasons).
        // Reason khác → chỉ dùng category làm tiêu đề, KHÔNG có ghi chú hướng dẫn riêng.
        $reasonNotes = [
            'exportSizeLimitExceeded' => 'QUÁ LỚN để export qua API (giới hạn 10MB của `files.export`) — **tải tay qua trình duyệt được**.',
            'cannotExportFile' => 'Bị chủ file KHOÁ — không tải được bằng cách nào, kể cả thủ công.',
            'fileNotExportable' => 'Bị chủ file KHOÁ — không tải được bằng cách nào, kể cả thủ công.',
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

