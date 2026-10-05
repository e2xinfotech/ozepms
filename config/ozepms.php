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
        'hsts_max_age' => 31536000,
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

    'uploads' => [
        'max_image_kb' => 4096,
        'image_mimes' => ['jpg', 'jpeg', 'png', 'webp'],
    ],
];
