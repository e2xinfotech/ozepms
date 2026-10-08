<?php

use App\Http\Controllers\WebApi\Property\GuestsController;
use App\Http\Controllers\WebApi\Property\ReservationsController;
use App\Http\Controllers\WebApi\Property\SearchController;
use Illuminate\Support\Facades\Route;

// --- Module: reservations, guests, front desk (Phase 4) ----------------------
Route::get('/search', SearchController::class)->middleware(['can.do:property.view', 'throttle:120,1'])->name('search');

Route::middleware('can.do:reservations.create')->group(function () {
    Route::get('/reservations/availability', [ReservationsController::class, 'availability'])->name('reservations.availability');
    Route::post('/reservations/quote', [ReservationsController::class, 'quote'])->middleware('throttle:120,1')->name('reservations.quote');
    Route::post('/reservations', [ReservationsController::class, 'store'])->middleware('throttle:60,1')->name('reservations.store');
});
Route::middleware('can.do:reservations.view')->group(function () {
    Route::get('/reservations/export', [ReservationsController::class, 'export'])->middleware('throttle:10,1')->name('reservations.export');
    Route::get('/reservations/units', [ReservationsController::class, 'units'])->name('reservations.units');
    Route::get('/reservations/{reservation}', [ReservationsController::class, 'show'])->name('reservations.show');
    Route::get('/reservations/{reservation}/history', [ReservationsController::class, 'history'])->name('reservations.history');
    Route::post('/reservations/{reservation}/notes', [ReservationsController::class, 'note'])->name('reservations.notes.store');
});
Route::middleware('can.do:reservations.update|checkin.perform')->group(function () {
    Route::get('/reservations/{reservation}/occupants', [ReservationsController::class, 'occupants'])->name('reservations.occupants');
    Route::put('/reservations/{reservation}/occupants', [ReservationsController::class, 'saveOccupants'])->name('reservations.occupants.save');
});
Route::get('/reservations/{reservation}/guest-register', [ReservationsController::class, 'guestRegister'])->middleware('can.do:reservations.view')->name('reservations.guest-register');
Route::middleware('can.do:reservations.update')->group(function () {
    Route::put('/reservations/{reservation}', [ReservationsController::class, 'update'])->name('reservations.update');
    Route::post('/reservations/{reservation}/confirm', [ReservationsController::class, 'confirm'])->name('reservations.confirm');
    Route::post('/reservations/{reservation}/assign', [ReservationsController::class, 'assign'])->name('reservations.assign');
});
Route::middleware('can.do:reservations.cancel')->group(function () {
    Route::post('/reservations/{reservation}/cancel', [ReservationsController::class, 'cancel'])->name('reservations.cancel');
    Route::post('/reservations/{reservation}/no-show', [ReservationsController::class, 'noShow'])->name('reservations.no-show');
});
Route::post('/reservations/{reservation}/check-in', [ReservationsController::class, 'checkIn'])->middleware('can.do:checkin.perform')->name('reservations.check-in');
Route::post('/reservations/{reservation}/check-out', [ReservationsController::class, 'checkOut'])->middleware('can.do:checkout.perform')->name('reservations.check-out');

Route::middleware('can.do:guests.view')->group(function () {
    Route::get('/guests/export', [GuestsController::class, 'export'])->middleware('throttle:10,1')->name('guests.export');
    Route::get('/guests/{guest}', [GuestsController::class, 'show'])->name('guests.show');
    Route::get('/guests/{guest}/documents/{document}', [GuestsController::class, 'download'])->name('guests.documents.show');
});
Route::get('/guest-lookup', [GuestsController::class, 'lookup'])->middleware(['can.do:reservations.create', 'throttle:120,1'])->name('guests.lookup');
Route::middleware('can.do:guests.update')->group(function () {
    Route::post('/guests', [GuestsController::class, 'store'])->name('guests.store');
    Route::put('/guests/{guest}', [GuestsController::class, 'update'])->name('guests.update');
    Route::put('/guests/{guest}/tags', [GuestsController::class, 'tags'])->name('guests.tags');
    Route::post('/guests/{guest}/notes', [GuestsController::class, 'note'])->name('guests.notes.store');
    Route::post('/guests/{guest}/documents', [GuestsController::class, 'upload'])->name('guests.documents.store');
});
// --- End module: reservations ---------------------------------------------------
