<?php

namespace Tests\Unit;

use Dev\Kernel\Commands\GDriveMirrorSync;
use PHPUnit\Framework\TestCase;

/**
 * Bảo vệ GDriveMirrorSync::resolveSiblingNames() — dedup tên CHUNG 1 namespace file+folder cho
 * TOÀN BỘ con TRỰC TIẾP của 1 folder cha, quyết định theo Drive file ID TĂNG DẦN (audit C1-C5/
 * L1-L3, 2026-07-19). Port 1:1 `resolveSiblingNames()`/`disambiguate()` bản Go
 * (tools/gdrive-mirror-cli/internal/mirror/list.go, list_test.go). Pure/static → không cần Drive
 * API thật hay boot Laravel.
 *
 * Mỗi test PHẢI ĐỎ nếu revert fix (RULE #0.0):
 *   C1 — 2 file khác ID chỉ khác hoa/thường → phải bị đổi tên, KHÔNG được ghi đè im lặng.
 *   C2 — file và folder trùng tên cùng cha → phải bị đổi tên, KHÔNG được đụng độ.
 *   C3 — 2 folder trùng tên → phải bị đổi tên, KHÔNG được gộp nội dung.
 *   C4 — thứ tự input (giả lập Drive trả về không ổn định) KHÔNG được ảnh hưởng tới ai giữ tên gốc.
 */
class GDriveResolveSiblingNamesTest extends TestCase
{
    private function rawChild(array $overrides = []): array
    {
        return array_merge([
            'id' => 'ID_' . uniqid(),
            'name' => 'file.pdf',
            'mimeType' => 'application/pdf',
            'md5Checksum' => 'abc123',
            'timestamp' => 1700000000,
            'size' => 1024,
            'isFolder' => false,
        ], $overrides);
    }

    public function test_no_collision_keeps_original_name(): void
    {
        $children = [
            $this->rawChild(['id' => 'AAA1', 'name' => 'alpha.pdf']),
            $this->rawChild(['id' => 'AAA2', 'name' => 'beta.pdf']),
        ];

        $resolved = GDriveMirrorSync::resolveSiblingNames($children);

        $byId = [];
        foreach ($resolved as $c) {
            $byId[$c['id']] = $c;
        }
        $this->assertSame('alpha.pdf', $byId['AAA1']['localName']);
        $this->assertFalse($byId['AAA1']['collided']);
        $this->assertSame('beta.pdf', $byId['AAA2']['localName']);
        $this->assertFalse($byId['AAA2']['collided']);
    }

    /**
     * C1: "Report.pdf" và "report.pdf" là 2 file KHÁC NHAU với Drive (ID khác nhau) nhưng CÙNG 1
     * file trên NTFS/APFS (case-insensitive theo mặc định) — không xử lý sẽ ghi đè mất dữ liệu
     * im lặng. Item ID nhỏ hơn (theo thứ tự tăng dần) giữ tên gốc; item ID lớn hơn phải đổi tên.
     */
    public function test_c1_case_only_clash_between_two_files_is_resolved(): void
    {
        $children = [
            $this->rawChild(['id' => '00002BBBB', 'name' => 'report.pdf']),
            $this->rawChild(['id' => '00001AAAA', 'name' => 'Report.pdf']),
        ];

        $resolved = GDriveMirrorSync::resolveSiblingNames($children);
        $byId = [];
        foreach ($resolved as $c) {
            $byId[$c['id']] = $c;
        }

        // ID nhỏ hơn ('00001AAAA') xử lý TRƯỚC → giữ tên gốc, không collide.
        $this->assertSame('Report.pdf', $byId['00001AAAA']['localName']);
        $this->assertFalse($byId['00001AAAA']['collided']);

        // ID lớn hơn ('00002BBBB') xử lý SAU, đụng key đã dùng ('report.pdf') → phải đổi tên.
        $this->assertTrue($byId['00002BBBB']['collided']);
        $this->assertNotSame('report.pdf', $byId['00002BBBB']['localName']);
        $this->assertStringContainsString(substr('00002BBBB', 0, 8), $byId['00002BBBB']['localName']);
        $this->assertStringEndsWith('.pdf', $byId['00002BBBB']['localName']);

        // Kết quả cuối phải KHÁC NHAU sau khi lower-case (không được đụng NTFS/APFS case-insensitive).
        $this->assertNotSame(
            mb_strtolower($byId['00001AAAA']['localName']),
            mb_strtolower($byId['00002BBBB']['localName'])
        );
    }

