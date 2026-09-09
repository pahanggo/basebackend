<?php

// --------------------------
// Custom Backpack Routes
// --------------------------
// This route file is loaded automatically by Backpack\Base.
// Routes you generate using Backpack\Generators will be placed here.

use Illuminate\Support\Facades\Route;

// Protect your routes with 'can:Manage System' middleware!!

Route::group([
    'prefix'     => config('backpack.base.route_prefix', 'admin'),
    'middleware' => array_merge(
        (array) config('backpack.base.web_middleware', 'web'),
        (array) config('backpack.base.middleware_key', 'admin')
    ),
    'namespace'  => 'App\Http\Controllers\Admin',
], function () { // custom admin routes
    // Cached static map images for the latlng_map column
    Route::get('static-map', 'StaticMapController@show')->name('static-map');

    if (config('app.kitchensink')) {
        Route::crud('kitchensink', 'KitchenSinkCrudController');
    }
}); // this should be the absolute last line of this file
