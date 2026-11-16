<?php

declare(strict_types=1);

namespace Tanaad\Shared\Http;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The one place API datetimes are normalised: every JSON string value that is a date-time
 * ("2026-11-10 09:00:00", "...T09:00:00+03:00", Eloquent's "...T09:00:00.000000Z") leaves the API as
 * ISO-8601 UTC with a Z, e.g. "2026-11-10T09:00:00Z" (milliseconds kept when non-zero).
 * Values without an offset are DB reads and the DB stores UTC (CommandContext normalises occurred_at;
 * the app timezone is UTC). Plain dates ("2026-11-10") and other strings are left alone.
 */
final class UtcTimestamps
{
    private const string PATTERN = '/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+-]\d{2}:?\d{2})?$/';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if ($response instanceof JsonResponse) {
            // Decoded as objects so empty JSON objects stay objects on re-encode.
            $response->setData(self::walk($response->getData()));
        }

        return $response;
    }

    public static function iso(string $value): string
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            return $value;
        }
        $at = CarbonImmutable::parse($value, 'UTC')->utc();

        return $at->format($at->micro >= 1000 ? 'Y-m-d\TH:i:s.v\Z' : 'Y-m-d\TH:i:s\Z');
    }

    private static function walk(mixed $data): mixed
    {
        if (is_string($data)) {
            return self::iso($data);
        }
        if (is_array($data)) {
            return array_map(self::walk(...), $data);
        }
        if ($data instanceof \stdClass) {
            foreach (get_object_vars($data) as $k => $v) {
                $data->{$k} = self::walk($v);
            }
        }

        return $data;
    }
}
