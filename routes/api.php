<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::group([
    'namespace'  => 'App\Http\Controllers\Api',
    'middleware' => 'api',
    'as'         => 'api.',
], function(){
    Route::group([
        'prefix' => 'auth/',
        'as'     => 'auth.'
    ], function(){
        Route::post('login', 'AuthController@login');
        Route::post('register', 'AuthController@register');
        Route::post('forgot-password', 'AuthController@forgotPassword');
    });

    Route::group([
        'middleware' => 'auth:sanctum',
        'prefix'     => 'user/',
        'as'         => 'user.'
    ], function(){
        Route::get('/', 'UserController@profile');
        Route::post('/', 'UserController@updateProfile');
        Route::post('/avatar', 'UserController@updateAvatar');
        Route::post('/password', 'UserController@changePassword');
    });
});
