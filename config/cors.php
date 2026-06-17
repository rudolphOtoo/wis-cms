<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Controls which browser origins may call this API and which headers and
    | methods are permitted. In production, set CORS_ALLOWED_ORIGINS in .env
    | to the exact SPA origin (e.g. "https://wis-cms.example.com"). Multiple
    | origins are supported as a comma-separated list:
    |
    |   CORS_ALLOWED_ORIGINS=https://app.example.com,https://admin.example.com
    |
    | Do NOT use '*' in production — it disables the browser's same-origin
    | protection for all users of any site that happens to hit this API.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],

    'allowed_origins' => explode(',', env('CORS_ALLOWED_ORIGINS', 'http://localhost:3000')),

    'allowed_origins_patterns' => [],

    // Explicit header allowlist — only the headers this API actually reads.
    // 'Authorization'     → Sanctum Bearer token
    // 'Content-Type'      → JSON request bodies
    // 'Accept'            → forces JSON error responses
    // 'X-Requested-With'  → Laravel AJAX detection
    // 'X-Webhook-Secret'  → member-intake webhook authentication
    // 'X-Idempotency-Key' → member-intake deduplication
    'allowed_headers' => [
        'Authorization',
        'Content-Type',
        'Accept',
        'X-Requested-With',
        'X-Webhook-Secret',
        'X-Idempotency-Key',
    ],

    'exposed_headers' => [],

    // Cache the preflight response for 2 hours to reduce OPTIONS round-trips.
    'max_age' => 7200,

    // Keep false — this API uses Bearer tokens, not session cookies.
    // Setting this to true would require non-wildcard origins AND changes to
    // Axios/fetch config (withCredentials: true). Do not enable without a
    // matching frontend change.
    'supports_credentials' => false,

];
