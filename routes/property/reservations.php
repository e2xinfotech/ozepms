<?php

use App\Http\Controllers\Web\Property\GuestsController;
use App\Http\Controllers\Web\Property\ReservationsController;
use Illuminate\Support\Facades\Route;

// --- Module: reservations, guests, front desk (Phase 4) ----------------------
Route::get('/reservations', [ReservationsController::class, 'index'])->middleware('can.do:reservations.view')->name('reservations');
Route::get('/reservations/new', [ReservationsController::class, 'create'])->middleware('can.do:reservations.create')->name('reservations.create');
Route::get('/reservations/{reservation}', [ReservationsController::class, 'show'])->middleware('can.do:reservations.view')->name('reservations.show');
Route::get('/reservations/{reservation}/edit', [ReservationsController::class, 'edit'])->middleware('can.do:reservations.update')->name('reservations.edit');
Route::get('/front-desk', [ReservationsController::class, 'frontDesk'])->middleware('can.do:reservations.view')->name('front-desk');
Route::get('/guests', [GuestsController::class, 'index'])->middleware('can.do:guests.view')->name('guests');
// --- End module: reservations ---------------------------------------------------
