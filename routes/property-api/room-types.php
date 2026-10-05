<?php

use App\Http\Controllers\WebApi\Property\RoomTypesController;
use Illuminate\Support\Facades\Route;

// --- Module: room-types (Phase 2) -------------------------------------------
Route::get('/room-types/{roomType}', [RoomTypesController::class, 'show'])->middleware('can.do:rooms.view')->name('room-types.show');
Route::post('/room-types', [RoomTypesController::class, 'store'])->middleware('can.do:rooms.create')->name('room-types.store');
Route::middleware('can.do:rooms.update')->group(function () {
    Route::put('/room-types/{roomType}', [RoomTypesController::class, 'update'])->name('room-types.update');
    Route::post('/room-types/{roomType}/status', [RoomTypesController::class, 'status'])->name('room-types.status');
    Route::put('/room-types/{roomType}/default-rate-plan', [RoomTypesController::class, 'defaultRatePlan'])->name('room-types.default-rate-plan');
    Route::post('/room-types/{roomType}/images', [RoomTypesController::class, 'storeImage'])->name('room-types.images.store');
    Route::delete('/room-types/{roomType}/images/{image}', [RoomTypesController::class, 'destroyImage'])->whereNumber('image')->name('room-types.images.destroy');
});
// --- End module: room-types ---------------------------------------------------