    /**
     * C2: 1 file và 1 folder trùng tên trong cùng cha — TRƯỚC fix, code cũ chỉ dedup trong tập
     * file (namespace riêng), nên file có thể ghi ĐÈ LÊN folder cùng tên. Giờ dùng CHUNG 1
     * namespace, folder giữ tên nếu ID nhỏ hơn thì file phải đổi tên (và ngược lại).
     */
    public function test_c2_file_and_folder_same_name_share_one_namespace(): void
    {
        $children = [
            $this->rawChild(['id' => '00001AAAA', 'name' => 'Invoices', 'isFolder' => true, 'mimeType' => 'application/vnd.google-apps.folder']),
            $this->rawChild(['id' => '00002BBBB', 'name' => 'Invoices', 'isFolder' => false, 'mimeType' => 'application/pdf']),
        ];

        $resolved = GDriveMirrorSync::resolveSiblingNames($children);
        $byId = [];
        foreach ($resolved as $c) {
            $byId[$c['id']] = $c;
        }

        $this->assertSame('Invoices', $byId['00001AAAA']['localName']);
        $this->assertFalse($byId['00001AAAA']['collided']);

        $this->assertTrue($byId['00002BBBB']['collided']);
        $this->assertNotSame('Invoices', $byId['00002BBBB']['localName']);
        $this->assertNotSame(
            mb_strtolower($byId['00001AAAA']['localName']),
            mb_strtolower($byId['00002BBBB']['localName'])
        );
    }

    /**
     * C3: 2 folder trùng tên trong cùng cha — TRƯỚC fix, cả 2 mkdir vào CÙNG 1 thư mục local →
     * nội dung 2 cây con Drive khác nhau gộp lẫn lộn âm thầm. Giờ folder thứ 2 (theo ID tăng dần)
     * phải nhận tên khác, để mỗi cây con Drive có thư mục local RIÊNG.
     */
    public function test_c3_two_folders_same_name_do_not_merge(): void
    {
        $children = [
            $this->rawChild(['id' => '00002BBBB', 'name' => 'Projects', 'isFolder' => true, 'mimeType' => 'application/vnd.google-apps.folder']),
            $this->rawChild(['id' => '00001AAAA', 'name' => 'Projects', 'isFolder' => true, 'mimeType' => 'application/vnd.google-apps.folder']),
        ];

        $resolved = GDriveMirrorSync::resolveSiblingNames($children);
        $byId = [];
        foreach ($resolved as $c) {
            $byId[$c['id']] = $c;
        }

        $this->assertSame('Projects', $byId['00001AAAA']['localName']);
        $this->assertFalse($byId['00001AAAA']['collided']);

        $this->assertTrue($byId['00002BBBB']['collided']);
        $this->assertNotSame('Projects', $byId['00002BBBB']['localName']);
        $this->assertNotSame($byId['00001AAAA']['localName'], $byId['00002BBBB']['localName']);
    }

    /**
     * C4: kết quả PHẢI ổn định bất kể thứ tự $rawChildren truyền vào (mô phỏng Drive files.list
     * trả về không đảm bảo thứ tự ổn định giữa 2 lần gọi) — vì quyết định LUÔN theo Drive ID tăng
     * dần, không theo thứ tự mảng input.
     */
    public function test_c4_result_is_independent_of_input_order(): void
    {
        $a = $this->rawChild(['id' => '00001AAAA', 'name' => 'dup.pdf']);
        $b = $this->rawChild(['id' => '00002BBBB', 'name' => 'dup.pdf']);

        $resolvedOrder1 = GDriveMirrorSync::resolveSiblingNames([$a, $b]);
        $resolvedOrder2 = GDriveMirrorSync::resolveSiblingNames([$b, $a]);

        $normalize = static function (array $resolved): array {
            $byId = [];
            foreach ($resolved as $c) {
                $byId[$c['id']] = $c['localName'];
            }
            ksort($byId);

            return $byId;
        };

        $this->assertSame($normalize($resolvedOrder1), $normalize($resolvedOrder2));
        // Khẳng định cụ thể: ID nhỏ hơn ('00001AAAA') LUÔN giữ tên gốc bất kể thứ tự input.
        $this->assertSame('dup.pdf', $normalize($resolvedOrder1)['00001AAAA']);
        $this->assertSame('dup.pdf', $normalize($resolvedOrder2)['00001AAAA']);
    }

