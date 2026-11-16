<?php

declare(strict_types=1);

namespace Tanaad\Shared\Ledger;

use Carbon\CarbonImmutable;

/**
 * An event as it sits in the ledger: the Event plus seq, received_at and batch.
 */
final readonly class StoredEvent
{
    public function __construct(
        public Event $event,
        public int $seq,
        public CarbonImmutable $receivedAt,
        public ?string $batchId = null,
    ) {}

    public function id(): string
    {
        return $this->event->id;
    }

    public function type(): string
    {
        return $this->event->type;
    }

    public function siteId(): ?string
    {
        return $this->event->siteId;
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return $this->event->payload;
    }

    /** @param object{id: string, type: string, site_id: ?string, actor_id: ?string, device_id: ?string, occurred_at: string, received_at: string, seq: int|string, payload: string, version: int|string, corrects_event_id: ?string, reason: ?string, batch_id: ?string} $row */
    public static function fromRow(object $row): self
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);

        return new self(
            event: new Event(
                id: $row->id,
                type: $row->type,
                siteId: $row->site_id,
                actorId: $row->actor_id,
                deviceId: $row->device_id,
                occurredAt: CarbonImmutable::parse($row->occurred_at),
                payload: $payload,
                version: (int) $row->version,
                correctsEventId: $row->corrects_event_id,
                reason: $row->reason,
            ),
            seq: (int) $row->seq,
            receivedAt: CarbonImmutable::parse($row->received_at),
            batchId: $row->batch_id,
        );
    }
}
