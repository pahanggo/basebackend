<?php

use Gis\Http\Controllers\Api\CommandController;
use Gis\Http\Controllers\Api\CommandReplayController;
use Gis\Http\Controllers\Api\FeatureReadController;
use Gis\Http\Controllers\Api\GeometryOpsController;
use Gis\Http\Controllers\Api\ImageUploadController;
use Gis\Http\Controllers\Api\LayerLibraryController;
use Gis\Http\Controllers\Api\LayerValuesController;
use Gis\Http\Controllers\Api\QueryController;
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

// Spatial and attribute query. A POST because the body carries geometry, not
// because it writes anything — it returns ids, a count or features.
Route::post('layers/{layer}/query', QueryController::class)
    ->middleware('throttle:gis-writes')
    ->name('layers.query');

Route::post('maps/{map}/commands', CommandController::class)
    ->middleware('throttle:gis-writes')
    ->name('maps.commands');

Route::get('maps/{map}/commands', CommandReplayController::class)->name('maps.commands.replay');

// The one multipart write in v1. Separate from the command endpoint on purpose:
// that one stays JSON so it can stay atomic, idempotent and replayable.
// Constructive geometry above the client's vertex limit. Not a write path: it
// returns geometry and creates nothing, which is what keeps the command
// endpoint the only way anything is stored.
Route::post('geometry/ops', GeometryOpsController::class)
    ->middleware('throttle:gis-writes')
    ->name('geometry.ops');

Route::post('images', ImageUploadController::class)
    ->middleware('throttle:gis-images')
    ->name('images.store');
