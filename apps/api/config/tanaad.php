<?php

declare(strict_types=1);

return [
    // Business timezone for business-day and shift boundaries (ADR-008).
    'timezone' => env('TANAAD_TIMEZONE', 'Africa/Kampala'),

    // Hour at which a business day starts when a site has no setting of its own.
    'day_start_hour' => (int) env('TANAAD_DAY_START_HOUR', 6),

    // Default currency for sites without their own setting (ADR-009).
    'currency' => env('TANAAD_CURRENCY', 'UGX'),

    // Maximum number of events accepted in one POST /events batch.
    'ingest_batch_max' => (int) env('TANAAD_INGEST_BATCH_MAX', 500),

    // How many follow-up commands a policy chain may raise inside one transaction.
    'policy_depth_max' => (int) env('TANAAD_POLICY_DEPTH_MAX', 5),

    // Fallback for people:archive-absent when a site has no archive_after_absent_days; 0 = off.
    'people' => [
        'archive_after_absent_days' => (int) env('TANAAD_ARCHIVE_AFTER_ABSENT_DAYS', 0),
    ],

    'pin' => [
        'max_attempts' => (int) env('TANAAD_PIN_MAX_ATTEMPTS', 5),
        'lockout_minutes' => (int) env('TANAAD_PIN_LOCKOUT_MINUTES', 15),
        // Upper bound on a PIN (shared-tablet, device-bound) session: its token expires at the shorter of the
        // user's role TTL (auth.token_ttl_minutes) and this many hours. 0 = no extra cap.
        'session_hours' => (int) env('TANAAD_PIN_SESSION_HOURS', 14),
    ],

    // Token lifetimes in minutes, by the user's role (ADR-006). A user holding several roles gets the
    // longest of them. PIN sessions on shared tablets are further capped by pin.session_hours. 0 = never expires.
    // Applies to every token at issue time AND at request time (tokens without expires_at age from created_at).
    'auth' => [
        'token_ttl_minutes' => [
            'ceo' => (int) env('TANAAD_TTL_CEO', 10080),                    // 7 days: reads from a personal phone
            'group_admin' => (int) env('TANAAD_TTL_GROUP_ADMIN', 480),      // 8 hours: highest privilege, shortest office session
            'site_manager' => (int) env('TANAAD_TTL_SITE_MANAGER', 1440),   // 24 hours
            'supervisor' => (int) env('TANAAD_TTL_SUPERVISOR', 720),        // 12 hours: one shift
            'cashier' => (int) env('TANAAD_TTL_CASHIER', 480),              // 8 hours: handles money
            'gate' => (int) env('TANAAD_TTL_GATE', 480),                    // 8 hours: shared device
            'storekeeper' => (int) env('TANAAD_TTL_STOREKEEPER', 720),
            'mechanic' => (int) env('TANAAD_TTL_MECHANIC', 720),
            'sales' => (int) env('TANAAD_TTL_SALES', 720),
            'driver' => (int) env('TANAAD_TTL_DRIVER', 720),
            'default' => (int) env('TANAAD_TTL_DEFAULT', 720),              // a user with no role
            'device' => (int) env('TANAAD_TTL_DEVICE', 0),                  // registered tablet token; re-register to rotate
        ],
    ],

    // Named rate limiters (requests per minute). See App\Providers\AppServiceProvider and the Identity provider.
    'rate_limits' => [
        'login' => (int) env('TANAAD_RL_LOGIN', 10),        // per username + IP
        'pin_login' => (int) env('TANAAD_RL_PIN_LOGIN', 5), // per username + IP
        'api' => (int) env('TANAAD_RL_API', 1200),          // per signed-in user (IP when anonymous); a CEO first load is ~200 requests
        'ingest' => (int) env('TANAAD_RL_INGEST', 120),     // per device (user when the token is not device-bound)
    ],

    'ledger' => [
        // HMAC key for the per-event checksum written at append; falls back to APP_KEY. Changing it makes
        // older events fail ledger:verify, so set it once and keep it with the backups.
        'checksum_key' => env('TANAAD_LEDGER_KEY'),
    ],

    'devices' => [
        // A device that has not sent a heartbeat for this many minutes is stale.
        'stale_after_minutes' => (int) env('TANAAD_DEVICE_STALE_MINUTES', 15),
    ],

    'messaging' => [
        // log (default: app log + outbound_messages) or whatsapp_cloud (Graph API template messages).
        'driver' => env('TANAAD_MESSAGING_DRIVER', 'log'),
        'max_attempts' => (int) env('TANAAD_MESSAGING_MAX_ATTEMPTS', 5),
        'backoff' => [30, 120, 600, 1800],
        'whatsapp' => [
            'token' => env('WHATSAPP_TOKEN'),
            'phone_id' => env('WHATSAPP_PHONE_ID'),
            'api_version' => env('WHATSAPP_API_VERSION', 'v21.0'),
            'base_url' => env('WHATSAPP_BASE_URL', 'https://graph.facebook.com'),
        ],
        // Event type -> approved template name and the order of its body parameters (title, detail, body).
        'templates' => [
            'group.brief_sent' => ['name' => 'tanaad_morning_brief', 'language' => 'en', 'params' => ['title', 'detail']],
            'group.exception_raised' => ['name' => 'tanaad_critical_alert', 'language' => 'en', 'params' => ['title', 'detail']],
            'dispatch.gate_held' => ['name' => 'tanaad_gate_held', 'language' => 'en', 'params' => ['title', 'detail']],
            'orders.approved' => ['name' => 'tanaad_order_update', 'language' => 'en', 'params' => ['title', 'detail']],
            'orders.held' => ['name' => 'tanaad_order_update', 'language' => 'en', 'params' => ['title', 'detail']],
        ],
    ],

    'documents' => [
        // PDF output through Browsershot (package not installed yet); off returns structured data only.
        'pdf' => (bool) env('TANAAD_DOCUMENTS_PDF', false),
    ],
];
