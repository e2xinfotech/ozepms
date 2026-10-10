<?php

use App\Http\Controllers\Web\Property\BillingController;
use Illuminate\Support\Facades\Route;

// --- Module: billing (Phase 4) -------------------------------------------------
Route::get('/services', [BillingController::class, 'services'])->middleware('can.do:services.manage')->name('services');
Route::get('/invoices/{invoice}', [BillingController::class, 'invoice'])->middleware('can.do:folio.view')->name('invoices.show');
Route::get('/invoices/{invoice}/pdf', [BillingController::class, 'invoicePdf'])->middleware(['can.do:folio.view', 'throttle:30,1'])->name('invoices.pdf');
Route::get('/reservations/{reservation}/folio', [BillingController::class, 'folio'])->middleware('can.do:folio.view')->name('reservations.folio');
// --- End module: billing ------------------------------------------------------------
