<?php

use App\Http\Controllers\Web\Property\TaxesController;
use Illuminate\Support\Facades\Route;

// --- Module: taxes (Phase 2) ------------------------------------------------
Route::get('/taxes', [TaxesController::class, 'index'])->middleware('can.do:taxes.manage')->name('taxes');
// --- End module: taxes ---------------------------------------------------------
