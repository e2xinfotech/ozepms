<?php

use App\Http\Controllers\WebApi\Property\AmenitiesController;
use Illuminate\Support\Facades\Route;

// --- Module: amenities (Phase 2) --------------------------------------------
Route::middleware('can.do:rooms.update')->group(function () {
    Route::post('/amenities', [AmenitiesController::class, 'store'])->name('amenities.store');
    Route::put('/amenities/{amenity}', [AmenitiesController::class, 'update'])->name('amenities.update');
    Route::post('/amenities/{amenity}/facility', [AmenitiesController::class, 'facility'])->name('amenities.facility');
});
// --- End module: amenities -----------------------------------------------------
