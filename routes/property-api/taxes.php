<?php

use App\Http\Controllers\WebApi\Property\TaxesController;
use Illuminate\Support\Facades\Route;

// --- Module: taxes (Phase 2) ------------------------------------------------
Route::middleware('can.do:taxes.manage')->group(function () {
    Route::post('/taxes/preview', [TaxesController::class, 'preview'])->name('taxes.preview');
    Route::post('/taxes/copy-templates', [TaxesController::class, 'copyTemplates'])->name('taxes.copy-templates');
    Route::get('/taxes/{tax}', [TaxesController::class, 'show'])->name('taxes.show');
    Route::post('/taxes', [TaxesController::class, 'store'])->name('taxes.store');
    Route::put('/taxes/{tax}', [TaxesController::class, 'update'])->name('taxes.update');
    Route::post('/taxes/{tax}/status', [TaxesController::class, 'status'])->name('taxes.status');
    Route::post('/taxes/{tax}/default', [TaxesController::class, 'default'])->name('taxes.default');
    Route::delete('/taxes/{tax}', [TaxesController::class, 'destroy'])->name('taxes.destroy');
});
// --- End module: taxes ---------------------------------------------------------
