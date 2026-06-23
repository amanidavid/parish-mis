<?php

return [
    'timezone' => env('PROPERTY_SUB_ALERT_TIMEZONE', 'Africa/Nairobi'),
    'warning_days' => max((int) env('PROPERTY_SUB_ALERT_WARNING_DAYS', 7), 1),
    'staff_permissions' => array_values(array_filter(array_map(
        static fn (string $permission): string => trim($permission),
        explode(',', (string) env(
            'PROPERTY_SUB_ALERT_STAFF_PERMISSIONS',
            'properties.create,properties.update'
        ))
    ))),
    'channels' => [
        'sms' => [
            'enabled' => (bool) env('PROPERTY_SUB_ALERT_SMS_ENABLED', false),
        ],
        'email' => [
            'enabled' => (bool) env('PROPERTY_SUB_ALERT_EMAIL_ENABLED', false),
        ],
    ],
];
