<?php

declare(strict_types=1);

namespace Tanaad\Shared\Ledger;

use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Database\ConnectionInterface;

final class EventStore
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function exists(string $id): bool
    {
        return $this->db->table('events')->where('id', $id)->exists();
    }

    /**
     * Persist events in order and return them with their seq. Must run inside the
     * command transaction. Ids are unique: a clash means the caller skipped the
     * duplicate check, so it surfaces as a database error rather than being hidden.
     *
     * @param  list<Event>  $events
     * @return list<StoredEvent>
     */
    public function append(array $events, ?string $batchId = null): array
    {
        $stored = [];
        $receivedAt = CarbonImmutable::now();
        foreach ($events as $event) {
            $seq = $this->db->table('events')->insertGetId([
                'id' => $event->id,
                'site_id' => $event->siteId,
                'type' => $event->type,
                'version' => $event->version,
                'actor_id' => $event->actorId,
                'device_id' => $event->deviceId,
                'occurred_at' => $event->occurredAt,
                'received_at' => $receivedAt,
                'payload' => json_encode($event->payload, JSON_THROW_ON_ERROR),
                'corrects_event_id' => $event->correctsEventId,
                'reason' => $event->reason,
                'batch_id' => $batchId,
            ], 'seq');
            $stored[] = new StoredEvent($event, (int) $seq, $receivedAt, $batchId);
        }

        return $stored;
    }

    public function find(string $id): ?StoredEvent
    {
        $row = $this->db->table('events')->where('id', $id)->first();

        /* @phpstan-ignore argument.type */
        return $row === null ? null : StoredEvent::fromRow($row);
    }

    /** Events in append order, streamed in chunks. */
    public function stream(int $fromSeq = 0, int $chunk = 1000): Generator
    {
        $cursor = $fromSeq;
        while (true) {
            $rows = $this->db->table('events')->where('seq', '>', $cursor)->orderBy('seq')->limit($chunk)->get();
            if ($rows->isEmpty()) {
                return;
            }
            foreach ($rows as $row) {
                /* @phpstan-ignore argument.type */
                $event = StoredEvent::fromRow($row);
                $cursor = $event->seq;
                yield $event;
            }
        }
    }

    public function lastSeq(): int
    {
        return (int) $this->db->table('events')->max('seq');
    }
}
