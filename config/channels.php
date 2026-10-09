<?php

/*
| Channel manager (Phase 8). Providers are sales channels; each one is a class implementing
| App\Domain\Channels\Contracts\ChannelProvider. A provider without a class is listed as
| "available on request" (its connectivity contract / certification is still needed).
*/
return [
    // Days ahead kept in sync with every channel (availability, prices, restrictions).
    'sync_days' => (int) env('OZ_CHANNEL_SYNC_DAYS', 365),
    // The whole change goes to the adapter in one list; each adapter cuts it to its channel's limits
    // (Booking.com: one month per request, max messages per request; Agoda: request size; Go-MMT: 30 days, items per request).
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
        // Booking.com (OTA XML). Switched on with OZ_CHANNEL_BOOKING_COM=true once E2X has sandbox access and certification.
        'booking_com' => [
            'class' => env('OZ_CHANNEL_BOOKING_COM', false) ? \App\Domain\Channels\Providers\BookingComProvider::class : null,
            'supply_url' => env('OZ_BOOKING_COM_SUPPLY_URL', 'https://supply-xml.booking.com'),
            'secure_url' => env('OZ_BOOKING_COM_SECURE_URL', 'https://secure-supply-xml.booking.com'),
            'max_messages' => (int) env('OZ_BOOKING_COM_MAX_MESSAGES', 500),
        ],
        'expedia' => ['class' => null],
        // Agoda YCS. Switched on with OZ_CHANNEL_AGODA=true once E2X has YCS partner access and certification.
        'agoda' => [
            'class' => env('OZ_CHANNEL_AGODA', false) ? \App\Domain\Channels\Providers\AgodaProvider::class : null,
            'api_url' => env('OZ_AGODA_API_URL', 'https://supply.agoda.com/api'),
            'token_url' => env('OZ_AGODA_TOKEN_URL', 'https://supply.agoda.com/token-based-authentication/exchange'),
            'max_request_bytes' => (int) env('OZ_AGODA_MAX_REQUEST_BYTES', 900000),
        ],
        'airbnb' => ['class' => null],
        // MakeMyTrip / Goibibo: no public connectivity specification; needs the partner documentation (channelmanager-tech@goibibo.com).
        'makemytrip' => [
            // PROVISIONAL: unverified wire format; there is deliberately no default api_url (nothing is sent until it is set).
            'class' => env('OZ_CHANNEL_MAKEMYTRIP', false) ? \App\Domain\Channels\Providers\MakeMyTripProvider::class : null,
            'api_url' => env('OZ_MAKEMYTRIP_API_URL'),
            'ari_path' => env('OZ_MAKEMYTRIP_ARI_PATH', '/ari'),
            'bookings_path' => env('OZ_MAKEMYTRIP_BOOKINGS_PATH', '/api/chmv2/getbookinglisting'),
            'min_interval_ms' => (int) env('OZ_MAKEMYTRIP_MIN_INTERVAL_MS', 200),
            'max_items_per_request' => (int) env('OZ_MAKEMYTRIP_MAX_ITEMS', 50),
        ],
    ],
];
