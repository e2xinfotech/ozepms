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
    // Retries: wait 1, 2, 4 … minutes after consecutive failures, at most this long.
    'max_backoff_minutes' => 30,
    // A connection is shown as "error" after this many consecutive failures (it keeps retrying).
    'error_after_failures' => 5,
    // Sync log rows older than this are removed by the nightly clean-up.
    'log_retention_days' => 90,

    'providers' => [
        'test' => ['class' => \App\Domain\Channels\Providers\TestChannelProvider::class],
        'booking_com' => ['class' => null],
        'expedia' => ['class' => null],
        'agoda' => ['class' => null],
        'airbnb' => ['class' => null],
    ],
];
