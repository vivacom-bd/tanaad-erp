<?php

declare(strict_types=1);

namespace Tanaad\Shared\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tanaad\Shared\Ledger\Rejection;

/**
 * `Idempotency-Key: <uuid>` on a write becomes the primary event id, so a retried request
 * is reported as a duplicate instead of writing twice (§16).
 */
final class IdempotencyKey
{
    public static function from(Request $request): ?string
    {
        $key = $request->header('Idempotency-Key');
        if ($key === null || $key === '') {
            return null;
        }
        if (! Str::isUuid($key)) {
            throw Rejection::invalidPayload(['Idempotency-Key' => 'must be a UUID']);
        }

        return strtolower($key);
    }
}
