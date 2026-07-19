<?php

namespace Tests\Unit;

use Dev\Kernel\Commands\GDriveMirrorSync;
use PHPUnit\Framework\TestCase;

/**
 * Bảo vệ 3 helper tên-file-an-toàn thêm vào GDriveMirrorSync trong audit C1-C5/L1-L3
 * (2026-07-19) — port 1:1 từ `tools/gdrive-mirror-cli/internal/localpath/localpath.go` (đã có
 * bộ test Go tương ứng `localpath_test.go`). Pure/static (không đụng $this/facade) nên test
 * không cần boot Laravel — cùng convention GDriveErrorClassificationTest.php.
 *
 * Mỗi test ở đây PHẢI ĐỎ nếu revert fix (RULE #0.0) — verify bằng cách test trực tiếp lỗi gốc:
 *   L2  — ký tự Windows cấm không bị lọc
 *   L3  — tên thiết bị dành riêng (CON, PRN…) không bị chèn hậu tố
 *   L1  — không giới hạn 255 byte/component, và bẫy pathinfo() lấy đuôi dài làm "extension"
 *   C5  — nối chuỗi trần cho phép Drive item name chứa "/"/".." ghi ra ngoài --path
 */
class GDriveLocalPathTest extends TestCase
{
    // ───────────────────────── sanitizeNameComponent() — L2/L3 ─────────────────────────

    public function test_forbidden_chars_replaced_with_underscore(): void
    {
        $got = GDriveMirrorSync::sanitizeNameComponent('a<b>c:d"e|f?g*h\\i/j');
        $this->assertSame('a_b_c_d_e_f_g_h_i_j', $got);
    }

    public function test_control_chars_replaced(): void
    {
        $got = GDriveMirrorSync::sanitizeNameComponent("a\x00b\x1fc");
        $this->assertSame('a_b_c', $got);
    }

    public function test_trailing_dots_and_spaces_trimmed(): void
    {
        $cases = [
            'report.' => 'report',
            'report ' => 'report',
            'report. .' => 'report',
            'report...' => 'report',
            'a.b.' => 'a.b',
        ];
        foreach ($cases as $in => $want) {
            $this->assertSame($want, GDriveMirrorSync::sanitizeNameComponent($in), "input={$in}");
        }
    }

    public function test_empty_name_falls_back_to_underscore(): void
    {
        $this->assertSame('_', GDriveMirrorSync::sanitizeNameComponent(''));
        // Mỗi ký tự cấm bị thay THEO TỪNG KÝ TỰ (không bị strip), nên "///" thành "___" — khác
        // rỗng, không bao giờ rơi vào nhánh fallback rỗng.
        $this->assertSame('___', GDriveMirrorSync::sanitizeNameComponent('///'));
        // Tên TOÀN dấu chấm/khoảng trắng (không còn gì sau khi trim) mới thật sự chạm nhánh fallback rỗng.
        $this->assertSame('_', GDriveMirrorSync::sanitizeNameComponent('...'));
    }

    public function test_dot_and_dotdot_never_survive(): void
    {
        foreach (['.', '..'] as $in) {
            $got = GDriveMirrorSync::sanitizeNameComponent($in);
            $this->assertNotSame('.', $got, "input={$in}");
            $this->assertNotSame('..', $got, "input={$in}");
            $this->assertNotSame('', $got, "input={$in}");
        }
    }

    public function test_reserved_device_names_get_underscore_inserted(): void
    {
        $cases = [
            'CON' => 'CON_',
            'con' => 'con_',
            'PRN' => 'PRN_',
            'AUX' => 'AUX_',
            'NUL' => 'NUL_',
            'COM1' => 'COM1_',
            'com9' => 'com9_',
            'LPT1' => 'LPT1_',
            'lpt9' => 'lpt9_',
            'CON.txt' => 'CON_.txt',
            'con.TXT' => 'con_.TXT',
            'COM1.tar.gz' => 'COM1_.tar.gz',
        ];
        foreach ($cases as $in => $want) {
            $this->assertSame($want, GDriveMirrorSync::sanitizeNameComponent($in), "input={$in}");
        }
    }

    public function test_reserved_name_is_not_a_prefix_match(): void
    {
        // "CONSOLE" KHÔNG được coi là reserved chỉ vì bắt đầu bằng CON.
        $this->assertSame('CONSOLE.txt', GDriveMirrorSync::sanitizeNameComponent('CONSOLE.txt'));
    }

    // ───────────────────────── truncateNameComponent() — L1 ─────────────────────────

    public function test_short_name_unchanged(): void
    {
        $this->assertSame('short.txt', GDriveMirrorSync::truncateNameComponent('short.txt', 0));
    }

    public function test_limits_to_255_bytes(): void
    {
        $long = str_repeat('a', 400) . '.txt';
        $got = GDriveMirrorSync::truncateNameComponent($long, 0);
        $this->assertLessThanOrEqual(255, strlen($got));
        $this->assertStringEndsWith('.txt', $got);
    }

    public function test_reserve_is_respected(): void
    {
        $long = str_repeat('a', 400);
        $got = GDriveMirrorSync::truncateNameComponent($long, 20);
        $this->assertLessThanOrEqual(255 - 20, strlen($got));
    }

