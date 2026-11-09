<?php

declare(strict_types=1);

namespace Tanaad\Shared\Ledger;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Who, where, when and from which device. Every command carries one.
 *
 * `eventId` is the id the client chose for the primary event this command produces.
 * It makes an offline retry idempotent: the same id is accepted once and reported as
 * a duplicate afterwards. Server-originated commands leave it null and get a fresh id.
 */
final readonly class CommandContext
{
    public function __construct(
        public ?string $siteId,
        public ?string $actorId,
        public ?string $deviceId,
        public CarbonImmutable $occurredAt,
        public ?string $eventId = null,
        public ?string $provisionalRef = null,
    ) {}

    public static function system(?string $siteId = null): self
    {
        return new self($siteId, null, null, CarbonImmutable::now());
    }

    public function withEventId(?string $eventId): self
    {
        return new self($this->siteId, $this->actorId, $this->deviceId, $this->occurredAt, $eventId, $this->provisionalRef);
    }

    public function eventIdOrNew(): string
    {
        return $this->eventId ?? (string) Str::uuid7();
    }
}
