<?php

use App\Http\Controllers\Web\Property\AmenitiesController;
use Illuminate\Support\Facades\Route;

// --- Module: amenities (Phase 2) --------------------------------------------
Route::get('/amenities', [AmenitiesController::class, 'index'])->middleware('can.do:rooms.view')->name('amenities');
// --- End module: amenities -----------------------------------------------------
