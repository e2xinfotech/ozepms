<?php

use App\Http\Controllers\Web\Property\ChannelsController;
use Illuminate\Support\Facades\Route;

// --- Module: channel manager (Phase 8) -------------------------------------------------
Route::middleware(['can.do:channels.manage', 'plan.feature:channel_manager'])->group(function () {
    Route::get('/channels', [ChannelsController::class, 'index'])->name('channels');
    Route::get('/channels/{connection}', [ChannelsController::class, 'show'])->where('connection', '[0-9A-Z]{26}')->name('channels.show');
});
// --- End module: channel manager ---------------------------------------------------------
