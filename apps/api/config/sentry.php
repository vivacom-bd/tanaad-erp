<?php

declare(strict_types=1);

// Error reporting seam. sentry/sentry-laravel is NOT installed yet: this file is read only once the
// package is added (`composer require sentry/sentry-laravel`), and bootstrap/app.php hooks it in only
// when the package exists AND SENTRY_LARAVEL_DSN is set. With no DSN nothing is sent anywhere.
return [
    'dsn' => env('SENTRY_LARAVEL_DSN'),
    'release' => env('SENTRY_RELEASE'),
    'environment' => env('SENTRY_ENVIRONMENT', env('APP_ENV', 'production')),
    // Performance tracing is off unless a rate is given.
    'traces_sample_rate' => env('SENTRY_TRACES_SAMPLE_RATE') === null ? null : (float) env('SENTRY_TRACES_SAMPLE_RATE'),
    // Never ship PINs, passwords or bearer tokens or other personal data to a third party.
    'send_default_pii' => false,
    'breadcrumbs' => ['sql_bindings' => false],
];
