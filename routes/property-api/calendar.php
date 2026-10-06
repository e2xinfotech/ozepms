<?php

use App\Http\Controllers\WebApi\Property\CalendarController;
use Illuminate\Support\Facades\Route;

// --- Module: calendar (Phase 3) ----------------------------------------------
Route::get('/calendar/grid', [CalendarController::class, 'grid'])->middleware('can.do:calendar.view')->name('calendar.grid');
Route::get('/calendar/year', [CalendarController::class, 'year'])->middleware('can.do:calendar.view')->name('calendar.year');
Route::middleware(['can.do:calendar.update', 'throttle:120,1'])->group(function () {
    Route::put('/calendar/cell', [CalendarController::class, 'cell'])->name('calendar.cell');
    Route::put('/calendar/range', [CalendarController::class, 'range'])->name('calendar.range');
    Route::post('/calendar/bulk', [CalendarController::class, 'bulk'])->name('calendar.bulk');
    Route::post('/calendar/copy/preview', [CalendarController::class, 'copyPreview'])->name('calendar.copy.preview');
    Route::post('/calendar/copy', [CalendarController::class, 'copy'])->name('calendar.copy');
});
// --- End module: calendar ------------------------------------------------------
