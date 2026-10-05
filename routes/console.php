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
