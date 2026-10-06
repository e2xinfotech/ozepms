<?php

use App\Domain\Subscription\SubscriptionService;
use Illuminate\Support\Facades\Schedule;

// Moves subscriptions through active → grace → expired once a day.
Schedule::call(fn () => app(SubscriptionService::class)->refreshStatuses())
    ->dailyAt('00:15')
    ->name('subscriptions:refresh')
    ->withoutOverlapping()
    ->onOneServer();

// Removes login attempt records older than 180 days.
Schedule::call(fn () => \App\Models\LoginAttempt::query()->where('created_at', '<', now()->subDays(180))->delete())
    ->weekly()
    ->name('login-attempts:prune');

// Creates the next day's inventory, rates and restrictions so every property always has
// config('ozepms.inventory.horizon_days') days ready to sell.
Schedule::command('inventory:horizon')
    ->dailyAt('00:30')
    ->name('inventory:horizon')
    ->withoutOverlapping()
    ->onOneServer();
