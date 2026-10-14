<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'fbr' => [
        'pakistan_submission_enabled' => env('FBR_PAKISTAN_SUBMISSION_ENABLED', false),
        'credit_note_submission_enabled' => false,
        'endpoints' => [
            'sandbox' => env('FBR_SANDBOX_API_ENDPOINT'),
            'production' => env('FBR_PRODUCTION_API_ENDPOINT'),
        ],
        'connect_timeout' => env('FBR_API_CONNECT_TIMEOUT', 5),
        'timeout' => env('FBR_API_TIMEOUT', 15),
    ],

    'stripe' => [
        'enabled' => env('STRIPE_BILLING_ENABLED', false),
        'secret' => env('STRIPE_SECRET_KEY'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'return_origin' => env('FRONTEND_URL'),
        'automatic_tax_enabled' => env('STRIPE_AUTOMATIC_TAX_ENABLED', false),
        'payment_method_portal_configuration_id' => env('STRIPE_PAYMENT_METHOD_PORTAL_CONFIGURATION_ID'),
    ],

    'billing' => [
        'ai_monthly_token_allowance' => (int) env('BILLING_AI_MONTHLY_TOKEN_ALLOWANCE', 50_000),
    ],

];
