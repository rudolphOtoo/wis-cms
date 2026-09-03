<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'mnotify' => [
        'api_key' => env('MNOTIFY_API_KEY'),
        'sender_id' => env('MNOTIFY_SENDER_ID', 'WIS'),
        'base_url' => env('MNOTIFY_BASE_URL', 'https://api.mnotify.com/api'),
        // When true, send() logs and returns true without hitting mNotify.
        // Defaults to on in local/testing; tests set it to false to exercise
        // the real HTTP path against Http::fake().
        'dry_run' => env('MNOTIFY_DRY_RUN'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'paystack' => [
        'secret' => env('PAYSTACK_SECRET_KEY'),
        'public' => env('PAYSTACK_PUBLIC_KEY'),
        'webhook_secret' => env('PAYSTACK_WEBHOOK_SECRET'),
        // Overridden in tests / self-hosted proxies.
        'base_url' => env('PAYSTACK_BASE_URL', 'https://api.paystack.co'),
        // How far back (minutes) the reconciliation poll rewinds beyond the
        // newest locally synced payment to absorb Paystack event-timestamp skew.
        'reconcile_overlap_minutes' => (int) env('PAYSTACK_RECONCILE_OVERLAP_MINUTES', 60),
    ],

    'google_form_webhook' => [
        // Shared secret required in the X-Webhook-Secret header on
        // POST /api/webhooks/member-submission. Generated once, stored
        // in .env. Treat like a password.
        'secret' => env('GOOGLE_FORM_WEBHOOK_SECRET'),
    ],

    // When true, Password::uncompromised() is enforced even in non-production
    // environments. Useful for staging servers or offline desktops that need
    // the check disabled: set ENABLE_PWNED_PASSWORD_CHECK=false in .env.
    'pwned_password_check' => (bool) env('ENABLE_PWNED_PASSWORD_CHECK', false),

];
