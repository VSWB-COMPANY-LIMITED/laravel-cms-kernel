<?php

/**
 * © 2026 VISUAL WEBER COMPANY LIMITED. All rights reserved.
 * Proprietary software developed and distributed by Visual Weber.
 * Use is permitted only under a valid license agreement.
 *
 * © 2026 CÔNG TY TNHH VISUAL WEBER. Bảo lưu mọi quyền.
 * Phần mềm độc quyền của Visual Weber, chỉ được sử dụng theo Hợp đồng cấp phép.
 */


Route::group([
    'namespace' => 'Dev\Kernel\Http\Controllers',
    'middleware' => ['web', 'core'],
    'as' => 'kernel.'
], function () {
    // Route::group(['prefix' => BaseHelper::getAdminPrefix(), 'middleware' => 'auth'], function () {
    // });
});
