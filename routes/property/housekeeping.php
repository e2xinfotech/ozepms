<?php

use App\Http\Controllers\Web\Property\HousekeepingController;
use Illuminate\Support\Facades\Route;

// --- Module: housekeeping -----------------------------------------------------------
Route::get('/housekeeping', [HousekeepingController::class, 'index'])->middleware('can.do:rooms.view')->name('housekeeping');
// --- End module: housekeeping ---------------------------------------------------------
