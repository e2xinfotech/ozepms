<?php

use App\Http\Controllers\WebApi\Property\FolioController;
use App\Http\Controllers\WebApi\Property\InvoicesController;
use App\Http\Controllers\WebApi\Property\PaymentsController;
use App\Http\Controllers\WebApi\Property\ServicesController;
use Illuminate\Support\Facades\Route;

// --- Module: billing — folios, payments, invoices, services (Phase 4) ---------
Route::name('billing.')->group(function () {
    Route::middleware('can.do:folio.view')->group(function () {
        Route::get('/billing/options', [FolioController::class, 'options'])->name('options');
        Route::get('/reservations/{reservation}/folio', [FolioController::class, 'show'])->name('folio.show');
        Route::get('/reservations/{reservation}/payments', [PaymentsController::class, 'index'])->name('payments.index');
        Route::get('/reservations/{reservation}/invoices', [InvoicesController::class, 'index'])->name('invoices.index');
    });
    Route::middleware(['can.do:folio.post', 'throttle:60,1'])->group(function () {
        Route::post('/reservations/{reservation}/folio/charges', [FolioController::class, 'charge'])->name('folio.charges.store');
        Route::post('/reservations/{reservation}/folio/bill', [FolioController::class, 'bill'])->name('folio.bill.store');
        Route::post('/reservations/{reservation}/folio/lines/{line}/void', [FolioController::class, 'void'])->name('folio.lines.void');
    });
    Route::middleware(['can.do:payments.manage', 'throttle:60,1'])->group(function () {
        Route::post('/reservations/{reservation}/payments', [PaymentsController::class, 'store'])->name('payments.store');
        Route::post('/reservations/{reservation}/payments/online', [PaymentsController::class, 'online'])->name('payments.online');
        Route::post('/reservations/{reservation}/payments/{payment}/verify', [PaymentsController::class, 'verify'])->name('payments.verify');
        Route::post('/reservations/{reservation}/payments/{payment}/refund', [PaymentsController::class, 'refund'])->name('payments.refund');
    });
    Route::middleware(['can.do:invoices.manage', 'throttle:30,1'])->group(function () {
        Route::post('/reservations/{reservation}/invoices', [InvoicesController::class, 'store'])->name('invoices.store');
        Route::post('/invoices/{invoice}/email', [InvoicesController::class, 'email'])->name('invoices.email');
        Route::post('/invoices/{invoice}/cancel', [InvoicesController::class, 'cancel'])->name('invoices.cancel');
    });
    Route::middleware('can.do:services.manage')->group(function () {
        Route::get('/services', [ServicesController::class, 'index'])->name('services.index');
        Route::get('/services/{service}', [ServicesController::class, 'show'])->name('services.show');
        Route::post('/services', [ServicesController::class, 'store'])->name('services.store');
        Route::put('/services/{service}', [ServicesController::class, 'update'])->name('services.update');
        Route::post('/services/{service}/status', [ServicesController::class, 'status'])->name('services.status');
    });
});
// --- End module: billing ------------------------------------------------------------
