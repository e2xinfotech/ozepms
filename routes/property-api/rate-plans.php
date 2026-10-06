<?php

use App\Http\Controllers\WebApi\Property\CancellationPoliciesController;
use App\Http\Controllers\WebApi\Property\MealPlansController;
use App\Http\Controllers\WebApi\Property\RatePlansController;
use Illuminate\Support\Facades\Route;

// --- Module: rate-plans (Phase 2) -------------------------------------------
Route::get('/rate-plans/{ratePlan}', [RatePlansController::class, 'show'])->middleware('can.do:rate_plans.view')->name('rate-plans.show');
Route::middleware('can.do:rate_plans.create')->group(function () {
    Route::post('/rate-plans', [RatePlansController::class, 'store'])->name('rate-plans.store');
    Route::post('/rate-plans/{ratePlan}/copy', [RatePlansController::class, 'copy'])->name('rate-plans.copy');
    Route::post('/meal-plans', [MealPlansController::class, 'store'])->name('meal-plans.store');
});
Route::middleware('can.do:rate_plans.update')->group(function () {
    Route::put('/rate-plans/{ratePlan}', [RatePlansController::class, 'update'])->name('rate-plans.update');
    Route::post('/rate-plans/{ratePlan}/status', [RatePlansController::class, 'status'])->name('rate-plans.status');
    Route::post('/cancellation-policies', [CancellationPoliciesController::class, 'store'])->name('cancellation-policies.store');
    Route::put('/cancellation-policies/{policy}', [CancellationPoliciesController::class, 'update'])->name('cancellation-policies.update');
});
// --- End module: rate-plans ---------------------------------------------------
