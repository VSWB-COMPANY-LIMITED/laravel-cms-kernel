<?php

namespace Tests\Unit;

use Dev\Kernel\Commands\GDriveMirrorSync;
use PHPUnit\Framework\TestCase;

/**
 * Bảo vệ GDriveMirrorSync::buildFailedRows() + renderFailedCsv() — 2 method pure/static dùng để
 * xuất spreadsheet danh sách file lỗi TỰ ĐỘNG mỗi lần `gdrive:mirror:sync` chạy xong mà có
 * failed_files (xem finalReport() → writeFailedSpreadsheet()). Không boot Laravel — cùng convention
 * GDriveUnexportableManifestTest/GDriveErrorClassificationTest.
 *
 * KHÔNG test nhánh .xlsx (OpenSpout) ở đây — kernel package không require OpenSpout/PhpSpreadsheet
 * (chỉ pull-server có), nhánh đó verify live ở pull-server. writeFailedSpreadsheet() cũng KHÔNG
 * test trực tiếp (gọi storage_path() cần boot Laravel app, không có trong kernel test env) — chỉ
 * test 2 method thuần đứng sau nó.
 */
class GDriveFailedRowsTest extends TestCase
{
    protected function meta(array $overrides = []): array
    {
        return array_merge([
            'base_local_path' => '/mnt/backup/drive-mirror',
            'folder_ids' => ['0BxFAKEFOLDERID_forTest_00000'],
            'run_id' => 'abcd1234',
            'generated_at' => '2026-07-17T10:00:00+00:00',
        ], $overrides);
    }

    protected function exportSizeLimitItem(): array
    {
        return [
            'type' => 'file',
            'path' => 'Slides/big-deck.pptx',
            'id' => 'PRESID_BIG',
            'mimeType' => 'application/vnd.google-apps.presentation',
            'size' => 76 * 1024 * 1024,
            'attempts' => 0,
            'reason' => 'This file is too large to be exported.',
            'permanent' => true,
            'error_reason' => 'exportSizeLimitExceeded',
            'category' => 'Export size limit',
        ];
    }

    protected function cannotDownloadFileItem(): array
    {
        // Body JSON THẬT khớp định dạng lỗi Google (xem classifyDriveError() permanentReasons —
        // ghi chú bằng chứng run 9cd3158b 2026-07-17).
        $body = json_encode([
            'error' => [
                'errors' => [
                    [
                        'domain' => 'global',
                        'reason' => 'cannotDownloadFile',
                        'message' => 'This file cannot be downloaded by the user.',
                    ],
                ],
                'code' => 403,
                'message' => 'This file cannot be downloaded by the user.',
            ],
        ]);

        return [
            'type' => 'file',
            'path' => 'Locked/no-download.pdf',
            'id' => 'FILEID_LOCKED',
            'mimeType' => 'application/pdf',
            'size' => 2 * 1024 * 1024,
            'attempts' => 1,
            'reason' => $body,
            'permanent' => true,
            'error_reason' => 'cannotDownloadFile',
            'category' => 'Not downloadable (chủ tắt quyền tải)',
        ];
    }

    protected function nativePresentationItem(): array
    {
        return [
            'type' => 'file',
            'path' => 'Slides/deck.pptx',
            'id' => 'PRESID123',
            'mimeType' => 'application/vnd.google-apps.presentation',
            'size' => 3 * 1024 * 1024,
            'attempts' => 3,
            'reason' => 'cURL error 28: Operation timed out',
            'permanent' => false,
            'error_reason' => null,
            'category' => 'Network / timeout',
        ];
    }

    protected function regularXlsxItem(): array
    {
        return [
            'type' => 'file',
            'path' => 'Reports/data.xlsx',
            'id' => 'FILEID_XLSX',
            'mimeType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'size' => 1 * 1024 * 1024,
            'attempts' => 2,
            'reason' => 'cURL error 28: Operation timed out',
            'permanent' => false,
            'error_reason' => null,
            'category' => 'Network / timeout',
        ];
    }

