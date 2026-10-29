<?php

namespace Tests\Unit;

use Dev\Kernel\Commands\GDriveMirrorSync;
use PHPUnit\Framework\TestCase;

class GDriveDriveIdDetectionTest extends TestCase
{
    public static function identifierProvider(): array
    {
        return [
            'folder ID thật (33 ký tự)' => ['1AbCdEfGhIjKlMnOpQrStUvWxYz012345', true],
            'ID có - và _' => ['0B7_xyzABC-def_ghiJKLmnopQR', true],
            'tên thư mục 1 cấp có dấu cách (lỗi cũ)' => ['Marketing Materials 2026', false],
            'tên có dấu tiếng Việt' => ['Tài liệu dự án khách hàng 2026', false],
            'đường dẫn' => ['Clients/Thaco/2026', false],
            'chuỗi ngắn (≤ 20) như cũ không phải ID' => ['abcdefghijklmnopqrst', false],
            '21 ký tự hợp lệ' => ['abcdefghijklmnopqrstu', true],
            'link chia sẻ dán nguyên' => ['1AbCdEfGhIjKlMnOpQrStUvWxYz?usp=sharing', false],
            'rỗng' => ['', false],
        ];
    }

    /** @dataProvider identifierProvider */
    public function test_looks_like_drive_id(string $identifier, bool $expected): void
    {
        $this->assertSame($expected, GDriveMirrorSync::looksLikeDriveId($identifier));
    }
}
