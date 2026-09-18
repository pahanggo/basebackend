<?php

use Gis\Http\Controllers\Api\CommandController;
use Gis\Http\Controllers\Api\CommandReplayController;
use Gis\Http\Controllers\Api\FeatureReadController;
use Gis\Http\Controllers\Api\HealthController;
use Illuminate\Support\Facades\Route;

/*
 * Registered by GisServiceProvider at /api/geo under the admin guard, session
 * cookie and CSRF. Geometry ops, the layer library and the query endpoint
 * arrive with the sessions that need them.
 */

Route::get('health', HealthController::class)->name('health');
Route::get('layers/{layer}/features', FeatureReadController::class)->name('layers.features');

Route::post('maps/{map}/commands', CommandController::class)
    ->middleware('throttle:gis-writes')
    ->name('maps.commands');

Route::get('maps/{map}/commands', CommandReplayController::class)->name('maps.commands.replay');
