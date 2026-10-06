<?php

use App\Http\Controllers\Web\Property\CalendarController;
use Illuminate\Support\Facades\Route;

// --- Module: calendar (Phase 3) ----------------------------------------------
Route::get('/calendar', [CalendarController::class, 'index'])->middleware('can.do:calendar.view')->name('calendar');
// --- End module: calendar ------------------------------------------------------
