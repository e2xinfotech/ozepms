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

// Removes daily inventory, rate and restriction rows of nights older than
// config('ozepms.inventory.retention_days') and old calendar change-log rows, in small batches.
Schedule::command('inventory:archive')
    ->monthlyOn(1, '01:30')
    ->name('inventory:archive')
    ->withoutOverlapping()
    ->onOneServer();

// Night audit (billing): every property's business day is closed after its own audit time
// (property setting night_audit_time, default config('ozepms.billing.night_audit_time')) in its timezone.
Schedule::command('billing:night-audit')
    ->everyFifteenMinutes()
    ->name('billing:night-audit')
    ->withoutOverlapping(60)
    ->onOneServer();

// Booking engine: online bookings whose payment did not arrive within the hold go back on sale.
Schedule::command('booking:expire-holds')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// Reports: rebuilds the rollups of the last week and the selling horizon (bookings refresh them
// as they change; this run also picks up room count changes and repairs anything missed).
Schedule::command('reports:refresh')
    ->dailyAt('03:10')
    ->name('reports:refresh')
    ->withoutOverlapping(120)
    ->onOneServer();

// Channel manager: pending availability / rate / restriction changes go to the channels every
// minute (with back-off after failures); polled channels deliver their bookings.
Schedule::command('channels:sync')
    ->everyMinute()
    ->name('channels:sync')
    ->withoutOverlapping(10)
    ->onOneServer();

// Channel sync logs older than config('channels.log_retention_days').
Schedule::call(fn () => \App\Models\ChannelSyncLog::query()->where('created_at', '<', now()->subDays((int) config('channels.log_retention_days', 90)))->limit(50000)->delete())
    ->dailyAt('03:40')
    ->name('channels:prune-logs');
