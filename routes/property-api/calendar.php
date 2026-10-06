<?php

use App\Http\Controllers\WebApi\Property\CalendarController;
use Illuminate\Support\Facades\Route;

// --- Module: calendar (Phase 3) ----------------------------------------------
Route::get('/calendar/grid', [CalendarController::class, 'grid'])->middleware('can.do:calendar.view')->name('calendar.grid');
Route::middleware(['can.do:calendar.update', 'throttle:120,1'])->group(function () {
    Route::put('/calendar/cell', [CalendarController::class, 'cell'])->name('calendar.cell');
    Route::put('/calendar/range', [CalendarController::class, 'range'])->name('calendar.range');
    Route::post('/calendar/bulk', [CalendarController::class, 'bulk'])->name('calendar.bulk');
});
// --- End module: calendar ------------------------------------------------------
