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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Google Form Webhook Configuration
    |--------------------------------------------------------------------------
    |
    | Member intake form submissions arrive via a webhook (e.g., from
    | Google Apps Script or a custom integration).
    |
    | The shared secret is used for HMAC verification to ensure only
    | authorized callers can submit.
    |
    */

    'google_form_webhook' => [
        'secret' => env('WEBHOOK_MEMBER_SUBMISSION_SECRET', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Google reCAPTCHA Configuration
    |--------------------------------------------------------------------------
    |
    | Protects the member intake form from spam and bot abuse.
    |
    | Setup:
    | 1. Go to https://www.google.com/recaptcha/admin
    | 2. Register a new site
    | 3. Choose "reCAPTCHA v3"
    | 4. Add your domains (localhost, staging, production)
    | 5. Copy Site Key and Secret Key to .env
    |
    | v3 returns a score (0.0-1.0):
    |   1.0 = definitely human
    |   0.0 = definitely bot
    | We use the score threshold to decide: score >= threshold = allow
    |
    */

    'google_recaptcha' => [
        'enabled' => env('RECAPTCHA_ENABLED', false),
        'site_key' => env('RECAPTCHA_SITE_KEY'),
        'secret_key' => env('RECAPTCHA_SECRET_KEY'),
        'score_threshold' => env('RECAPTCHA_SCORE_THRESHOLD', 0.5),
    ],

    /*
    |--------------------------------------------------------------------------
    | mNotify SMS Service Configuration
    |--------------------------------------------------------------------------
    |
    | Used to send welcome SMS, reminders, and notifications to members.
    | This should already be configured for your system.
    |
    */

    'mnotify' => [
        'api_key' => env('MNOTIFY_API_KEY'),
        'sender_id' => env('MNOTIFY_SENDER_ID', 'WIS-CMS'),
    ],

];
