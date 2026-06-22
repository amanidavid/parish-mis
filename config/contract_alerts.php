<?php

return [
    'timezone' => env('CONTRACT_ALERT_TIMEZONE', 'Africa/Nairobi'),
    'warning_days' => max((int) env('CONTRACT_ALERT_WARNING_DAYS', 7), 1),
    'staff_permissions' => array_values(array_filter(array_map(
        static fn (string $permission): string => trim($permission),
        explode(',', (string) env(
            'CONTRACT_ALERT_STAFF_PERMISSIONS',
            'customer_contracts.view,customer_contracts.create,customer_contracts.update'
        ))
    ))),
    'recipients' => [
        'occupant' => (bool) env('CONTRACT_ALERT_SEND_TO_OCCUPANT', true),
        'staff' => (bool) env('CONTRACT_ALERT_SEND_TO_STAFF', true),
    ],
    'channels' => [
        'sms' => [
            'enabled' => (bool) env('CONTRACT_ALERT_SMS_ENABLED', false),
        ],
        'email' => [
            'enabled' => (bool) env('CONTRACT_ALERT_EMAIL_ENABLED', false),
        ],
    ],
];
