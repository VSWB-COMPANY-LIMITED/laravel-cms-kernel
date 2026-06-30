<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Bảo vệ logic chống báo "synchronized" GIẢ của apps_google_sheet:
 * append trả 200 nhưng updatedRows=0/thiếu (vd tab đích bị Filter/ẩn) => phải coi là THẤT BẠI.
 */
class AppsGsheetAppendSucceededTest extends TestCase
{
    public static function appendResultProvider(): array
    {
        return [
            'one row written'          => [['updates' => ['updatedRows' => 1]], true],
            'three rows written'       => [['updates' => ['updatedRows' => 3]], true],
            'zero rows written'        => [['updates' => ['updatedRows' => 0]], false],
            'missing updates key'      => [[], false],
            // Bug thực tế: Google trả updatedRange nhưng không có updatedRows => KHÔNG được coi là thành công.
            'range present but no rows'=> [['updates' => ['updatedRange' => "'DATA Raw'!A3107:Y3107"]], false],
        ];
    }

    /**
     * @dataProvider appendResultProvider
     */
    public function test_append_success_detection(array $appendSimple, bool $expected): void
    {
        $this->assertSame($expected, apps_gsheet_append_succeeded($appendSimple));
    }
}
