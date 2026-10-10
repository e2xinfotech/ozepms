<?php

/*
| OzePMS application settings.
| Every value that may need to change later lives here (or in .env) so it can
| be adjusted in one place. Per-property overrides are stored in property_settings.
*/

return [

    'brand' => [
        'name' => env('APP_NAME', 'OzePMS'),
        'tagline' => 'Property Management System',
        'company' => 'E2X Infotech Pvt Ltd.',
        'support_email' => env('OZ_SUPPORT_EMAIL', 'support@e2xinfotech.in'),
        // Photo behind the sign-in screen (path under public/, e.g. /images/auth-hero.jpg); empty = brand gradient only.
        'auth_image' => env('OZ_AUTH_IMAGE') ?: '/images/auth-hero.webp',
    ],

    'locales' => [
        'default' => env('APP_LOCALE', 'en'),
        'available' => [
            'en' => 'English',
            'fr' => 'Français',
            'it' => 'Italiano',
            'de' => 'Deutsch',
        ],
    ],

    'property' => [
        // Property codes look like P1001, P1002 … (prefix + number, never reused).
        'code_prefix' => 'P',
        'code_start' => 1001,
        'default_currency' => env('OZ_DEFAULT_CURRENCY', 'INR'),
        'default_country' => env('OZ_DEFAULT_COUNTRY', 'IN'),
        'default_timezone' => env('OZ_DEFAULT_TIMEZONE', 'Asia/Kolkata'),
        'default_check_in' => '14:00',
        'default_check_out' => '11:00',
        'date_formats' => ['DD MMM YYYY', 'DD/MM/YYYY', 'MM/DD/YYYY', 'YYYY-MM-DD'],
        'number_formats' => ['en-IN', 'en-US', 'de-DE', 'fr-FR', 'it-IT'],
    ],

    'subscription' => [
        // Plan given to a property registered by its owner (code from subscription_plans).
        'default_plan' => env('OZ_DEFAULT_PLAN', 'starter'),
        'default_trial_days' => 14,
        'default_grace_days' => 7,
        'expiry_warning_days' => 7,
    ],

    'security' => [
        'password_min_length' => 10,
        'login_attempts_per_minute' => 5,
        // Requests per minute and IP to the sign-in, two-factor and password reset endpoints.
        'auth_requests_per_minute' => (int) env('OZ_AUTH_REQUESTS_PER_MINUTE', 10),
        // Wrong two-factor codes accepted before the pending sign-in is discarded.
        'two_factor_max_attempts' => 5,
        'lockout_after_failures' => 10,
        'lockout_minutes' => 15,
        // Two-factor authentication is required for these groups.
        'require_2fa' => [
            'platform_users' => (bool) env('OZ_REQUIRE_2FA_PLATFORM', true),
            'property_owners' => (bool) env('OZ_REQUIRE_2FA_OWNERS', true),
        ],
        'password_confirm_seconds' => 900,
        // "Log in as": how long a session may act as someone else, and the shortest reason accepted.
        'impersonation_minutes' => 60,
        'impersonation_reason_min' => 5,
        'hsts_max_age' => 31536000,
        // Name of the "keep me signed in" cookie.
        'remember_cookie' => env('OZ_REMEMBER_COOKIE', 'oz_rm'),
    ],

    'sso' => [
        // Shown on the login page only when configured.
        'google' => (bool) env('OZ_SSO_GOOGLE', false),
        'microsoft' => (bool) env('OZ_SSO_MICROSOFT', false),
    ],

    'pagination' => [
        'default' => 10,
        'options' => [10, 20, 50, 100],
    ],

    'logging' => [
        'slow_query_ms' => (int) env('OZ_SLOW_QUERY_MS', 200),
        'client_errors_per_minute' => 30,
        'client_error_max_chars' => 4000,
        // Days the per-request change trail is kept (the audit log is kept for ever).
        'request_trail_days' => 730,
        // Keys whose values are never written to logs or audit trails.
        'redact_keys' => [
            'password', 'password_confirmation', 'current_password', 'token', '_token',
            'secret', 'two_factor_secret', 'two_factor_recovery', 'code', 'otp',
            'id_number', 'card_number', 'cvv', 'authorization', 'cookie', 'remember_token',
        ],
    ],

    'demo' => [
        // Password for every account created by the DemoSeeder.
        'password' => env('OZ_DEMO_PASSWORD', 'Demo@12345'),
    ],

    'inventory' => [
        // Days ahead (from the property's today) for which daily inventory, rates and
        // restrictions are kept ready; extended every night by the scheduler.
        'horizon_days' => (int) env('OZ_INVENTORY_HORIZON_DAYS', 730),
        // Longest date range one calendar edit may cover.
        'max_edit_days' => 731,
        // Booking-engine holds are released after this many minutes without payment.
        'hold_minutes' => 15,
        // A night counts as "low availability" (calendar colours and filter) when the rooms left
        // are at most this share of the room type's rooms (and at least 1 room).
        'low_availability_percent' => 20,
        // inventory:archive (monthly) deletes daily inventory / rate / restriction rows of nights
        // older than this many days, and calendar change-log rows older than change_log_retention_days.
        // Reservations keep their own nightly prices, so no booking depends on these rows.
        'retention_days' => (int) env('OZ_INVENTORY_RETENTION_DAYS', 400),
        'change_log_retention_days' => (int) env('OZ_ARI_LOG_RETENTION_DAYS', 400),
        'archive_batch' => 5000,
    ],

    // Versioned API /api/v1 (property API keys).
    'api' => [
        'requests_per_minute' => (int) env('OZ_API_PER_MINUTE', 120),
    ],

    // Public booking engine (Phase 6): /book/{property code}.
    // Reports (Phase 7): longest period of one report run, in days.
    'reports' => [
        'max_days' => (int) env('OZ_REPORTS_MAX_DAYS', 400),
    ],

    'booking_engine' => [
        // Own address for the public booking engine, e.g. "book.ozepms.e2xinfotech.in" (host name only).
        // Empty: the engine lives under the PMS address at /book/{code}. When set, it is served at
        // https://{domain}/{code}, the PMS screens answer 404 on that host and /book/... on the PMS host redirects there.
        'domain' => env('OZ_BOOKING_DOMAIN') ?: null,
        // Minutes a booking waiting for online payment holds its rooms.
        'hold_minutes' => (int) env('OZ_BE_HOLD_MINUTES', 15),
        // Search results are cached per property, ARI version, offers and query.
        'cache_seconds' => (int) env('OZ_BE_CACHE_SECONDS', 300),
        // Furthest arrival date and longest stay a guest can search.
        'max_days_ahead' => 500,
        'max_nights' => 30,
        'max_rooms' => 5,
        // Requests per minute and IP.
        'search_per_minute' => 30,
        'book_per_minute' => 6,
    ],

    'billing' => [
        // Folio numbers: prefix + per-property sequence (F-000123).
        'folio_prefix' => 'F-',
        // GST invoice numbers (max 16 characters, unique per property and financial year, gap-free):
        // {code}/{fy}/{seq}: P1001/2627/00123. Credit notes use their own series: P1001/C2627/0012.
        'invoice_seq_digits' => 5,
        'credit_note_seq_digits' => 4,
        // First month of the financial year (India: April).
        'financial_year_start_month' => (int) env('OZ_FY_START_MONTH', 4),
        // SAC / HSN code printed for room nights (India: 9963 accommodation services).
        'accommodation_sac' => '9963',
        // Rounds invoice totals to whole currency units with a round-off line (off: payments match the folio exactly).
        'invoice_round_off' => (bool) env('OZ_INVOICE_ROUND_OFF', false),
        // Issue the tax invoice automatically when the last room checks out.
        'invoice_on_checkout' => true,
        // Tax category used for cancellation / no-show fees; null = fee posted without tax.
        'cancellation_fee_tax_category' => env('OZ_CANCELLATION_FEE_TAX_CATEGORY') ?: null,
        // Night audit: default local time (property timezone) after which the previous business
        // day is closed; per property in property_settings key "night_audit_time".
        'night_audit_time' => env('OZ_NIGHT_AUDIT_TIME', '02:00'),
        // Mark confirmed / pending arrivals of the audited day that never checked in as no-show.
        'auto_no_show' => (bool) env('OZ_AUTO_NO_SHOW', true),
        // Most business days one audit run catches up (a property that was offline for long).
        'night_audit_max_days' => 7,
        'razorpay' => [
            // Online payments are available only when all three values are set.
            'key_id' => env('RAZORPAY_KEY_ID'),
            'key_secret' => env('RAZORPAY_KEY_SECRET'),
            'webhook_secret' => env('RAZORPAY_WEBHOOK_SECRET'),
            'base_url' => env('RAZORPAY_BASE_URL', 'https://api.razorpay.com/v1'),
            'timeout' => 15,
        ],
    ],

    'uploads' => [
        'max_image_kb' => 4096,
        'image_mimes' => ['jpg', 'jpeg', 'png', 'webp'],
    ],
];