    protected function zeroSizeItem(): array
    {
        return [
            'type' => 'file',
            'path' => 'Empty/placeholder.txt',
            'id' => 'FILEID_ZERO',
            'mimeType' => 'text/plain',
            'size' => 0,
            'attempts' => 0,
            'reason' => 'Not Found',
            'permanent' => true,
            'error_reason' => 'notFound',
            'category' => 'Not found',
        ];
    }

    public function test_empty_input_returns_empty_list(): void
    {
        $this->assertSame([], GDriveMirrorSync::buildFailedRows([], $this->meta()));
    }

    public function test_columns_exact_name_and_order(): void
    {
        $rows = GDriveMirrorSync::buildFailedRows([$this->exportSizeLimitItem()], $this->meta());

        $this->assertCount(1, $rows);
        $this->assertSame([
            'file', 'path', 'local_path', 'folder_root_id', 'loai', 'google_native', 'mime_type',
            'size_bytes', 'size', 'category', 'error_reason', 'permanent', 'attempts',
            'error_message', 'drive_id', 'drive_link', 'run_id', 'report_time', 'trung_file',
            'dong_dai_dien',
        ], array_keys($rows[0]));
    }

    public function test_export_size_limit_permanent_row_fields(): void
    {
        $rows = GDriveMirrorSync::buildFailedRows(
            [$this->exportSizeLimitItem()],
            $this->meta(['run_id' => 'runXYZ', 'generated_at' => '2026-07-17T12:00:00+00:00'])
        );
        $row = $rows[0];

        $this->assertSame('big-deck.pptx', $row['file']);
        $this->assertSame('Slides/big-deck.pptx', $row['path']);
        $this->assertSame('/mnt/backup/drive-mirror/Slides/big-deck.pptx', $row['local_path']);
        $this->assertSame('0BxFAKEFOLDERID_forTest_00000', $row['folder_root_id']);
        $this->assertSame('file', $row['loai']);
        $this->assertSame('yes', $row['google_native']);
        $this->assertSame((string) (76 * 1024 * 1024), $row['size_bytes']);
        $this->assertSame('76.0 MB', $row['size']);
        $this->assertSame('Export size limit', $row['category']);
        $this->assertSame('exportSizeLimitExceeded', $row['error_reason']);
        $this->assertSame('yes', $row['permanent']);
        $this->assertSame('runXYZ', $row['run_id']);
        $this->assertSame('2026-07-17T12:00:00+00:00', $row['report_time']);
        // Google Native presentation → link editor Docs, không phải drive.google.com/file/d.
        $this->assertSame('https://docs.google.com/presentation/d/PRESID_BIG', $row['drive_link']);
    }

    public function test_cannot_download_file_extracts_message_from_json_body(): void
    {
        $rows = GDriveMirrorSync::buildFailedRows([$this->cannotDownloadFileItem()], $this->meta());
        $row = $rows[0];

        $this->assertSame('no', $row['google_native']);
        $this->assertSame('This file cannot be downloaded by the user.', $row['error_message']);
        $this->assertSame('cannotDownloadFile', $row['error_reason']);
        $this->assertSame('yes', $row['permanent']);
        // File thường (không phải Google Native) → link drive.google.com/file/d.
        $this->assertSame('https://drive.google.com/file/d/FILEID_LOCKED', $row['drive_link']);
    }

    public function test_regular_xlsx_drive_link_uses_file_url_not_docs_editor(): void
    {
        $rows = GDriveMirrorSync::buildFailedRows([$this->regularXlsxItem()], $this->meta());
        $row = $rows[0];

        $this->assertSame('no', $row['google_native']);
        $this->assertSame('https://drive.google.com/file/d/FILEID_XLSX', $row['drive_link']);
        $this->assertSame('no', $row['permanent']);
    }

