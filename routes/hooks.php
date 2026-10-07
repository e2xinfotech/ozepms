<?php

use App\Http\Controllers\Hooks\RazorpayWebhookController;
use Illuminate\Support\Facades\Route;

/*
| Server-to-server callbacks (no session, no CSRF; each one verifies its own signature).
| Prefix: /hooks   Name prefix: hooks.
*/
Route::post('/razorpay', RazorpayWebhookController::class)->middleware('throttle:120,1')->name('razorpay');
Route::post('/channels/{provider}/{connection}', \App\Http\Controllers\Hooks\ChannelWebhookController::class)
    ->where(['provider' => '[a-z_]{2,30}', 'connection' => '[0-9A-Z]{26}'])->middleware('throttle:600,1')->name('channels');
