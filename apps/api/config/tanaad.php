<?php

declare(strict_types=1);

return [
    // Business timezone for business-day and shift boundaries (ADR-008).
    'timezone' => env('TANAAD_TIMEZONE', 'Africa/Mogadishu'),

    // Hour at which a business day starts when a site has no setting of its own.
    'day_start_hour' => (int) env('TANAAD_DAY_START_HOUR', 6),

    // Default currency for sites without their own setting (ADR-009).
    'currency' => env('TANAAD_CURRENCY', 'USD'),

    // Maximum number of events accepted in one POST /events batch.
    'ingest_batch_max' => (int) env('TANAAD_INGEST_BATCH_MAX', 500),

    // How many follow-up commands a policy chain may raise inside one transaction.
    'policy_depth_max' => (int) env('TANAAD_POLICY_DEPTH_MAX', 5),

    'pin' => [
        'max_attempts' => (int) env('TANAAD_PIN_MAX_ATTEMPTS', 5),
        'lockout_minutes' => (int) env('TANAAD_PIN_LOCKOUT_MINUTES', 15),
        'session_hours' => (int) env('TANAAD_PIN_SESSION_HOURS', 14),
    ],
];
