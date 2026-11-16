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
    /**
     * Always UTC. Seeders and clients pass instants in other zones (e.g. parsed as Africa/Kampala, or an
     * ISO string with +03:00); the DB layer formats DateTime bindings without converting, so a non-UTC
     * instant would be stored as local wall-clock time in UTC columns. Normalising here fixes every path.
     */
    public CarbonImmutable $occurredAt;

    public function __construct(
        public ?string $siteId,
        public ?string $actorId,
        public ?string $deviceId,
        CarbonImmutable $occurredAt,
        public ?string $eventId = null,
        public ?string $provisionalRef = null,
    ) {
        $this->occurredAt = $occurredAt->utc();
    }

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
