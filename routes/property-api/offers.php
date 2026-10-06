<?php

use App\Http\Controllers\WebApi\Property\OffersController;
use Illuminate\Support\Facades\Route;

// --- Module: offers & promotions (Phase 5) ---------------------------------------
Route::middleware('can.do:offers.manage')->group(function () {
    Route::get('/offers/export', [OffersController::class, 'export'])->name('offers.export');
    Route::get('/offers/{offer}', [OffersController::class, 'show'])->name('offers.show');
    Route::post('/offers', [OffersController::class, 'store'])->name('offers.store');
    Route::put('/offers/{offer}', [OffersController::class, 'update'])->name('offers.update');
    Route::post('/offers/{offer}/status', [OffersController::class, 'status'])->name('offers.status');
    Route::post('/offers/{offer}/copy', [OffersController::class, 'copy'])->name('offers.copy');
    Route::delete('/offers/{offer}', [OffersController::class, 'destroy'])->name('offers.destroy');
    Route::post('/offers/{offer}/image', [OffersController::class, 'image'])->name('offers.image');
    Route::delete('/offers/{offer}/image', [OffersController::class, 'removeImage'])->name('offers.image.remove');
});
