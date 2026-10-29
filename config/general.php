<?php

/**
 * © 2026 VISUAL WEBER COMPANY LIMITED. All rights reserved.
 * Proprietary software developed and distributed by Visual Weber.
 * Use is permitted only under a valid license agreement.
 *
 * © 2026 CÔNG TY TNHH VISUAL WEBER. Bảo lưu mọi quyền.
 * Phần mềm độc quyền của Visual Weber, chỉ được sử dụng theo Hợp đồng cấp phép.
 */


return [
    'supported' => [
    ],
    /*
    |--------------------------------------------------------------------------
    | Birthday Event
    |--------------------------------------------------------------------------
    |
    | Here you may specify the activations model used and the time (in seconds)
    | which activation codes expire. By default, activation codes expire after
    | three days. The lottery is used for garbage collection, expired
    | codes will be cleared automatically based on the provided odds.
    |
    */

    'member-birthday-reminder-before-days' => 90,

    // Biến môi trường mà helpers đọc lúc chạy, qua apps_env(). Chụp ở đây vì khi đã
    // `php artisan config:cache`, Laravel không nạp .env nữa và env() gọi lúc chạy trả null.
    // Không đặt default ở đây: default nằm ở nơi gọi apps_env(), giữ đúng hành vi cũ.
    'env' => [
        'APP_LOG_LEVEL' => env('APP_LOG_LEVEL'),
        'APP_URL' => env('APP_URL'),
        'VTIGER_REDIRECT' => env('VTIGER_REDIRECT'),
        'TELEGRAM_NOTIFY_ENABLE' => env('TELEGRAM_NOTIFY_ENABLE'),
        'TELEGRAM_CHAT_ID' => env('TELEGRAM_CHAT_ID'),
        'TELEGRAM_MESSAGE_THREAD_ID' => env('TELEGRAM_MESSAGE_THREAD_ID'),
        'TELEGRAM_BOT_TOKEN' => env('TELEGRAM_BOT_TOKEN'),
    ],
];
