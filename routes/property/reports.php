<?php

use App\Http\Controllers\Web\Property\ReportsController;
use Illuminate\Support\Facades\Route;

// --- Module: reports (Phase 7) -----------------------------------------------------
Route::middleware(['can.do:reports.view', 'plan.feature:reports'])->group(function () {
    Route::get('/reports', [ReportsController::class, 'index'])->name('reports');
    Route::get('/reports/{report}', [ReportsController::class, 'show'])->where('report', '[a-z-]{3,30}')->name('reports.show');
});
