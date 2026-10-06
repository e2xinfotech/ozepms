<?php

use App\Http\Controllers\Hooks\RazorpayWebhookController;
use Illuminate\Support\Facades\Route;

/*
| Server-to-server callbacks (no session, no CSRF; each one verifies its own signature).
| Prefix: /hooks   Name prefix: hooks.
*/
Route::post('/razorpay', RazorpayWebhookController::class)->middleware('throttle:120,1')->name('razorpay');
