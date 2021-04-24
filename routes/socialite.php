<?php

use Illuminate\Support\Facades\Route;

Route::middleware('admin')->get('/unlink', 'BaseController@unlink')->name('socialite.unlink');

if(config('auth.socialite.providers.google')) {
    Route::group(['prefix' => 'google'], function(){
        Route::get('/redirect', 'GoogleController@redirectToProvider')->name('socialite.google');
        Route::get('/callback', 'GoogleController@handleProviderCallback');
    });
}

if(config('auth.socialite.providers.github')) {
    Route::group(['prefix' => 'github'], function(){
        Route::get('/redirect', 'GithubController@redirectToProvider')->name('socialite.github');
        Route::get('/callback', 'GithubController@handleProviderCallback');
    });
}