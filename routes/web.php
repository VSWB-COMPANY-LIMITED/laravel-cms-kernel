<?php

Route::group([
    'namespace' => 'Dev\Kernel\Http\Controllers',
    'middleware' => ['web', 'core'],
    'as' => 'addons.kernel.'
], function () {
    // Route::group(['prefix' => BaseHelper::getAdminPrefix(), 'middleware' => 'auth'], function () {
    // });
});
