<?php

namespace Tests\Unit;

use Dev\Kernel\Commands\GDriveMirrorSync;
use PHPUnit\Framework\TestCase;

/**
 * Bảo vệ GDriveMirrorSync::isInvalidLocalName() (audit C1-C5/L1-L3, 2026-07-19) — nhận diện lỗi
 * OS về chính cái TÊN (quá dài/EINVAL) hoặc lỗi safeJoinLocalPath() từ chối (traversal/escape),
 * và đảm bảo nó được classifyDriveError()/isLocalFsError() gọi SỚM NHẤT — LUÔN permanent, và
 * LUÔN KHÔNG được tính là bằng chứng ổ đĩa cục bộ hỏng (mirror classify.IsInvalidLocalName() bản
 * Go). Port pattern test từ GDriveLocalFsErrorTest.php (cùng nguyên tắc bất đối xứng: đoán nhầm
 * "ổ hỏng" ABORT OAN cả run, nặng hơn nhiều so với đoán nhầm "lỗi tên" thành lỗi Drive thường).
 */
class GDriveInvalidLocalNameTest extends TestCase
{
    public static function invalidNameProvider(): array
    {
        return [
            'ENAMETOOLONG kiểu macOS/Linux' => [
                'file_put_contents(/Volumes/WD-DATA1/very/long/path.txt): Failed to open stream: File name too long',
                true,
            ],
            'chuỗi "name too long" (biến thể khác)' => [
                'rename(): name too long',
                true,
            ],
            'ENAMETOOLONG viết hoa toàn bộ (case-insensitive)' => [
                'ENAMETOOLONG: File name too long',
                true,
            ],
            'EINVAL kèm đường dẫn tuyệt đối' => [
                'fopen(/Volumes/WD-DATA1/OneDrive/bad?name.txt): Failed to open stream: Invalid argument',
                true,
            ],
            'EINVAL TRẦN không kèm đường dẫn → KHÔNG nhận' => [
                'Invalid argument',
                false,
            ],
            'lỗi Drive API 403 thường (KHÔNG phải lỗi tên)' => [
                '{"error":{"code":403,"message":"forbidden","errors":[{"reason":"forbidden"}]}}',
                false,
            ],
            'chuỗi rỗng' => [
                '',
                false,
            ],
        ];
    }

    /**
     * @dataProvider invalidNameProvider
     */
    public function test_is_invalid_local_name(string $msg, bool $expected): void
    {
        $this->assertSame($expected, GDriveMirrorSync::isInvalidLocalName($msg));
    }

    public function test_safe_join_local_path_rejection_is_recognized(): void
    {
        $root = sys_get_temp_dir() . '/gdrive_invalid_name_test_' . uniqid();
        try {
            GDriveMirrorSync::safeJoinLocalPath($root, '../escape.txt');
            $this->fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertTrue(GDriveMirrorSync::isInvalidLocalName($e->getMessage()));
        }
    }

    /**
     * 🔴 classifyDriveError() phải kiểm isInvalidLocalName() TRƯỚC MỌI THỨ KHÁC — nếu không, 1
     * tên quá dài (KHÔNG có JSON body hợp lệ, message dạng chuỗi filesystem trần) có thể rơi vào
     * nhánh "Other, retryable=true" mặc định thay vì bị đánh permanent đúng.
     */
    public function test_classify_drive_error_returns_permanent_for_invalid_name(): void
    {
        $result = GDriveMirrorSync::classifyDriveError('File name too long');

        $this->assertFalse($result['retryable']);
        $this->assertSame('invalidLocalName', $result['reason']);
        $this->assertSame('Invalid or too-long local name', $result['category']);
    }

    public function test_classify_drive_error_recognizes_safe_join_rejection(): void
    {
        $root = sys_get_temp_dir() . '/gdrive_invalid_name_test_' . uniqid();
        try {
            GDriveMirrorSync::safeJoinLocalPath($root, '../../../../etc/passwd');
            $this->fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $result = GDriveMirrorSync::classifyDriveError($e->getMessage());
            $this->assertFalse($result['retryable']);
            $this->assertSame('invalidLocalName', $result['reason']);
        }
    }

    /**
     * 🔴 isLocalFsError() PHẢI trả false cho lỗi tên — nếu không, 1 item tên bẩn/quá dài có thể
     * bị đếm vào circuit breaker ổ đĩa (MAX_CONSECUTIVE_LOCAL_FS_ERRORS) và ABORT OAN cả run dù ổ
     * đĩa hoàn toàn khoẻ mạnh.
     */
    public function test_is_local_fs_error_returns_false_for_invalid_name_even_with_fs_function_marker(): void
    {
        // Cố tình dùng message VỪA khớp marker filesystem cục bộ (fopen() ...) VỪA khớp lỗi tên
        // quá dài — guard belt-and-suspenders trong isLocalFsError() phải thắng, trả false.
        $msg = 'fopen(/Volumes/WD-DATA1/some/path.txt): Failed to open stream: File name too long';

        $this->assertTrue(GDriveMirrorSync::isInvalidLocalName($msg));
        $this->assertFalse(GDriveMirrorSync::isLocalFsError($msg));
    }
}