    public function test_google_native_file_gets_export_extension_appended(): void
    {
        $children = [
            $this->rawChild([
                'id' => 'DOC1',
                'name' => 'My Report',
                'mimeType' => 'application/vnd.google-apps.document',
                'isFolder' => false,
            ]),
        ];

        $resolved = GDriveMirrorSync::resolveSiblingNames($children);

        $this->assertSame('My Report.docx', $resolved[0]['localName']);
    }

    public function test_three_way_collision_all_get_unique_names(): void
    {
        $children = [
            $this->rawChild(['id' => '00003CCCC', 'name' => 'DUP.pdf']),
            $this->rawChild(['id' => '00001AAAA', 'name' => 'dup.pdf']),
            $this->rawChild(['id' => '00002BBBB', 'name' => 'Dup.pdf']),
        ];

        $resolved = GDriveMirrorSync::resolveSiblingNames($children);

        $names = array_map(static fn (array $c) => mb_strtolower($c['localName']), $resolved);
        $this->assertCount(3, array_unique($names), 'all three colliding names must resolve to distinct local names');
    }

    public function test_dangerous_name_is_also_sanitized_before_dedup_key(): void
    {
        // Đảm bảo resolveSiblingNames() thật sự gọi sanitizeNameComponent() (không chỉ dedup
        // trên tên thô) — 2 tên khác nhau về ký tự cấm nhưng CÙNG kết quả sau sanitize phải đụng nhau.
        $children = [
            $this->rawChild(['id' => '00001AAAA', 'name' => 'a/b']),
            $this->rawChild(['id' => '00002BBBB', 'name' => 'a\\b']),
        ];

        $resolved = GDriveMirrorSync::resolveSiblingNames($children);
        $byId = [];
        foreach ($resolved as $c) {
            $byId[$c['id']] = $c;
        }

        $this->assertSame('a_b', $byId['00001AAAA']['localName']);
        $this->assertTrue($byId['00002BBBB']['collided']);
    }

    /**
     * ID Drive ngắn hơn cửa sổ hậu tố 8 ký tự trước đây làm nhánh ID của disambiguateLocalName()
     * không chạy lần nào, rơi thẳng xuống nhánh bộ đếm — nhánh này lại nối bộ đếm vào SAU phần
     * mở rộng, cho ra "bao cao_BBBB222.pdf-2": file mất sạch đuôi .pdf. Bắt được khi review port
     * PHP (2026-07-19); bản Go dính y hệt và đã sửa cùng lúc.
     */
    public function test_short_drive_id_keeps_extension_and_skips_counter_branch(): void
    {
        $resolved = GDriveMirrorSync::resolveSiblingNames([
            $this->rawChild(['id' => 'AAAA111', 'name' => 'bao cao.pdf']),
            $this->rawChild(['id' => 'BBBB222', 'name' => 'Bao Cao.pdf']),
        ]);

        $names = array_column($resolved, 'localName');

        foreach ($names as $name) {
            $this->assertStringEndsWith('.pdf', $name, "mất phần mở rộng: {$name}");
            $this->assertStringNotContainsString('-2', $name, "rơi nhầm xuống nhánh bộ đếm: {$name}");
        }

        $this->assertCount(2, array_unique($names), 'hai tên phải khác nhau sau khử trùng');
    }

    /**
     * Ca THẬT từ lần chạy 4843 item (2026-07-19): thư mục
     * "WEB19.0607 Ms Giau - Coding _ GHN - Facebook App" bị trùng tên và hậu tố chống trùng
     * chèn vào GIỮA tên ("WEB19_1e1Z27tn.0607 Ms Giau - ...") vì pathinfo() coi cả phần đuôi
     * sau "WEB19" là phần mở rộng. Thư mục KHÔNG có phần mở rộng — hậu tố phải ở CUỐI.
     */
    public function test_folder_name_containing_dot_keeps_suffix_at_the_end(): void
    {
        $dir = 'WEB19.0607 Ms Giau - Coding _ GHN - Facebook App';
        $resolved = GDriveMirrorSync::resolveSiblingNames([
            $this->rawChild(['id' => '1aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'name' => $dir, 'isFolder' => true, 'mimeType' => 'application/vnd.google-apps.folder']),
            $this->rawChild(['id' => '1e1Z27tnkodv7hsALrTCHxSp-ACdmdbfB', 'name' => $dir, 'isFolder' => true, 'mimeType' => 'application/vnd.google-apps.folder']),
        ]);

        $names = array_column($resolved, 'localName');
        foreach ($names as $name) {
            $this->assertStringStartsWith('WEB19.0607 Ms Giau', $name, "tên thư mục bị chèn hậu tố vào giữa: {$name}");
        }
        $this->assertCount(2, array_unique($names));
    }
}
