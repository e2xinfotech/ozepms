<?php

use App\Http\Controllers\Web\Property\RatePlansController;
use Illuminate\Support\Facades\Route;

// --- Module: rate-plans (Phase 2) -------------------------------------------
Route::get('/rate-plans', [RatePlansController::class, 'index'])->middleware('can.do:rate_plans.view')->name('rate-plans');
Route::get('/rate-plans/new', [RatePlansController::class, 'create'])->middleware('can.do:rate_plans.create')->name('rate-plans.create');
Route::get('/rate-plans/{ratePlan}/edit', [RatePlansController::class, 'edit'])->middleware('can.do:rate_plans.update')->name('rate-plans.edit');
// --- End module: rate-plans ---------------------------------------------------
