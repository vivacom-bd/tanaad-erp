<?php

declare(strict_types=1);

namespace Tanaad\Shared\Http;

use Illuminate\Http\JsonResponse;
use Tanaad\Shared\Ledger\Rejection;

/**
 * RFC 9457 problem+json with the same closed code list as ingestion (ADR-004, §16).
 */
final class ProblemResponse
{
    /** @param array<string, mixed> $extra */
    public static function make(int $status, string $code, string $detail, array $extra = []): JsonResponse
    {
        return new JsonResponse([
            'type' => "https://tanaad.app/problems/{$code}",
            'title' => str_replace('_', ' ', ucfirst($code)),
            'status' => $status,
            'code' => $code,
            'detail' => $detail,
            ...$extra,
        ], $status, ['Content-Type' => 'application/problem+json']);
    }

    public static function fromRejection(Rejection $r): JsonResponse
    {
        return self::make($r->rejectionCode->httpStatus(), $r->rejectionCode->value, $r->getMessage(), $r->details === [] ? [] : ['details' => $r->details]);
    }
}
