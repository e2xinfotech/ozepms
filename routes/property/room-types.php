<?php

use App\Http\Controllers\Web\Property\RoomTypesController;
use Illuminate\Support\Facades\Route;

// --- Module: room-types (Phase 2) -------------------------------------------
Route::get('/room-types', [RoomTypesController::class, 'index'])->middleware('can.do:rooms.view')->name('room-types');
Route::get('/room-types/new', [RoomTypesController::class, 'create'])->middleware('can.do:rooms.create')->name('room-types.create');
Route::get('/room-types/{roomType}/edit', [RoomTypesController::class, 'edit'])->middleware('can.do:rooms.update')->name('room-types.edit');
// --- End module: room-types ---------------------------------------------------
