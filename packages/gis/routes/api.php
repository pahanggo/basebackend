<?php

use Gis\Http\Controllers\Api\HealthController;
use Illuminate\Support\Facades\Route;

/*
 * Registered by GisServiceProvider at /api/geo under the admin guard, session
 * cookie and CSRF. Feature reads, the command endpoint and geometry ops arrive
 * in S3 and later; only the health check is live.
 */

Route::get('health', HealthController::class)->name('health');
