<?php

use App\Http\Controllers\WebApi\Property\ReportsController;
use Illuminate\Support\Facades\Route;

// --- Module: reports (Phase 7) -----------------------------------------------------
Route::middleware(['can.do:reports.view', 'plan.feature:reports'])->group(function () {
    Route::get('/reports/{report}', [ReportsController::class, 'show'])->where('report', '[a-z-]{3,30}')->name('reports.data');
    Route::get('/reports/{report}/export', [ReportsController::class, 'export'])->where('report', '[a-z-]{3,30}')->name('reports.export');
});
