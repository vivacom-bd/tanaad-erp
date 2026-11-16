<?php

declare(strict_types=1);

namespace Tanaad\Group\Projectors;

use Illuminate\Database\ConnectionInterface;
use Tanaad\Shared\Ledger\Projector;
use Tanaad\Shared\Ledger\StoredEvent;

/** The only writer of `briefs`. */
final class BriefProjector implements Projector
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function handles(): array
    {
        return ['group.brief_sent'];
    }

    public function apply(StoredEvent $event): void
    {
        $p = $event->payload();
        $this->db->table('briefs')->insert([
            'id' => $p['brief_id'], 'site_id' => null, 'day' => $p['day'], 'channel' => $p['channel'],
            'recipient_ids' => json_encode($p['recipient_ids'] ?? [], JSON_THROW_ON_ERROR),
            'lines' => json_encode($p['lines'], JSON_THROW_ON_ERROR),
            'sent_by' => $event->event->actorId, 'sent_at' => $event->event->occurredAt,
            'event_id' => $event->id(), 'seq' => $event->seq, 'created_at' => $event->receivedAt, 'updated_at' => $event->receivedAt,
        ]);
    }

    public function reset(): void
    {
        $this->db->table('briefs')->delete();
    }
}
