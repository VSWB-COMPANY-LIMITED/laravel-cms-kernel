<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Chống lỗi "cột nhảy" (Thaco, 22–27/10/2026): append() để Google tự dò bảng bị lệch sang cột I rồi +8 cột
 * mỗi lượt khi sheet có dòng trống giữa dữ liệu. Neo range vào cột A và phát hiện dòng ghi lệch.
 * Hành vi thật của Google đã tái hiện trên sheet thử: không neo ⇒ I,Q,Y...; neo 'Tab'!A:A ⇒ A,A,A.
 */
class AppsGsheetAppendRangeTest extends TestCase
{
    public function test_append_range_quotes_tab_and_targets_column_a(): void
    {
        $this->assertSame("'Raw Lead'!A:A", apps_gsheet_append_range('Raw Lead'));
    }

    public function test_append_range_doubles_single_quotes_in_tab_name(): void
    {
        $this->assertSame("'Khach''s data'!A:A", apps_gsheet_append_range("Khach's data"));
    }

    /** Tên tab trùng cách viết ô (vd "E1") phải được bọc nháy để Google không hiểu thành ô A1. */
    public function test_append_range_quotes_tab_that_looks_like_a_cell(): void
    {
        $this->assertSame("'E1'!A:A", apps_gsheet_append_range('E1'));
    }

    public static function updatedRangeProvider(): array
    {
        return [
            'normal row from A'            => ["'Raw Lead'!A3605:M3605", true],
            'single cell from A'           => ["'Raw'!A26958", true],
            'unquoted tab'                 => ['Sheet1!A2:Z2', true],
            'shifted to I (the bug)'       => ["'Raw Lead'!I3605:U3605", false],
            'shifted further (Q)'          => ["'Raw Lead'!Q3606:AC3606", false],
            // Cột bắt đầu bằng chữ A nhưng KHÔNG phải cột A — không được nhận nhầm.
            'column AG is not column A'    => ["'Raw Lead'!AG3656:AR3656", false],
            'column AW is not column A'    => ["'Raw Lead'!AW3663:BI3663", false],
            'empty string'                 => ['', false],
            'null'                         => [null, false],
        ];
    }

    #[DataProvider('updatedRangeProvider')]
    public function test_range_starts_at_column_a(?string $range, bool $expected): void
    {
        $this->assertSame($expected, apps_gsheet_range_starts_at_column_a($range));
    }
}
