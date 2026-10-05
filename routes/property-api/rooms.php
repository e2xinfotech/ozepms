<?php

use App\Http\Controllers\WebApi\Property\RoomsController;
use Illuminate\Support\Facades\Route;

// --- Module: rooms (Phase 2) ------------------------------------------------
Route::get('/rooms/{room}', [RoomsController::class, 'show'])->middleware('can.do:rooms.view')->name('rooms.show');
Route::middleware('can.do:rooms.create')->group(function () {
    Route::post('/rooms', [RoomsController::class, 'store'])->name('rooms.store');
    Route::post('/rooms/bulk', [RoomsController::class, 'bulkStore'])->name('rooms.bulk');
});
Route::middleware('can.do:rooms.update')->group(function () {
    Route::put('/rooms/{room}', [RoomsController::class, 'update'])->name('rooms.update');
    Route::post('/rooms/{room}/status', [RoomsController::class, 'status'])->name('rooms.status');
    Route::post('/rooms/{room}/blocks', [RoomsController::class, 'block'])->name('rooms.blocks.store');
    Route::delete('/rooms/{room}/blocks/{block}', [RoomsController::class, 'release'])->whereNumber('block')->name('rooms.blocks.release');
});
Route::post('/rooms/{room}/housekeeping', [RoomsController::class, 'housekeeping'])
    ->middleware('can.do:housekeeping.update|rooms.update')->name('rooms.housekeeping');
// --- End module: rooms ---------------------------------------------------------
