<?php

declare(strict_types=1);

namespace Tanaad\Shared\Ledger;

use Carbon\CarbonImmutable;

/**
 * An immutable fact produced by a handler, before it has a seq.
 */
final readonly class Event
{
    /** Always UTC (see CommandContext::$occurredAt). */
    public CarbonImmutable $occurredAt;

    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $id,
        public string $type,
        public ?string $siteId,
        public ?string $actorId,
        public ?string $deviceId,
        CarbonImmutable $occurredAt,
        public array $payload,
        public int $version = 1,
        public ?string $correctsEventId = null,
        public ?string $reason = null,
    ) {
        $this->occurredAt = $occurredAt->utc();
    }

    /**
     * The usual way a handler builds an event: the command's context supplies the
     * who/where/when, and the primary event takes the client's id when there is one.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function from(
        CommandContext $context,
        string $type,
        array $payload,
        bool $primary = true,
        int $version = 1,
        ?string $correctsEventId = null,
        ?string $reason = null,
    ): self {
        return new self(
            id: $primary ? $context->eventIdOrNew() : CommandContext::system()->eventIdOrNew(),
            type: $type,
            siteId: $context->siteId,
            actorId: $context->actorId,
            deviceId: $context->deviceId,
            occurredAt: $context->occurredAt,
            payload: $payload,
            version: $version,
            correctsEventId: $correctsEventId,
            reason: $reason,
        );
    }
}
