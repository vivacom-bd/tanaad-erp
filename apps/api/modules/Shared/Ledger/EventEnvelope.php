<?php

declare(strict_types=1);

namespace Tanaad\Shared\Ledger;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Str;

/**
 * What a client sends to POST /events: a proposed fact with a client-chosen UUIDv7.
 */
final readonly class EventEnvelope
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $id,
        public string $type,
        public CarbonImmutable $occurredAt,
        public array $payload,
        public int $version = 1,
        public ?string $deviceId = null,
        public ?string $correctsEventId = null,
        public ?string $reason = null,
        public ?string $provisionalRef = null,
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     *
     * @throws Rejection with invalid_payload when the envelope itself is malformed
     */
    public static function fromArray(array $raw): self
    {
        $errors = [];
        $id = $raw['id'] ?? null;
        $type = $raw['type'] ?? null;
        $payload = $raw['payload'] ?? null;
        $occurredAt = $raw['occurred_at'] ?? null;

        if (! is_string($id) || ! Str::isUuid($id)) {
            $errors['id'] = 'must be a UUID';
        }
        if (! is_string($type) || $type === '' || ! preg_match('/^[a-z_]+\.[a-z_]+$/', $type)) {
            $errors['type'] = 'must look like module.event_name';
        }
        if (! is_array($payload)) {
            $errors['payload'] = 'must be an object';
        }
        $parsedAt = null;
        if (! is_string($occurredAt)) {
            $errors['occurred_at'] = 'must be an ISO-8601 timestamp';
        } else {
            try {
                $parsedAt = CarbonImmutable::parse($occurredAt);
            } catch (InvalidFormatException) {
                $errors['occurred_at'] = 'must be an ISO-8601 timestamp';
            }
        }
        foreach (['device_id', 'corrects_event_id', 'reason', 'provisional_ref'] as $key) {
            if (array_key_exists($key, $raw) && $raw[$key] !== null && ! is_string($raw[$key])) {
                $errors[$key] = 'must be a string';
            }
        }
        if ($errors !== []) {
            throw Rejection::invalidPayload($errors);
        }

        /** @var string $id */
        /** @var string $type */
        /** @var array<string, mixed> $payload */
        /** @var CarbonImmutable $parsedAt */
        return new self(
            id: $id,
            type: $type,
            occurredAt: $parsedAt,
            payload: $payload,
            version: (int) ($raw['version'] ?? 1),
            deviceId: $raw['device_id'] ?? null,
            correctsEventId: $raw['corrects_event_id'] ?? null,
            reason: $raw['reason'] ?? null,
            provisionalRef: $raw['provisional_ref'] ?? null,
        );
    }
}
