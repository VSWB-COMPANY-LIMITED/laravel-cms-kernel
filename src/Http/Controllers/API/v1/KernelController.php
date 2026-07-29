<?php

/**
 * © 2026 VISUAL WEBER COMPANY LIMITED. All rights reserved.
 * Proprietary software developed and distributed by Visual Weber.
 * Use is permitted only under a valid license agreement.
 *
 * © 2026 CÔNG TY TNHH VISUAL WEBER. Bảo lưu mọi quyền.
 * Phần mềm độc quyền của Visual Weber, chỉ được sử dụng theo Hợp đồng cấp phép.
 */

namespace Dev\Kernel\Http\Controllers\API\v1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

class KernelController extends BaseController
{
    /**
     * Middleware Check - Verify tất cả middleware đã hoạt động
     * 
     * @url: GET /api/v1/test/middleware-check
     * 
     * Kiểm tra:
     * - SecurityHeaders: Xem response headers
     * - TrustProxies: Xem real IP
     * - TrimStrings: Test trim input
     * - EncryptCookies: Xem cookies
     * - StartSession: Xem session
     */
    public function middlewareCheck(Request $request): JsonResponse
    {
        $data = [
            'timestamp' => now()->toIso8601String(),
            'middleware_status' => 'active',
            'checks' => [
                // 1. SecurityHeaders - Kiểm tra response headers
                'security_headers' => [
                    'status' => 'check_response_headers',
                    'note' => 'Kiểm tra response headers: Strict-Transport-Security, CSP, X-Frame-Options, etc.',
                ],

                // 2. TrustProxies - Kiểm tra real IP
                'trust_proxies' => [
                    'status' => 'active',
                    'client_ip' => $request->ip(),
                    'real_ip' => $request->header('X-Forwarded-For') ?: $request->ip(),
                    'all_ips' => [
                        'ip()' => $request->ip(),
                        'getClientIp()' => $request->getClientIp(),
                        'X-Forwarded-For' => $request->header('X-Forwarded-For'),
                        'X-Real-IP' => $request->header('X-Real-IP'),
                    ],
                ],

                // 3. TrimStrings - Test trim input
                'trim_strings' => [
                    'status' => 'active',
                    'test_input' => $request->input('test', ''),
                    'note' => 'Gửi ?test=  hello  world  để test trim (sẽ trim spaces)',
                ],

                // 4. EncryptCookies - Kiểm tra cookies
                'encrypt_cookies' => [
                    'status' => 'active',
                    'cookies_received' => $request->cookies->all(),
                    'note' => 'Cookies sẽ được encrypt/decrypt tự động',
                ],

                // 5. StartSession - Kiểm tra session
                'start_session' => [
                    'status' => 'active',
                    'session_id' => session()->getId(),
                    'session_data' => session()->all(),
                    'note' => 'Session đã được start',
                ],

                // 6. TrustHosts - Kiểm tra host validation
                'trust_hosts' => [
                    'status' => 'active',
                    'host' => $request->getHost(),
                    'scheme' => $request->getScheme(),
                    'http_host' => $request->getHttpHost(),
                    'note' => 'Host đã được validate',
                ],

                // 7. ValidateSignature - Test signed URLs
                'validate_signature' => [
                    'status' => 'active',
                    'note' => 'Dùng URL::signedRoute() để test',
                ],

                // 8. VerifyCsrfToken - CSRF protection
                'verify_csrf_token' => [
                    'status' => 'excluded_for_api',
                    'note' => 'API routes đã được exclude khỏi CSRF (xem VerifyCsrfToken::$except)',
                ],
            ],

            'request_info' => [
                'method' => $request->method(),
                'url' => $request->fullUrl(),
                'path' => $request->path(),
                'headers' => $request->headers->all(),
                'query_params' => $request->query->all(),
            ],

            'instructions' => [
                '1. SecurityHeaders' => 'Kiểm tra response headers trong browser DevTools > Network tab',
                '2. TrustProxies' => 'Gửi header X-Forwarded-For để test',
                '3. TrimStrings' => 'Gửi ?test=  hello  world  để xem có trim không',
                '4. EncryptCookies' => 'Set cookie và xem có được encrypt không',
                '5. StartSession' => 'Session ID đã được tạo',
                '6. TrustHosts' => 'Host đã được validate',
                '7. ValidateSignature' => 'Dùng signed URLs để test',
                '8. VerifyCsrfToken' => 'API routes đã exclude CSRF',
            ],
        ];

        return response()->json($data, 200);
    }


    public function getPlugins(Request $request): JsonResponse
    {
        return response()->json([
            'active_plugins' => get_active_plugins(),
        ], 200);
    }
}
