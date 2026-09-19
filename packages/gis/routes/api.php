<?php

use Gis\Http\Controllers\Api\CommandController;
use Gis\Http\Controllers\Api\CommandReplayController;
use Gis\Http\Controllers\Api\FeatureReadController;
use Gis\Http\Controllers\Api\LayerLibraryController;
use Gis\Http\Controllers\Api\LayerValuesController;
use Gis\Http\Controllers\Api\MapController;
use Gis\Http\Controllers\Api\HealthController;
use Illuminate\Support\Facades\Route;

/*
 * Registered by GisServiceProvider at /api/geo under the admin guard, session
 * cookie and CSRF. Geometry ops, the layer library and the query endpoint
 * arrive with the sessions that need them.
 */

Route::get('health', HealthController::class)->name('health');

Route::get('maps', [MapController::class, 'index'])->name('maps.index');
Route::post('maps', [MapController::class, 'store'])->middleware('throttle:gis-writes')->name('maps.store');
Route::get('maps/{map}', [MapController::class, 'show'])->name('maps.show');
Route::patch('maps/{map}', [MapController::class, 'update'])->middleware('throttle:gis-writes')->name('maps.update');
Route::delete('maps/{map}', [MapController::class, 'destroy'])->middleware('throttle:gis-writes')->name('maps.destroy');

// Where the map opens. Separate from the versioned update because panning is
// not an edit: see MapController::view().
Route::put('maps/{map}/view', [MapController::class, 'view'])->middleware('throttle:gis-writes')->name('maps.view');

Route::get('layers', LayerLibraryController::class)->name('layers.index');
Route::get('layers/{layer}/features', FeatureReadController::class)->name('layers.features');

// The distinct values of one attribute, for splitting a layer into sublayers.
Route::get('layers/{layer}/values', LayerValuesController::class)->name('layers.values');

Route::post('maps/{map}/commands', CommandController::class)
    ->middleware('throttle:gis-writes')
    ->name('maps.commands');

Route::get('maps/{map}/commands', CommandReplayController::class)->name('maps.commands.replay');
