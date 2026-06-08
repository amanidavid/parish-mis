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

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'sms' => [
        'enabled' => (bool) env('SMS_ENABLED', false),
        'driver' => env('SMS_DRIVER', 'nextsms'),
        'endpoint' => env('SMS_ENDPOINT', 'https://messaging-service.co.tz/api/sms/v1/text/single'),
        'api_key' => env('SMS_API_KEY'),
        'secret_key' => env('SMS_SECRET_KEY'),
        'sender_id' => env('SMS_SENDER_ID', 'NEXTSMS'),
        'timeout' => (int) env('SMS_TIMEOUT', 15),
    ],

];
