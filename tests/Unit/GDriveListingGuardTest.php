<?php

namespace Tests\Unit;

use Dev\Kernel\Commands\GDriveMirrorSync;
use PHPUnit\Framework\TestCase;

/**
 * Bảo vệ GDriveMirrorSync::shouldAbortOnShrink() — lưới an toàn cuối cùng chống mirror đè lên
 * một cây dữ liệu bị liệt kê thiếu (BUG A-3).
 *
 * Bug thực tế đã đo được (2026-07-16): cùng 1 folder gốc, 1 lần chạy "Found 2719 items", lần
 * kế tiếp "Found 5760 items" (dry-run, lặp lại 3 lần đều ra 5760) — chênh lệch 3041 item đúng
 * bằng kích thước cây con của 1 subfolder mà Drive `files.list` trả rỗng sai. Command KHÔNG hề
 * báo lỗi, vẫn in "✨ COMPLETED" với Errors: 0. Case prev=5760/cur=2719 dưới đây tái hiện đúng
 * tỉ lệ sụt của bug thật (giảm ~52.8%, vượt xa ngưỡng chặn 20%).
 */
class GDriveListingGuardTest extends TestCase
{
    public static function shrinkProvider(): array
    {
        return [
            'lần đầu chưa có state (prev=null) → không abort' => [null, 5760, false],
            'case THẬT: 5760 → 2719 (giảm ~52.8%) → ABORT' => [5760, 2719, true],
            'không đổi (5760 → 5760) → không abort' => [5760, 5760, false],
            'giảm nhẹ 13% (5760 → 5000) → chưa vượt ngưỡng 20% → không abort' => [5760, 5000, false],
            'đúng biên 80% (5760 → 4608) → KHÔNG abort (chỉ abort khi < ratio, không phải ≤)' => [5760, 4608, false],
            'dưới biên 1 đơn vị (5760 → 4607) → ABORT' => [5760, 4607, true],
            'prev=0 (tránh chia cho 0, không có gì để "sụt" thêm) → không abort' => [0, 0, false],
        ];
    }

    /**
     * @dataProvider shrinkProvider
     */
    public function test_shouldAbortOnShrink(?int $prevCount, int $currentCount, bool $expectedAbort): void
    {
        $this->assertSame($expectedAbort, GDriveMirrorSync::shouldAbortOnShrink($prevCount, $currentCount));
    }
}
