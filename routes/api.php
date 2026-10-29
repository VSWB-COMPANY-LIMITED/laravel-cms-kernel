<?php

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;

#region General routes, customize routes. To avoice modification core platform
Route::group([
    'middleware' => [
        'app.middleware.empty-to-null',
        'api'
    ],
    'prefix' => 'api/v1',
    // NOTE: Changed namespace from Platform\Api to Platform\Kernel to match package structure
    'namespace' => 'Platform\Kernel\Http\Controllers\API\v1',
    'as' => 'kernel.api.v1.'
], function () {
    #region for laravel cms platform only: force update url cho toàn bộ hệ thống mã nguồn cms sau khi triển khai
    Route::match(['GET', 'POST'], 'products/check-update', function (): JsonResponse {
        return response()->json([
            'error' => false,
            'data' => null,
            'message' => 'The system is already running the latest version. For further assistance, please contact us at toan@visualweber.com or call +84 943 999 819',
        ]);
    })->name('license.check-update');
    
    Route::get('license/verify', function (): JsonResponse {
        return response()->json([
            'error' => false,
            'data' => null,
            'message' => 'The system is already running the latest version. For further assistance, please contact us at toan@visualweber.com or call +84 943 999 819',
        ]);
    })->name('license.verify');

    Route::get('license/check', function (): JsonResponse {
        return response()->json([
            'error' => false,
            'data' => null,
            'message' => 'The system is already running the latest version. For further assistance, please contact us at toan@visualweber.com or call +84 943 999 819',
        ]);
    })->name('license.check');
    #endregion

    // NOTE: ProfileController doesn't exist in this package
    // Uncomment and implement if needed, or remove this route group
    // Route::group(['middleware' => ['auth:sanctum']], function () {
    //     Route::delete(
    //         'delete-account',
    //         [
    //             'as' => 'platform.package.api.delete-account',
    //             'uses' => 'ProfileController@deleteAccount'
    //         ]
    //     );
    // });
});
#endregion General routes, customize routes. To avoice modification core platform
