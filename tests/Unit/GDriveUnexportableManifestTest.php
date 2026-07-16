<?php

namespace Tests\Unit;

use Dev\Kernel\Commands\GDriveMirrorSync;
use PHPUnit\Framework\TestCase;

/**
 * Bảo vệ GDriveMirrorSync::buildUnexportableManifest() — build nội dung Markdown cho manifest
 * "file KHÔNG THỂ mirror" (storage/app/gdrive-sync/unexportable/<folderTag>.md). Method pure/
 * static (không đụng facade/$this) nên test không cần boot Laravel — khớp convention của
 * GDriveErrorClassificationTest.php.
 *
 * Dữ liệu thật (2026-07-16): 29 file unique KHÔNG BAO GIỜ mirror được — 19 x
 * exportSizeLimitExceeded (Google giới hạn cứng 10MB của files.export, tải tay được) + 10 x
 * cannotExportFile (chủ file khoá, không tải được bằng cách nào).
 */
class GDriveUnexportableManifestTest extends TestCase
{
    protected function meta(): array
    {
        return [
            'generated_at' => '2026-07-16T10:00:00+00:00',
            'run_id' => 'abcd1234',
            'folder_ids' => ['0Bw6yYZTQJcm3aGoxTGJuY1p1ZU0'],
            'base_local_path' => '/Volumes/WD-DATA1/OneDrive',
        ];
    }

    protected function permanentItem(array $overrides = []): array
    {
        return array_merge([
            'type' => 'file',
            'path' => 'Reports/file.pptx',
            'id' => 'FILEID_' . uniqid(),
            'mimeType' => 'application/vnd.google-apps.presentation',
            'md5Checksum' => null,
            'timestamp' => 1700000000,
            'size' => 5 * 1024 * 1024,
            'attempts' => 0,
            'reason' => 'This file is too large to be exported.',
            'permanent' => true,
            'error_reason' => 'exportSizeLimitExceeded',
            'category' => 'Export size limit',
        ], $overrides);
    }

    public function test_two_reason_groups_both_appear_in_first_seen_order(): void
    {
        $items = [
            $this->permanentItem([
                'path' => 'Slides/big-deck.pptx',
                'error_reason' => 'exportSizeLimitExceeded',
                'category' => 'Export size limit',
            ]),
            $this->permanentItem([
                'path' => 'Docs/locked-doc',
                'mimeType' => 'application/vnd.google-apps.document',
                'error_reason' => 'cannotExportFile',
                'category' => 'Not exportable (locked/unsupported)',
            ]),
        ];

        $md = GDriveMirrorSync::buildUnexportableManifest($items, $this->meta());

        $this->assertStringContainsString('## Export size limit', $md);
        $this->assertStringContainsString('## Not exportable (locked/unsupported)', $md);

        // Thứ tự nhóm = thứ tự xuất hiện đầu tiên trong input (exportSizeLimitExceeded trước).
        $posSize = strpos($md, '## Export size limit');
        $posLocked = strpos($md, '## Not exportable (locked/unsupported)');
        $this->assertLessThan($posLocked, $posSize);
    }

    public function test_sorted_by_size_descending_within_group(): void
    {
        $items = [
            $this->permanentItem(['path' => 'small.pptx', 'size' => 1 * 1024 * 1024]),
            $this->permanentItem(['path' => 'huge.pptx', 'size' => 9 * 1024 * 1024]),
            $this->permanentItem(['path' => 'medium.pptx', 'size' => 5 * 1024 * 1024]),
        ];

        $md = GDriveMirrorSync::buildUnexportableManifest($items, $this->meta());

        $posHuge = strpos($md, 'huge.pptx');
        $posMedium = strpos($md, 'medium.pptx');
        $posSmall = strpos($md, 'small.pptx');

        $this->assertNotFalse($posHuge);
        $this->assertNotFalse($posMedium);
        $this->assertNotFalse($posSmall);
        $this->assertLessThan($posMedium, $posHuge);
        $this->assertLessThan($posSmall, $posMedium);
    }

