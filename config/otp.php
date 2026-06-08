<?php

return [
    'length' => (int) env('OTP_LENGTH', 6),
    'ttl' => (int) env('OTP_TTL', 300),
    'max_attempts' => (int) env('OTP_MAX_ATTEMPTS', 5),
    'delivery_channel' => env('OTP_DELIVERY_CHANNEL', 'log'),
    // When SMS is disabled in a lower environment, keep OTP development moving by logging the code.
    'log_fallback' => (bool) env('OTP_LOG_FALLBACK', true),
    'sms_template' => env(
        'OTP_SMS_TEMPLATE',
        'Your Parish MIS verification code is :code. It expires in :minutes minutes.'
    ),
];