    public function test_rune_boundary_vietnamese_stays_valid_utf8(): void
    {
        // Dấu tiếng Việt là UTF-8 nhiều byte — cắt mù byte sẽ chặt đứt giữa 1 ký tự và sinh ra
        // chuỗi UTF-8 hỏng.
        $word = 'Đơn xin nghỉ phép có dấu tiếng Việt đầy đủ dài dòng văn tự ';
        $long = str_repeat($word, 10) . '.docx';
        $got = GDriveMirrorSync::truncateNameComponent($long, 0);
        $this->assertLessThanOrEqual(255, strlen($got));
        $this->assertTrue(mb_check_encoding($got, 'UTF-8'), 'result must be valid UTF-8');
    }

    public function test_different_long_names_stay_distinct_after_truncate(): void
    {
        $a = str_repeat('x', 400) . '-first-file.pdf';
        $b = str_repeat('x', 400) . '-second-file.pdf';
        $gotA = GDriveMirrorSync::truncateNameComponent($a, 0);
        $gotB = GDriveMirrorSync::truncateNameComponent($b, 0);
        $this->assertNotSame($gotA, $gotB);
    }

    public function test_same_name_is_deterministic(): void
    {
        $long = str_repeat('y', 400) . '.pdf';
        $this->assertSame(
            GDriveMirrorSync::truncateNameComponent($long, 0),
            GDriveMirrorSync::truncateNameComponent($long, 0)
        );
    }

    /**
     * 🔴 Bẫy pathinfo() bắt buộc phải có test (yêu cầu task): tên kiểu VN phổ biến
     * "Bao cao Q1.2026 - <text dài>" khiến pathinfo(PATHINFO_EXTENSION) coi CẢ ĐUÔI DÀI là
     * "extension" (giống filepath.Ext bên Go lấy phần sau dấu chấm CUỐI CÙNG) — giữ nguyên
     * verbatim sẽ đẩy kết quả VƯỢT 255 byte nếu không có MAX_EXT_BYTES chặn lại.
     */
    public function test_long_fake_extension_from_last_dot_still_capped_at_255_bytes(): void
    {
        $name = 'Bao cao Q1.' . str_repeat('x', 300);
        $got = GDriveMirrorSync::truncateNameComponent($name, 0);
        $this->assertLessThanOrEqual(255, strlen($got), "result {$got} bytes exceeds the 255-byte cap");
        $this->assertTrue(mb_check_encoding($got, 'UTF-8'));
    }

    public function test_real_extension_still_preserved_after_fake_extension_fix(): void
    {
        $name = str_repeat('t', 300) . '.docx';
        $got = GDriveMirrorSync::truncateNameComponent($name, 0);
        $this->assertLessThanOrEqual(255, strlen($got));
        $this->assertStringEndsWith('.docx', $got, 'a genuine extension must survive truncation');
    }

    public function test_byte_cap_holds_under_extreme_reserve(): void
    {
        foreach ([0, 200, 250, 254, 255, 300] as $reserve) {
            $name = str_repeat('ạ', 200) . '.pdf';
            $got = GDriveMirrorSync::truncateNameComponent($name, $reserve);
            $limit = max(0, 255 - $reserve);
            $this->assertLessThanOrEqual($limit, strlen($got), "reserve={$reserve}");
            $this->assertTrue(mb_check_encoding($got, 'UTF-8'), "reserve={$reserve}");
        }
    }

    // ───────────────────────── safeJoinLocalPath() — C5 ─────────────────────────

    public function test_normal_path_stays_inside_root(): void
    {
        $root = sys_get_temp_dir() . '/gdrive_safejoin_test_' . uniqid();
        $got = GDriveMirrorSync::safeJoinLocalPath($root, 'sub/dir/file.txt');
        $this->assertStringStartsWith($root, $got);
    }

    public function test_traversal_attempt_throws_invalid_argument_exception(): void
    {
        $root = sys_get_temp_dir() . '/gdrive_safejoin_test_' . uniqid();
        $this->expectException(\InvalidArgumentException::class);
        GDriveMirrorSync::safeJoinLocalPath($root, '../escape.txt');
    }

    public function test_each_traversal_case_individually_blocked(): void
    {
        $root = sys_get_temp_dir() . '/gdrive_safejoin_test_' . uniqid();
        foreach (['../escape.txt', 'a/../../escape.txt', '../../../../etc/passwd'] as $rel) {
            $threw = false;
            try {
                GDriveMirrorSync::safeJoinLocalPath($root, $rel);
            } catch (\InvalidArgumentException $e) {
                $threw = true;
            }
            $this->assertTrue($threw, "expected traversal to be blocked for relPath={$rel}");
        }
    }

    public function test_root_itself_is_allowed(): void
    {
        $root = sys_get_temp_dir() . '/gdrive_safejoin_test_' . uniqid();
        $got = GDriveMirrorSync::safeJoinLocalPath($root, '.');
        $this->assertNotSame('', $got);
        $this->assertSame(rtrim($got, '/'), rtrim($root, '/'));
    }

    /**
     * isInvalidLocalName() phải nhận diện được message do chính safeJoinLocalPath() ném ra — đây
     * là cầu nối để classifyDriveError()/isLocalFsError() phân loại đúng permanent + không tính
     * vào circuit breaker ổ đĩa (xem GDriveInvalidLocalNameTest).
     */
    public function test_safe_join_rejection_message_is_recognized_as_invalid_local_name(): void
    {
        $root = sys_get_temp_dir() . '/gdrive_safejoin_test_' . uniqid();
        try {
            GDriveMirrorSync::safeJoinLocalPath($root, '../escape.txt');
            $this->fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertTrue(GDriveMirrorSync::isInvalidLocalName($e->getMessage()));
        }
    }
}
