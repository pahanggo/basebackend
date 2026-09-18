<?php

use Gis\Http\Controllers\Api\FeatureReadController;
use Gis\Http\Controllers\Api\HealthController;
use Illuminate\Support\Facades\Route;

/*
 * Registered by GisServiceProvider at /api/geo under the admin guard, session
 * cookie and CSRF. The command endpoint and geometry ops arrive in S4 and
 * later.
 */

Route::get('health', HealthController::class)->name('health');
Route::get('layers/{layer}/features', FeatureReadController::class)->name('layers.features');
