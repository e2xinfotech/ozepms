<?php

use App\Http\Controllers\WebApi\Property\ChannelsController;
use Illuminate\Support\Facades\Route;

// --- Module: channel manager (Phase 8) -------------------------------------------------
Route::middleware(['can.do:channels.manage', 'plan.feature:channel_manager'])->group(function () {
    Route::post('/channels', [ChannelsController::class, 'store'])->name('channels.store');
    Route::prefix('/channels/{connection}')->where(['connection' => '[0-9A-Z]{26}'])->group(function () {
        Route::put('', [ChannelsController::class, 'update'])->name('channels.update');
        Route::post('/test', [ChannelsController::class, 'test'])->name('channels.test');
        Route::post('/sync', [ChannelsController::class, 'sync'])->middleware('throttle:20,1')->name('channels.sync');
        Route::post('/pause', [ChannelsController::class, 'pause'])->name('channels.pause');
        Route::post('/resume', [ChannelsController::class, 'resume'])->name('channels.resume');
        Route::post('/disconnect', [ChannelsController::class, 'disconnect'])->name('channels.disconnect');
        Route::post('/request-approval', [ChannelsController::class, 'requestApproval'])->name('channels.request-approval');
        // E2X staff only (also available in support mode): stop or restart all traffic of a connection.
        Route::middleware('can.do:platform.channels.approve')->group(function () {
            Route::post('/suspend', [ChannelsController::class, 'suspend'])->name('channels.suspend');
            Route::post('/unsuspend', [ChannelsController::class, 'unsuspend'])->name('channels.unsuspend');
        });
        Route::get('/suggest', [ChannelsController::class, 'suggest'])->name('channels.suggest');
        Route::put('/mapping', [ChannelsController::class, 'mapping'])->name('channels.mapping');
        Route::get('/logs', [ChannelsController::class, 'logs'])->name('channels.logs');
        Route::get('/bookings', [ChannelsController::class, 'bookings'])->name('channels.bookings');
        Route::post('/bookings/{booking}/retry', [ChannelsController::class, 'retryBooking'])->name('channels.bookings.retry');
        Route::get('/test-channel/holds', [ChannelsController::class, 'holds'])->name('channels.test.holds');
        Route::post('/test-channel/bookings', [ChannelsController::class, 'sendBooking'])->name('channels.test.send');
        Route::post('/test-channel/bookings/{booking}/modify', [ChannelsController::class, 'modifyBooking'])->name('channels.test.modify');
        Route::post('/test-channel/bookings/{booking}/cancel', [ChannelsController::class, 'cancelBooking'])->name('channels.test.cancel');
        Route::post('/test-channel/outage', [ChannelsController::class, 'outage'])->name('channels.test.outage');
    });
});
// --- End module: channel manager ---------------------------------------------------------
