<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tanaad\Shared\Http\ProblemResponse;
use Tanaad\Shared\Ledger\Rejection;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        // Reverse proxies whose X-Forwarded-For/Proto are believed (client IP for throttling, https scheme).
        // TRUSTED_PROXIES: comma-separated IPs/CIDRs or '*'; empty (the local default) trusts none.
        $proxies = Tanaad\Shared\Http\TrustedProxies::parse((string) env('TRUSTED_PROXIES', ''));
        if ($proxies !== null) {
            $middleware->trustProxies(at: $proxies, headers: Tanaad\Shared\Http\TrustedProxies::HEADERS);
        }
        // Per-user rate limit (named limiter `api`), role-based token expiry, and UTC `Z` datetimes in every JSON body.
        $middleware->api(append: [Tanaad\Identity\Http\EnforceTokenExpiry::class, 'throttle:api', Tanaad\Shared\Http\UtcTimestamps::class]);
        $middleware->alias([
            'abilities' => Laravel\Sanctum\Http\Middleware\CheckAbilities::class,
            'ability' => Laravel\Sanctum\Http\Middleware\CheckForAnyAbility::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => true);
        $exceptions->dontReport(Rejection::class);
        // Sentry seam: inert until `composer require sentry/sentry-laravel` AND SENTRY_LARAVEL_DSN is set (see config/sentry.php).
        if (class_exists(Sentry\Laravel\Integration::class) && env('SENTRY_LARAVEL_DSN')) {
            Sentry\Laravel\Integration::handles($exceptions);
        }

        $exceptions->render(fn (Rejection $e) => ProblemResponse::fromRejection($e));
        $exceptions->render(fn (ValidationException $e) => ProblemResponse::make(422, 'invalid_payload', 'The request failed validation.', ['details' => ['errors' => $e->errors()]]));
        $exceptions->render(fn (AuthenticationException $e, Request $request) => app(Tanaad\Identity\Http\EnforceTokenExpiry::class)->rejection($request) ?? ProblemResponse::make(401, 'unauthenticated', 'Sign in first.'));
        $exceptions->render(fn (AuthorizationException $e) => ProblemResponse::make(403, 'forbidden', $e->getMessage() !== '' ? $e->getMessage() : 'This role may not perform this action here.'));
        $exceptions->render(fn (ModelNotFoundException $e) => ProblemResponse::make(404, 'not_found', 'Not found.'));
        $exceptions->render(fn (ThrottleRequestsException $e) => ProblemResponse::make(429, 'rate_limited', 'Too many requests. Slow down and retry shortly.')
            ->withHeaders($e->getHeaders()));
        $exceptions->render(fn (NotFoundHttpException $e) => ProblemResponse::make(404, 'not_found', 'Not found.'));
    })->create();
