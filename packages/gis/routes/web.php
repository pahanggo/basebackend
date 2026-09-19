<?php

use Gis\Http\Controllers\EditorController;
use Illuminate\Support\Facades\Route;

/*
 * Registered by GisServiceProvider under the admin prefix, the admin guard and
 * the module permission. Do not add middleware here — the group owns it.
 */

Route::get('/', [EditorController::class, 'index'])->name('editor');

/*
 * The same editor, opened on a named map.
 *
 * A slug rather than an id because this is the URL people paste to each other,
 * and `/app/gis/pahang` says what it opens where `/app/gis/12` does not. The
 * bare route above stays: it opens the map the user last touched, which is
 * what returning to the editor should do.
 *
 * **The parameter is `slug`, not `map`, and it is a string.** Naming it `map`
 * would invite Laravel's implicit route-model binding, and the only way to
 * make that resolve by slug is `Map::getRouteKeyName()` — which is global, so
 * it would also rebind every `{map}` on the id-based JSON API and 404 the
 * whole client. The editor looks the map up itself, scoped to what this user
 * may open.
 *
 * Constrained, so it cannot swallow a path segment that should 404.
 */
Route::get('/{slug}', [EditorController::class, 'index'])
    ->where('slug', '[a-z0-9-]+')
    ->name('editor.map');
