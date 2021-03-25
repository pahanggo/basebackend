<?php

/*
|--------------------------------------------------------------------------
| Backpack\PermissionManager Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of the routes that are
| handled by the Backpack\PermissionManager package.
|
*/

use Illuminate\Support\Facades\Route;

Route::group([
    'namespace'  => 'App\Http\Controllers\Auth',
    'prefix'     => config('backpack.base.route_prefix', 'admin'),
    'middleware' => ['web', backpack_middleware(), 'can:Manage Users'],
], function () {
    Route::crud('user', 'UserCrudController');
});

Route::group([
    'namespace'  => 'App\Http\Controllers\Auth',
    'prefix'     => config('backpack.base.route_prefix', 'admin'),
    'middleware' => ['web', backpack_middleware(), 'can:Assume Users'],
], function () {
    Route::get('user/{id}/assume', 'UserCrudController@assume')->name('users.assume');
});

Route::group([
    'namespace'  => 'App\Http\Controllers\Auth',
    'prefix'     => config('backpack.base.route_prefix', 'admin'),
    'middleware' => ['web', backpack_middleware()],
], function () {
    Route::get('user/resume', 'UserCrudController@resume')->name('users.resume');
    Route::post('user/update-avatar', 'UserCrudController@updateAvatar')->name('user.update-avatar');
});

Route::group([
    'namespace'  => 'App\Http\Controllers\Auth',
    'prefix'     => config('backpack.base.route_prefix', 'admin'),
    'middleware' => ['web', backpack_middleware(), 'can:Manage Roles and Permissions'],
], function () {
    Route::crud('permission', 'PermissionCrudController');
    Route::crud('role', 'RoleCrudController');
});
