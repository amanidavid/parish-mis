<?php

return [
    'timezone' => env('WORKSPACE_TRIAL_ALERT_TIMEZONE', 'Africa/Nairobi'),
    'warning_days' => max((int) env('WORKSPACE_TRIAL_ALERT_WARNING_DAYS', 7), 1),
    'channels' => [
        'sms' => [
            'enabled' => (bool) env('WORKSPACE_TRIAL_ALERT_SMS_ENABLED', false),
        ],
        'email' => [
            'enabled' => (bool) env('WORKSPACE_TRIAL_ALERT_EMAIL_ENABLED', false),
        ],
    ],
    'retry' => [
        'max_attempts' => max((int) env('WORKSPACE_TRIAL_ALERT_RETRY_MAX_ATTEMPTS', 3), 1),
        'delay_ms' => max((int) env('WORKSPACE_TRIAL_ALERT_RETRY_DELAY_MS', 500), 0),
    ],
];