    public function test_zero_size_item_has_dash_size_and_zero_size_bytes(): void
    {
        $rows = GDriveMirrorSync::buildFailedRows([$this->zeroSizeItem()], $this->meta());
        $row = $rows[0];

        // humanSize() (dùng chung với buildUnexportableManifest()) trả '-' cho size<=0, KHÔNG
        // phải chuỗi rỗng — size_bytes vẫn giữ nguyên giá trị thô '0'.
        $this->assertSame('0', $row['size_bytes']);
        $this->assertSame('-', $row['size']);
    }

    public function test_sorted_permanent_first_then_category_then_size_desc(): void
    {
        // Trộn: 2 permanent (export-size-limit 76MB, not-found 0 byte) + 2 retryable cùng category
        // Network/timeout (3MB, 1MB) — kỳ vọng permanent lên đầu, trong nhóm permanent theo
        // category (Export size limit < Not found theo string so-sánh ASCII 'E' < 'N'), trong
        // nhóm retryable size giảm dần.
        $rows = GDriveMirrorSync::buildFailedRows([
            $this->regularXlsxItem(),       // retryable, Network/timeout, 1MB
            $this->zeroSizeItem(),          // permanent, Not found, 0 byte
            $this->nativePresentationItem(), // retryable, Network/timeout, 3MB
            $this->exportSizeLimitItem(),   // permanent, Export size limit, 76MB
        ], $this->meta());

        $paths = array_column($rows, 'path');

        $this->assertSame([
            'Slides/big-deck.pptx',   // permanent, Export size limit
            'Empty/placeholder.txt',  // permanent, Not found
            'Slides/deck.pptx',       // retryable, Network/timeout, 3MB
            'Reports/data.xlsx',      // retryable, Network/timeout, 1MB
        ], $paths);
    }

    public function test_duplicate_drive_id_across_two_paths_marked_trung_file(): void
    {
        // Cùng 1 file Drive (id giống hệt) lặp lại ở 2 path khác nhau — mô phỏng 1 folder con nằm
        // lồng trong 2 job mirror khác nhau (xem giải thích trong buildFailedRows()).
        $itemA = $this->regularXlsxItem();
        $itemA['path'] = 'JobA/data.xlsx';
        $itemA['id'] = 'DUPID';

        $itemB = $this->regularXlsxItem();
        $itemB['path'] = 'JobB/nested/data.xlsx';
        $itemB['id'] = 'DUPID';

        $rows = GDriveMirrorSync::buildFailedRows([$itemA, $itemB], $this->meta());

        $this->assertSame('yes', $rows[0]['trung_file']);
        $this->assertSame('yes', $rows[1]['trung_file']);

        // Đúng 1 dòng được đánh dong_dai_dien=yes (dòng gặp trước) để lọc ra danh sách file riêng biệt.
        $flags = array_column($rows, 'dong_dai_dien');
        $this->assertSame(['yes', 'no'], $flags);
    }

    public function test_non_duplicate_ids_are_not_marked_trung_file(): void
    {
        $rows = GDriveMirrorSync::buildFailedRows([
            $this->exportSizeLimitItem(),
            $this->cannotDownloadFileItem(),
        ], $this->meta());

        foreach ($rows as $row) {
            $this->assertSame('no', $row['trung_file']);
            $this->assertSame('yes', $row['dong_dai_dien']);
        }
    }

    public function test_render_failed_csv_has_bom_and_semicolon_delimiter(): void
    {
        $rows = GDriveMirrorSync::buildFailedRows([$this->exportSizeLimitItem()], $this->meta());
        $csv = GDriveMirrorSync::renderFailedCsv($rows);

        $this->assertSame("\xEF\xBB\xBF", substr($csv, 0, 3));

        $headerLine = strtok(substr($csv, 3), "\n");
        $this->assertStringStartsWith('file;path;local_path;', $headerLine);
        $this->assertStringNotContainsString(',', $headerLine);
    }

    public function test_render_failed_csv_empty_rows_returns_empty_string(): void
    {
        $this->assertSame('', GDriveMirrorSync::renderFailedCsv([]));
    }
}
