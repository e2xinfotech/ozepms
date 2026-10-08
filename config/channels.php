<?php

/*
| Channel manager (Phase 8). Providers are sales channels; each one is a class implementing
| App\Domain\Channels\Contracts\ChannelProvider. A provider without a class is listed as
| "available on request" (its connectivity contract / certification is still needed).
*/
return [
    // Days ahead kept in sync with every channel (availability, prices, restrictions).
    'sync_days' => (int) env('OZ_CHANNEL_SYNC_DAYS', 365),
    // Updates per message sent to a channel.
    'batch_size' => (int) env('OZ_CHANNEL_BATCH_SIZE', 500),
    // Change-log rows read per sync run (the rest follows in the next run).
    'log_batch' => 5000,
    // A failed send is tried again at most this many times, each after the idle interval below.
    // After the last retry the connection shows "error" and nothing more is sent until the hotel
    // presses "Try again". Retries always send the current calendar values, never the old ones.
    'max_retries' => (int) env('OZ_CHANNEL_MAX_RETRIES', 2),
    'retry_after_minutes' => (int) env('OZ_CHANNEL_RETRY_AFTER_MINUTES', 5),
    // Sync log rows older than this are removed by the nightly clean-up.
    'log_retention_days' => 90,

    'providers' => [
        // The Test Channel is a practice tool inside the PMS: no outside party is involved, so no approval.
        'test' => ['class' => \App\Domain\Channels\Providers\TestChannelProvider::class, 'requires_approval' => false],
        'booking_com' => ['class' => null],
        'expedia' => ['class' => null],
        'agoda' => ['class' => null],
        'airbnb' => ['class' => null],
    ],
];
