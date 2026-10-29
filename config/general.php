<?php


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
    ],
];
