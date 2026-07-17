<?php

namespace Tests\Unit;

use Dev\Kernel\Commands\GDriveMirrorSync;
use PHPUnit\Framework\TestCase;

/**
 * Bảo vệ GDriveMirrorSync::classifyDriveError() — phân loại lỗi Drive API theo `reason`
 * field trong body JSON, KHÔNG string-match message chung chung.
 *
 * Bug thực tế (2026-07-16): 24/69 lỗi thật là `cannotExportFile` (403, file bị Google lock)
 * nhưng logic cũ dán nhãn "Permission denied" (vì message chứa "403") rồi RETRY 3 lần vô ích
 * (backoff 2+4+8=14s/file) — lỗi này KHÔNG BAO GIỜ tự khỏi. Test dưới là hồi quy cho bug đó.
 *
 * 2 body JSON đầu tiên (exportSizeLimitExceeded, cannotExportFile) copy NGUYÊN VĂN từ report
 * lỗi thật (storage/app/gdrive-sync/failed/*.json trên pull-server, phân tích 69 entry —
 * 100% rơi vào 2 reason này). Các reason còn lại (notFound, insufficientFilePermissions,
 * userRateLimitExceeded, backendError) chưa xuất hiện trong dữ liệu thật nhưng nằm trong spec
 * — dựng theo ĐÚNG format lỗi Google Drive API documented (khớp cấu trúc error.errors[0].reason
 * của 2 lỗi thật ở trên).
 */
class GDriveErrorClassificationTest extends TestCase
{
    public static function driveErrorProvider(): array
    {
        return [
            'exportSizeLimitExceeded (real report body)' => [
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
                'Export size limit',
                false,
            ],
            // Hồi quy: trước đây bị dán nhãn nhầm "Permission denied" vì message chứa "403".
            'cannotExportFile (real report body — regression for mislabel bug)' => [
                <<<'JSON'
                {
                  "error": {
                    "code": 403,
                    "message": "This file cannot be exported by the user.",
                    "errors": [
                      {
                        "message": "This file cannot be exported by the user.",
                        "domain": "global",
                        "reason": "cannotExportFile"
                      }
                    ]
                  }
                }
                JSON,
                'Not exportable (locked/unsupported)',
                false,
            ],
            // Hồi quy cho bug PHÁT HIỆN LÚC CHẠY THẬT (2026-07-17, run 9cd3158b 14:44-14:45):
            // 8 file dính reason này. Reason KHÔNG có trong allowlist permanent → rơi xuống
            // nhánh 403-fallback → retryable=true → mỗi file đốt 14s backoff rồi vẫn fail, và
            // vì không permanent nên KHÔNG lọt vào manifest unexportable → âm thầm thiếu khỏi
            // mirror. Body copy NGUYÊN VĂN từ log thật.
            'cannotDownloadFile (real log body — owner disabled download)' => [
                <<<'JSON'
                {
                  "error": {
                    "code": 403,
                    "message": "This file cannot be downloaded by the user.",
                    "errors": [
                      {
                        "message": "This file cannot be downloaded by the user.",
                        "domain": "global",
                        "reason": "cannotDownloadFile"
                      }
                    ]
                  }
                }
                JSON,
                'Not downloadable (chủ tắt quyền tải)',
                false,
            ],
            'notFound (404)' => [
                <<<'JSON'
                {
                  "error": {
                    "code": 404,
                    "message": "File not found: 1AbCdEfGhIjKlMnOpQrStUvWxYz.",
                    "errors": [
                      {
                        "message": "File not found: 1AbCdEfGhIjKlMnOpQrStUvWxYz.",
                        "domain": "global",
                        "reason": "notFound"
                      }
                    ]
                  }
                }
                JSON,
                'Not found',
                false,
            ],
            'insufficientFilePermissions (403)' => [
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
                'Permission denied',
                false,
            ],
            'userRateLimitExceeded (429)' => [
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
                'Quota / rate limit',
                true,
            ],
            'backendError (500)' => [
                <<<'JSON'
                {
                  "error": {
                    "code": 500,
                    "message": "Backend Error",
                    "errors": [
                      {
                        "message": "Backend Error",
                        "domain": "global",
                        "reason": "backendError"
                      }
                    ]
                  }
                }
                JSON,
                'Server error (5xx)',
                true,
            ],
            // 🔴 HỒI QUY: Drive trả rate-limit dưới CẢ 429 LẪN 403. Nếu suy "403 ⇒ permanent"
            // thì các lỗi TẠM THỜI dưới đây bị skip hẳn → file âm thầm mất khỏi mirror.
            'dailyLimitExceeded (403 nhưng TẠM THỜI — reset theo ngày)' => [
                <<<'JSON'
                {
                  "error": {
                    "code": 403,
                    "message": "Daily Limit Exceeded",
                    "errors": [
                      {
                        "message": "Daily Limit Exceeded",
                        "domain": "usageLimits",
                        "reason": "dailyLimitExceeded"
                      }
                    ]
                  }
                }
                JSON,
                'Quota / rate limit',
                true,
            ],
            'sharingRateLimitExceeded (403 nhưng TẠM THỜI)' => [
                <<<'JSON'
                {
                  "error": {
                    "code": 403,
                    "message": "Rate limit exceeded. User message: Sharing rate limit exceeded.",
                    "errors": [
                      {
                        "message": "Rate limit exceeded.",
                        "domain": "global",
                        "reason": "sharingRateLimitExceeded"
                      }
                    ]
                  }
                }
                JSON,
                'Quota / rate limit',
                true,
            ],
            // 403 với reason LẠ (Google thêm reason mới trong tương lai) → KHÔNG được đoán
            // permanent: đoán nhầm permanent = mất file vĩnh viễn, đoán nhầm retryable = phí 14s.
            'unknown reason dưới 403 → nghiêng về retryable' => [
                <<<'JSON'
                {
                  "error": {
                    "code": 403,
                    "message": "Some brand new Google restriction",
                    "errors": [
                      {
                        "message": "Some brand new Google restriction",
                        "domain": "global",
                        "reason": "someFutureReasonGoogleAdds"
                      }
                    ]
                  }
                }
                JSON,
                'Permission denied',
                true,
            ],
            'non-JSON network error (cURL raw string)' => [
                'cURL error 28: Operation timed out after 30000 milliseconds',
                'Network / timeout',
                true,
            ],
            // Chuỗi 403 trần không parse được reason → không chứng minh được permanent.
            'non-JSON chuỗi 403 trần → retryable' => [
                'Google_Service_Exception: HTTP 403 forbidden',
                'Permission denied',
                true,
            ],
            'garbage non-JSON string' => [
                'some totally unexpected garbage error blob %%%',
                'Other',
                true,
            ],
            'empty string' => [
                '',
                'Other',
                true,
            ],
            // 🔴 HỒI QUY (2026-07-16): body JSON THẬT copy nguyên văn từ file rác 420 byte bị
            // streamDownloadViaApi() ghi thẳng lên đĩa — Google trả 403 fileNotDownloadable cho
            // shortcut/Docs-Editors file, nhưng Guzzle RAW (http_errors=false) không throw nên
            // JSON lỗi này bị coi là nội dung file rồi báo "Errors encountered: 0". Trước khi có
            // fix, reason 'fileNotDownloadable' không nằm trong allowlist permanent → nếu có
            // throw đúng thì vẫn bị coi retryable, tốn backoff vô ích cho lỗi KHÔNG BAO GIỜ tự khỏi.
            'fileNotDownloadable (real body — shortcut/Docs Editors, 403)' => [
                <<<'JSON'
                {"error":{"code":403,"message":"Only files with binary content can be downloaded. Use Export with Docs Editors files.","errors":[{"message":"Only files with binary content can be downloaded. Use Export with Docs Editors files.","domain":"global","reason":"fileNotDownloadable"}]}}
                JSON,
                'Not downloadable (Docs Editors/shortcut)',
                false,
            ],
        ];
    }

