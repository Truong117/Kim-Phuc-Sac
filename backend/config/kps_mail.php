<?php

return [
    // No provider calls occur at boot. All switches default to off.
    'enabled' => (bool) env('KPS_MAIL_ENABLED', false),
    'send_enabled' => (bool) env('KPS_MAIL_SEND_ENABLED', false),
    'transport' => env('KPS_MAIL_TRANSPORT', 'gmail_api'),
    'from_name' => env('KPS_MAIL_FROM_NAME', 'Kim Phục Sắc - Nội bộ'),
    'test_recipients' => array_values(array_filter(array_map('trim', explode(',', (string) env('KPS_MAIL_TEST_RECIPIENTS', ''))))),
    'gmail' => [
        'client_id' => env('KPS_GMAIL_CLIENT_ID'),
        'client_secret' => env('KPS_GMAIL_CLIENT_SECRET'),
        'redirect_uri' => env('KPS_GMAIL_REDIRECT_URI'),
        'expected_email' => env('KPS_GMAIL_EXPECTED_EMAIL', 'kimphucsac@gmail.com'),
    ],
    // A fixed first-party destination; OAuth never accepts a user-provided return URL.
    'frontend_url' => env('FRONTEND_URL'),
];
