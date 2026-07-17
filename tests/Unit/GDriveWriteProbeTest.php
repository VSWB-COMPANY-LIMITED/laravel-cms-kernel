<?php

namespace Tests\Unit;

use Dev\Kernel\Commands\GDriveMirrorSync;
use PHPUnit\Framework\TestCase;

/**
 * Bảo vệ GDriveMirrorSync::writeProbe() — ghi-thử THẬT dùng cho preflight, thay is_writable().
 *
 * Bối cảnh (sự cố THẬT 2026-07-18, run 7fe28ffb): ổ exFAT ngoài /Volumes/WD-DATA1 hỏng I/O,
 * is_writable() báo true nhưng mkdir/ghi thật ném "Input/output error" → preflight cũ lọt qua →
 * list 227 item Drive rồi mới đụng circuit breaker (~1 phút phí). writeProbe() ghi 1 file thật
 * để bắt ổ read-only/hỏng NGAY. Test dùng thư mục tạm THẬT (không mock filesystem).
 */
class GDriveWriteProbeTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/gdrive_probe_test_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if ($this->tmpDir !== '' && is_dir($this->tmpDir)) {
            @rmdir($this->tmpDir);
        }
    }

    public function test_returns_true_on_writable_dir(): void
    {
        self::assertTrue(GDriveMirrorSync::writeProbe($this->tmpDir, 'tok1'));
    }

    public function test_cleans_up_probe_file_after_success(): void
    {
        GDriveMirrorSync::writeProbe($this->tmpDir, 'tok2');
        // Không để rác lại: file probe phải bị xoá sau khi kiểm tra xong.
        self::assertFalse(file_exists($this->tmpDir . '/.gdrive_write_probe_tok2'));
    }

    public function test_returns_false_on_nonexistent_parent(): void
    {
        // Ghi vào thư mục con không tồn tại (không mkdir) → file_put_contents fail → false.
        self::assertFalse(GDriveMirrorSync::writeProbe($this->tmpDir . '/khong_ton_tai_sub', 'tok3'));
    }

    public function test_two_tokens_do_not_collide(): void
    {
        // 2 run song song dùng runId khác → file probe khác nhau, cả hai đều thành công.
        self::assertTrue(GDriveMirrorSync::writeProbe($this->tmpDir, 'runA'));
        self::assertTrue(GDriveMirrorSync::writeProbe($this->tmpDir, 'runB'));
    }
}
