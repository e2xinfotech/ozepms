<?php

use App\Http\Controllers\WebApi\Property\HousekeepingController;
use Illuminate\Support\Facades\Route;

// --- Module: housekeeping -----------------------------------------------------------
Route::middleware('can.do:housekeeping.manage')->group(function () {
    Route::post('/housekeeping/staff', [HousekeepingController::class, 'storeStaff'])->name('housekeeping.staff.store');
    Route::put('/housekeeping/staff/{staff}', [HousekeepingController::class, 'updateStaff'])->name('housekeeping.staff.update');
    Route::delete('/housekeeping/staff/{staff}', [HousekeepingController::class, 'destroyStaff'])->name('housekeeping.staff.destroy');
    Route::post('/housekeeping/assign', [HousekeepingController::class, 'assign'])->name('housekeeping.assign');
});
// --- End module: housekeeping ---------------------------------------------------------
