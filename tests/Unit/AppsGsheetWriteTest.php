<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Bảo vệ logic ghi Google Sheet MIỄN NHIỄM filter/ẩn dòng:
 * - order_row: xếp dữ liệu theo đúng thứ tự header (update không tự map như append).
 * - next_row_index: tính dòng trống kế tiếp từ cột khóa đã đọc.
 * - update_succeeded / append_succeeded: chống báo thành công GIẢ.
 */
class AppsGsheetWriteTest extends TestCase
{
    public function test_order_row_maps_assoc_to_header_order(): void
    {
        $this->assertSame(
            ['30/06', 'An', 'X'],
            apps_gsheet_order_row(['Form' => 'X', 'Date' => '30/06', 'Name' => 'An'], ['Date', 'Name', 'Form'])
        );
    }

    public function test_order_row_missing_and_null_become_empty(): void
    {
        $this->assertSame(
            ['30/06', '', ''],
            apps_gsheet_order_row(['Date' => '30/06', 'Name' => null], ['Date', 'Name', 'Form'])
        );
    }

    public function test_order_row_indexed_passthrough_when_no_headers(): void
    {
        $this->assertSame(['a', 'b', 'c'], apps_gsheet_order_row(['a', 'b', 'c'], []));
    }

    public static function nextRowProvider(): array
    {
        return [
            'empty sheet'   => [[], 1],
            'null'          => [null, 1],
            'header+5 rows' => [[['h'], ['r1'], ['r2'], ['r3'], ['r4'], ['r5']], 7],
            'header only'   => [[['Date']], 2],
        ];
    }

    /** @dataProvider nextRowProvider */
    public function test_next_row_index($keyColumn, int $expected): void
    {
        $this->assertSame($expected, apps_gsheet_next_row_index($keyColumn));
    }

    public static function updateResultProvider(): array
    {
        return [
            'cells written'  => [['totalUpdatedCells' => 3], true],
            'rows written'   => [['totalUpdatedRows' => 1], true],
            'zero cells'     => [['totalUpdatedCells' => 0], false],
            'missing key'    => [[], false],
        ];
    }

    /** @dataProvider updateResultProvider */
    public function test_update_succeeded(array $simple, bool $expected): void
    {
        $this->assertSame($expected, apps_gsheet_update_succeeded($simple));
    }
}
