<?php

namespace Tests\Unit;

use Dev\Kernel\Commands\GDriveMirrorSync;
use PHPUnit\Framework\TestCase;

/**
 * Bảo vệ GDriveMirrorSync::isLocalFsError() và shouldAbortOnLocalFsErrors() (BUG D) — phân biệt
 * lỗi filesystem CỤC BỘ (ổ đích hỏng/unmount/read-only/đầy) khỏi lỗi Drive API, để withRetry()
 * KHÔNG phí backoff retry cho lỗi không bao giờ tự khỏi trong vài giây kế tiếp.
 *
 * Bug thực tế đã đo được (storage/logs/pull.vn-2026-07-17.log trên pull-server):
 *   - Run da1047b4 01:19:20: md5_file() đọc file trên ổ exFAT ngoài (/Volumes/WD-DATA1) rớt kết
 *     nối giữa chừng → E_WARNING "md5_file(): Read of 8192 bytes failed with errno=5 Input/output
 *     error" → Laravel biến WARNING thành ErrorException → 💥 Fatal Error giết CẢ run (5760 item).
 *   - Run f0547f35 01:19-01:21: hàng chục file liên tiếp lặp lại y hệt lỗi
 *     "mkdir(): Permission denied" (mkdir() gọi từ File::ensureDirectoryExists() bên trong
 *     callback của withRetry() khi download file) — mỗi file tốn 3 lần retry × backoff
 *     (2+4+8=14s) trong khi ổ đã hỏng, đốt ~2 phút chỉ để fail đều đặn.
 *
 * 2 message thật đầu tiên trong provider copy NGUYÊN VĂN từ log trên. Body JSON
 * exportSizeLimitExceeded copy nguyên văn từ report lỗi thật
 * storage/app/gdrive-sync/failed/failed-1cb5618d-20260717-133656.json (field 'reason' của item[0],
 * unescape lại thành JSON thật). Các case còn lại (insufficientFilePermissions, 429 rate-limit,
 * read-only/no-space) chưa xuất hiện trong dữ liệu thật nhưng dựng theo ĐÚNG format lỗi Google
 * Drive API documented / format warning PHP thật — cùng cách tiếp cận đã dùng ở
 * GDriveErrorClassificationTest.php.
 */
class GDriveLocalFsErrorTest extends TestCase
{
    public static function localFsErrorProvider(): array
    {
        return [
            // ── NHẬN ĐÚNG (local filesystem, isLocalFsError() phải trả true) ──────────────
            'md5_file() errno=5 I/O THẬT (log thật run da1047b4 01:19:20)' => [
                'md5_file(): Read of 8192 bytes failed with errno=5 Input/output error',
                true,
            ],
            'mkdir() Permission denied THẬT (log thật run f0547f35 01:19-01:21)' => [
                'mkdir(): Permission denied',
                true,
            ],
            'fopen() No such file or directory THẬT (log thật run f0547f35 01:19:20)' => [
                'fopen(/Volumes/WD-DATA1/visualweber.com-gmail.com/Accounting/HopDong_KhachHang/VisualWeber/2026/PULL26_WEB25.0410_Dong bo lead HD Khung/PULL26_WEB25.0410_DGM_DNTT_T03.2026_Dong Bo Lead.docx): Failed to open stream: No such file or directory',
                true,
            ],
            'read-only file system (fopen, format warning PHP thật, ổ bị remount read-only)' => [
                'fopen(/Volumes/WD-DATA1/OneDrive/report.pdf): failed to open stream: Read-only file system',
                true,
            ],
            'no space left on device (fwrite, format warning PHP thật, ổ đầy dung lượng)' => [
                'fwrite(): Write of 8192 bytes failed with errno=28 No space left on device',
                true,
            ],
            'filesize() thất bại khi delta-check đọc size local' => [
                'filesize(): stat failed for /Volumes/WD-DATA1/OneDrive/report.pdf',
                true,
            ],
            'permission denied TRẦN nhưng KÈM đường dẫn tuyệt đối → vẫn nhận local-FS' => [
                '/Volumes/WD-DATA1/OneDrive/report.pdf: Permission denied',
                true,
            ],

            // ── KHÔNG NHẬN NHẦM (lỗi Drive API, isLocalFsError() phải trả false) ──────────
            'exportSizeLimitExceeded (403, body JSON THẬT copy từ report lỗi thật pull-server)' => [
                <<<'JSON'
                {
                  "error": {
                    "code": 403,
                    "message": "This file is too large to be exported.",
                    "errors": [
                      {
                        "message": "This file is too large to be exported.",
                        "domain": "global",
                        "reason": "exportSizeLimitExceeded"
                      }
                    ]
                  }
                }
                JSON,
                false,
            ],
            'insufficientFilePermissions (403, đúng format Google Drive API documented)' => [
                <<<'JSON'
                {
                  "error": {
                    "code": 403,
                    "message": "The user does not have sufficient permissions for this file.",
                    "errors": [
                      {
                        "message": "The user does not have sufficient permissions for this file.",
                        "domain": "global",
                        "reason": "insufficientFilePermissions"
                      }
                    ]
                  }
                }
                JSON,
                false,
            ],
            'userRateLimitExceeded (429, chuỗi rate limit — tạm thời, không phải lỗi ổ đĩa)' => [
                <<<'JSON'
                {
                  "error": {
                    "code": 429,
                    "message": "User Rate Limit Exceeded",
                    "errors": [
                      {
                        "message": "User Rate Limit Exceeded",
                        "domain": "usageLimits",
                        "reason": "userRateLimitExceeded"
                      }
                    ]
                  }
                }
                JSON,
                false,
            ],
            // Hồi quy trực tiếp cho nguyên tắc bất đối xứng: "permission denied" TRẦN không kèm
            // marker FS nào — KHÔNG được đoán nhầm thành local-FS (đoán nhầm chiều này ABORT OAN
            // cả run, nặng hơn nhiều so với đoán nhầm chiều ngược lại).
            'permission denied TRẦN không kèm marker FS nào → KHÔNG nhận local-FS' => [
                'Google_Service_Exception: HTTP 403 Permission denied',
                false,
            ],
            'chuỗi rỗng' => [
                '',
                false,
            ],
        ];
    }

    /**
     * @dataProvider localFsErrorProvider
     */
    public function test_is_local_fs_error(string $msg, bool $expected): void
    {
        $this->assertSame($expected, GDriveMirrorSync::isLocalFsError($msg));
    }

    public static function localFsCircuitBreakerProvider(): array
    {
        return [
            '0 lỗi liên tiếp → không abort' => [0, false],
            '19 lỗi liên tiếp (dưới ngưỡng 20) → không abort' => [19, false],
            'đúng ngưỡng 20 → ABORT' => [20, true],
            'vượt ngưỡng 25 → ABORT' => [25, true],
        ];
    }

    /**
     * @dataProvider localFsCircuitBreakerProvider
     */
    public function test_should_abort_on_local_fs_errors(int $consecutive, bool $expectedAbort): void
    {
        $this->assertSame($expectedAbort, GDriveMirrorSync::shouldAbortOnLocalFsErrors($consecutive));
    }
}
