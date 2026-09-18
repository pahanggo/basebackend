<?php

use Gis\Http\Controllers\EditorController;
use Illuminate\Support\Facades\Route;

/*
 * Registered by GisServiceProvider under the admin prefix, the admin guard and
 * the module permission. Do not add middleware here — the group owns it.
 */

Route::get('/', [EditorController::class, 'index'])->name('editor');
