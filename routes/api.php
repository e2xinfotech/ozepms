<?php

use App\Http\Controllers\Api\V1\AvailabilityController;
use App\Http\Controllers\Api\V1\PropertyController;
use App\Http\Controllers\Api\V1\ReservationsController;
use Illuminate\Support\Facades\Route;

/*
| /api/v1 — versioned JSON API for the hotel's own website, mobile apps and partners.
| Authentication: property API key ("Authorization: Bearer ozk_…"), created in Settings.
| Every endpoint uses the same engines as the PMS and the booking engine. See docs/07-api.md.
*/
Route::get('/property', PropertyController::class)->middleware('api.key:availability')->name('property');
Route::get('/availability', AvailabilityController::class)->middleware('api.key:availability')->name('availability');
Route::post('/reservations', [ReservationsController::class, 'store'])->middleware('api.key:reservations.create')->name('reservations.store');
Route::get('/reservations/{ref}', [ReservationsController::class, 'show'])->middleware('api.key:reservations.read')->name('reservations.show');
