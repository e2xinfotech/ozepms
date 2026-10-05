<?php

use App\Http\Controllers\Web\Property\RoomsController;
use Illuminate\Support\Facades\Route;

// --- Module: rooms (Phase 2) ------------------------------------------------
Route::get('/rooms', [RoomsController::class, 'index'])->middleware('can.do:rooms.view')->name('rooms');
// --- End module: rooms ---------------------------------------------------------