    /**
     * @dataProvider driveErrorProvider
     */
    public function test_classify_drive_error(string $rawMessage, string $expectedCategory, bool $expectedRetryable): void
    {
        $result = GDriveMirrorSync::classifyDriveError($rawMessage);

        $this->assertSame($expectedCategory, $result['category']);
        $this->assertSame($expectedRetryable, $result['retryable']);
    }

    public function test_cannot_export_file_is_not_misclassified_as_permission_denied(): void
    {
        $msg = '{"error":{"code":403,"message":"This file cannot be exported by the user.","errors":[{"message":"This file cannot be exported by the user.","domain":"global","reason":"cannotExportFile"}]}}';

        $result = GDriveMirrorSync::classifyDriveError($msg);

        $this->assertNotSame('Permission denied', $result['category']);
        $this->assertSame('Not exportable (locked/unsupported)', $result['category']);
        $this->assertFalse($result['retryable']);
        $this->assertSame('cannotExportFile', $result['reason']);
    }

    /**
     * Bảo vệ GDriveMirrorSync::assertDownloadOk() — helper pure/static tách ra từ
     * streamDownloadViaApi() để kiểm status code tay (Guzzle RAW dùng bởi Google client có
     * 'http_errors' => false nên KHÔNG tự throw ở 4xx/5xx, xem comment tại streamDownloadViaApi()).
     */
    public function test_assert_download_ok_does_not_throw_on_200(): void
    {
        GDriveMirrorSync::assertDownloadOk(200, '');
        $this->addToAssertionCount(1); // không throw = pass
    }

    public function test_assert_download_ok_throws_with_reason_on_403(): void
    {
        $body = '{"error":{"code":403,"message":"Only files with binary content can be downloaded. Use Export with Docs Editors files.","errors":[{"message":"Only files with binary content can be downloaded. Use Export with Docs Editors files.","domain":"global","reason":"fileNotDownloadable"}]}}';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($body);

        try {
            GDriveMirrorSync::assertDownloadOk(403, $body);
        } catch (\RuntimeException $e) {
            // Message ném ra phải parse được qua classifyDriveError() y hệt luồng withRetry().
            $classified = GDriveMirrorSync::classifyDriveError($e->getMessage());
            $this->assertSame('fileNotDownloadable', $classified['reason']);
            $this->assertFalse($classified['retryable']);
            throw $e;
        }
    }
}
