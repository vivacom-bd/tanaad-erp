<?php

declare(strict_types=1);

namespace Tanaad\Shared\Http;

use Illuminate\Http\Request;

/** Parses TRUSTED_PROXIES (comma-separated IPs/CIDRs or '*'; empty = trust none) for $middleware->trustProxies. */
final class TrustedProxies
{
    public const int HEADERS = Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO
        | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT;

    /** @return list<string>|'*'|null */
    public static function parse(?string $value): array|string|null
    {
        $proxies = array_values(array_filter(array_map(trim(...), explode(',', (string) $value)), fn (string $p): bool => $p !== ''));

        return match (true) {
            $proxies === [] => null,
            in_array('*', $proxies, true) => '*',
            default => $proxies,
        };
    }
}
