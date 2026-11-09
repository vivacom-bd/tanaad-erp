<?php

declare(strict_types=1);

namespace Tanaad\Shared\Http;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Tanaad\Shared\Ledger\CommandContext;

abstract class ApiController extends Controller
{
    /**
     * The who/where/when for a command built from an HTTP request. The actor is always the
     * authenticated user; the device comes from a device-bound token; the event id from
     * Idempotency-Key when the client sent one.
     */
    protected function context(Request $request, ?string $siteId, ?CarbonImmutable $occurredAt = null): CommandContext
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();
        $deviceId = null;
        if ($token !== null && str_starts_with((string) $token->name, 'device:')) {
            $deviceId = substr((string) $token->name, 7);
        }

        return new CommandContext(
            siteId: $siteId,
            actorId: $user?->getAuthIdentifier() === null ? null : (string) $user->getAuthIdentifier(),
            deviceId: $deviceId,
            occurredAt: $occurredAt ?? CarbonImmutable::now(),
            eventId: IdempotencyKey::from($request),
        );
    }

    /** @param array<string, mixed> $data */
    protected function created(array $data): JsonResponse
    {
        return new JsonResponse($data, 201);
    }
}
