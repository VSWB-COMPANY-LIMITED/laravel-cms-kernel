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

    /** Sheet đầy dòng: nhận diện lỗi grid-limit để chủ động nới grid (auto-grow). */
    public function test_is_grid_limit_error_detects_exceeds_message(): void
    {
        $e = new \Exception('Invalid data[0]: Range (Raw!A19766) exceeds grid limits. Max rows: 19765, max columns: 23');
        $this->assertTrue(apps_gsheet_is_grid_limit_error($e));
    }

    public function test_is_grid_limit_error_false_for_other_errors(): void
    {
        $this->assertFalse(apps_gsheet_is_grid_limit_error(new \Exception('PERMISSION_DENIED')));
        $this->assertFalse(apps_gsheet_is_grid_limit_error(new \Exception('')));
    }

    /** Lỗi tạm thời (503/500/429) => retry; grid-limit(400)/permission(403) => KHÔNG retry. */
    public function test_is_transient_error_true_for_5xx_429(): void
    {
        $this->assertTrue(apps_gsheet_is_transient_error(new \Exception('The service is currently unavailable.', 503)));
        $this->assertTrue(apps_gsheet_is_transient_error(new \Exception('Internal error encountered.', 500)));
        $this->assertTrue(apps_gsheet_is_transient_error(new \Exception('rateLimitExceeded', 429)));
    }

    public function test_is_transient_error_false_for_permanent(): void
    {
        // grid-limit là 400 (không transient — xử lý bằng nới grid, không backoff)
        $this->assertFalse(apps_gsheet_is_transient_error(new \Exception('exceeds grid limits. Max rows: 19765', 400)));
        $this->assertFalse(apps_gsheet_is_transient_error(new \Exception('PERMISSION_DENIED', 403)));
    }
}
