<?php

/**
 * © 2026 VISUAL WEBER COMPANY LIMITED. All rights reserved.
 * Proprietary software developed and distributed by Visual Weber.
 * Use is permitted only under a valid license agreement.
 *
 * © 2026 CÔNG TY TNHH VISUAL WEBER. Bảo lưu mọi quyền.
 * Phần mềm độc quyền của Visual Weber, chỉ được sử dụng theo Hợp đồng cấp phép.
 */

namespace Dev\Kernel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        // Chỉ set headers cho HTTP responses (không set cho redirects, JSON, etc.)
        if (!$response instanceof Response) {
            return $response;
        }

        /*
        |--------------------------------------------------------------------------
        | Strict-Transport-Security (HSTS)
        |--------------------------------------------------------------------------
        | Bắt buộc trình duyệt chỉ dùng HTTPS trong 1 năm (31536000 giây).
        | includeSubDomains: áp dụng cho toàn bộ subdomain.
        | preload: để có thể submit vào danh sách preload của Chrome.
        | 
        | QUAN TRỌNG: Chỉ set HSTS khi request là HTTPS để tránh lỗi.
        */
        if ($request->secure() || $request->header('X-Forwarded-Proto') === 'https') {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains; preload',
                false // Don't replace if already exists
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Content-Security-Policy (CSP)
        |--------------------------------------------------------------------------
        | Bạn có thể tùy chỉnh whitelist tuỳ project.
        | Đây là CSP ở mức an toàn nhưng vẫn cho phép CDN và inline styles.
        | 
        | Note: Nếu cần whitelist thêm domains, sửa trong config hoặc override middleware.
        */
        $csp = $this->buildCsp($request);
        $response->headers->set(
            'Content-Security-Policy',
            $csp,
            false // Don't replace if already exists
        );

        /*
        |--------------------------------------------------------------------------
        | Permissions-Policy (formerly Feature-Policy)
        |--------------------------------------------------------------------------
        | Giới hạn các API nguy hiểm.
        | Format: feature=(allowlist|self|*|())
        */
        $response->headers->set(
            'Permissions-Policy',
            'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()',
            false // Don't replace if already exists
        );

        /*
        |--------------------------------------------------------------------------
        | X-Content-Type-Options
        |--------------------------------------------------------------------------
        | Chống trình duyệt tự đoán định dạng file (MIME type sniffing).
        */
        $response->headers->set('X-Content-Type-Options', 'nosniff', false);

        /*
        |--------------------------------------------------------------------------
        | X-Frame-Options
        |--------------------------------------------------------------------------
        | Chống clickjacking (nhúng website vào iframe).
        | SAMEORIGIN: Cho phép nhúng từ cùng origin.
        */
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN', false);

        /*
        |--------------------------------------------------------------------------
        | Referrer-Policy
        |--------------------------------------------------------------------------
        | Hạn chế rò rỉ thông tin referrer.
        | strict-origin-when-cross-origin: Gửi full URL cho same-origin, chỉ origin cho cross-origin HTTPS.
        */
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin', false);

        /*
        |--------------------------------------------------------------------------
        | X-XSS-Protection (Legacy, nhưng vẫn hữu ích cho browser cũ)
        |--------------------------------------------------------------------------
        | Enable XSS filter trong browser (nếu hỗ trợ).
        */
        $response->headers->set('X-XSS-Protection', '1; mode=block', false);

        return $response;
    }

    /**
     * Build Content-Security-Policy header.
     * 
     * @param  \Illuminate\Http\Request  $request
     * @return string
     */
    protected function buildCsp(Request $request): string
    {
        // Có thể lấy từ config hoặc env
        $cspConfig = config('kernel.kernel.security.csp', []);

        // Default CSP - cân bằng giữa security và usability
        $directives = [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval' https: http:", // Cho phép CDN và inline scripts
            "style-src 'self' 'unsafe-inline' https: http:", // Cho phép CDN và inline styles
            "img-src 'self' data: https: http:", // Cho phép images từ mọi nguồn HTTPS/HTTP
            "font-src 'self' data: https: http:", // Cho phép fonts từ CDN
            "connect-src 'self' https: http: wss: ws:", // Cho phép AJAX/fetch + WebSocket realtime (Echo/socket.io ws.pull.vn)
            "frame-src 'self' https:", // Cho phép iframes từ HTTPS
            "object-src 'none'", // Không cho phép object/embed
            "base-uri 'self'", // Chỉ cho phép base tag từ same origin
            "form-action 'self'", // Chỉ cho phép form submit về same origin
            "frame-ancestors 'self'", // Tương tự X-Frame-Options
            "upgrade-insecure-requests", // Tự động upgrade HTTP requests thành HTTPS
        ];

        // Merge với config nếu có
        if (!empty($cspConfig)) {
            $directives = array_merge($directives, $cspConfig);
        }

        return implode('; ', $directives);
    }
}
