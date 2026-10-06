<?php

use App\Http\Controllers\Booking\BookingApiController;
use App\Http\Controllers\Booking\BookingPageController;
use Illuminate\Support\Facades\Route;

/*
| Public booking engine, one per property: /book/{property code}. No login and no PMS session —
| separate from the PMS screens. The property code is the only input; everything else is decided
| on the server (BookingEngineService). Rate limited per IP.
*/
Route::get('/{code}', [BookingPageController::class, 'search'])->name('search');
Route::get('/{code}/checkout', [BookingPageController::class, 'checkout'])->name('checkout');
Route::get('/{code}/booking/{reservation}', [BookingPageController::class, 'confirmation'])->middleware('signed')->name('confirmation');

Route::middleware('throttle:booking-search')->group(function () {
    Route::get('/{code}/api/search', [BookingApiController::class, 'search'])->name('api.search');
});
Route::middleware('throttle:booking-book')->group(function () {
    Route::post('/{code}/api/book', [BookingApiController::class, 'book'])->name('api.book');
    Route::post('/{code}/api/payments/{payment}/checkout', [BookingApiController::class, 'checkout'])->name('api.checkout');
    Route::post('/{code}/api/payments/{payment}/verify', [BookingApiController::class, 'verify'])->name('api.verify');
});
