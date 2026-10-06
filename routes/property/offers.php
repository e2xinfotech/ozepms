<?php

use App\Http\Controllers\Web\Property\OffersController;
use Illuminate\Support\Facades\Route;

// --- Module: offers & promotions (Phase 5) ---------------------------------------
Route::middleware('can.do:offers.manage')->group(function () {
    Route::get('/offers', [OffersController::class, 'index'])->name('offers');
    Route::get('/offers/new', [OffersController::class, 'create'])->name('offers.create');
    Route::get('/offers/{offer}/edit', [OffersController::class, 'edit'])->name('offers.edit');
});