    public function test_url_built_from_mime_type_for_presentation_spreadsheet_and_fallback(): void
    {
        $items = [
            $this->permanentItem([
                'path' => 'Slides/deck.pptx',
                'id' => 'PRESID123',
                'mimeType' => 'application/vnd.google-apps.presentation',
            ]),
            $this->permanentItem([
                'path' => 'Sheets/data.xlsx',
                'id' => 'SHEETID456',
                'mimeType' => 'application/vnd.google-apps.spreadsheet',
                'error_reason' => 'exportSizeLimitExceeded',
                'category' => 'Export size limit',
            ]),
            $this->permanentItem([
                'path' => 'Videos/clip.mp4',
                'id' => 'FILEID789',
                'mimeType' => 'video/mp4',
                'error_reason' => 'cannotExportFile',
                'category' => 'Not exportable (locked/unsupported)',
            ]),
        ];

        $md = GDriveMirrorSync::buildUnexportableManifest($items, $this->meta());

        $this->assertStringContainsString('https://docs.google.com/presentation/d/PRESID123', $md);
        $this->assertStringContainsString('(tải .pptx)', $md);

        $this->assertStringContainsString('https://docs.google.com/spreadsheets/d/SHEETID456', $md);
        $this->assertStringContainsString('(tải .xlsx)', $md);

        // mimeType không nằm trong exportMap Google Native → fallback drive.google.com/file/d.
        $this->assertStringContainsString('https://drive.google.com/file/d/FILEID789', $md);
    }

    public function test_retryable_item_mixed_into_input_is_excluded(): void
    {
        $items = [
            $this->permanentItem(['path' => 'permanent-one.pptx']),
            [
                'type' => 'file',
                'path' => 'retryable-network-hiccup.pdf',
                'id' => 'RETRYID',
                'mimeType' => 'application/pdf',
                'md5Checksum' => null,
                'timestamp' => 1700000000,
                'size' => 1024,
                'attempts' => 3,
                'reason' => 'cURL error 28: Operation timed out',
                'permanent' => false,
                'error_reason' => null,
                'category' => 'Network / timeout',
            ],
        ];

        $md = GDriveMirrorSync::buildUnexportableManifest($items, $this->meta());

        $this->assertStringContainsString('permanent-one.pptx', $md);
        $this->assertStringNotContainsString('retryable-network-hiccup.pdf', $md);
        $this->assertStringNotContainsString('Network / timeout', $md);
    }

    public function test_empty_input_returns_empty_string(): void
    {
        $md = GDriveMirrorSync::buildUnexportableManifest([], $this->meta());

        $this->assertSame('', $md);
    }

    public function test_all_retryable_input_also_returns_empty_string(): void
    {
        // Method tự lọc permanent bên trong (defensive) — caller lỡ truyền toàn retryable thì
        // vẫn không sinh ra manifest rỗng-nội-dung (writeUnexportableManifest() coi đây là "hết
        // permanent-fail" và xoá manifest cũ thay vì ghi file trống).
        $items = [
            [
                'type' => 'file',
                'path' => 'still-retryable.pdf',
                'id' => 'RETRYID2',
                'mimeType' => 'application/pdf',
                'size' => 1024,
                'permanent' => false,
                'error_reason' => 'backendError',
                'category' => 'Server error (5xx)',
            ],
        ];

        $md = GDriveMirrorSync::buildUnexportableManifest($items, $this->meta());

        $this->assertSame('', $md);
    }

    public function test_header_contains_meta_fields(): void
    {
        $md = GDriveMirrorSync::buildUnexportableManifest([$this->permanentItem()], $this->meta());

        $this->assertStringContainsString('2026-07-16T10:00:00+00:00', $md);
        $this->assertStringContainsString('abcd1234', $md);
        $this->assertStringContainsString('0Bw6yYZTQJcm3aGoxTGJuY1p1ZU0', $md);
        $this->assertStringContainsString('/Volumes/WD-DATA1/OneDrive', $md);
    }
}
