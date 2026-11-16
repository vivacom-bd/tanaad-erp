<?php

declare(strict_types=1);

namespace Tanaad\Identity\Http;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;
use Tanaad\Identity\TokenTtl;
use Tanaad\Shared\Http\ProblemResponse;

/**
 * Global API middleware: a bearer token past its expiry is answered 401 token_expired.
 * Sanctum already refuses tokens whose expires_at has passed; this also ages tokens that
 * carry no expires_at from created_at using the role lifetime in config, and tells the
 * client why (so it can sign in again instead of retrying).
 */
final class EnforceTokenExpiry
{
    public function __construct(private readonly TokenTtl $ttl) {}

    public function handle(Request $request, Closure $next): Response
    {
        return $this->rejection($request) ?? $next($request);
    }

    /** The 401 for an expired bearer token, or null when the request carries no expired token. Also used by the unauthenticated renderer, because Sanctum's guard (which already refuses an expired expires_at) can run before this middleware. */
    public function rejection(Request $request): ?Response
    {
        $bearer = $request->bearerToken();
        $token = $bearer === null ? null : PersonalAccessToken::findToken($bearer);
        if ($token === null || ! $this->expired($token)) {
            return null;
        }
        $token->delete();

        return ProblemResponse::make(401, 'token_expired', 'Your session has expired. Sign in again.');
    }

    private function expired(PersonalAccessToken $token): bool
    {
        $now = CarbonImmutable::now();
        if ($token->expires_at !== null) {
            return $token->expires_at->isBefore($now);
        }
        $minutes = $this->ttl->minutesForToken($token);

        return $minutes > 0 && $token->created_at !== null && $token->created_at->addMinutes($minutes)->isBefore($now);
    }
}
