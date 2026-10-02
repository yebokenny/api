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

    'strapi' => [
        'display_url' => env('STRAPI_DISPLAY_URL', 'http://cms.yebokenny.com'),
    ],

    'brevo' => [
        'key' => env('BREVO_API_KEY'),
        'sender_email' => env('BREVO_SENDER_EMAIL', 'no-reply@yebokenny.com'),
        'sender_name' => env('BREVO_SENDER_NAME', 'YeboKenny'),
        'support_email' => env('BREVO_SUPPORT_EMAIL', 'support@yebokenny.com'),
    ],

    'paystack' => [
        'secret_zar' => env('PAYSTACK_SECRET_ZAR'),
        'secret_ngn' => env('PAYSTACK_SECRET_NGN'),
        'portal_url' => env('PORTAL_URL', 'http://127.0.0.1:3001'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
