<?php

declare(strict_types=1);

namespace Tanaad\Messaging\Projectors;

use Illuminate\Database\ConnectionInterface;
use Tanaad\Shared\Ledger\Projector;
use Tanaad\Shared\Ledger\StoredEvent;

/** The only writer of `outbound_messages`. */
final class MessageProjector implements Projector
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function handles(): array
    {
        return ['messaging.queued', 'messaging.delivered', 'messaging.failed'];
    }

    public function apply(StoredEvent $event): void
    {
        $p = $event->payload();
        $at = $event->event->occurredAt;
        if ($event->type() === 'messaging.queued') {
            $this->db->table('outbound_messages')->updateOrInsert(['id' => $p['message_id']], [
                'site_id' => $event->siteId(), 'user_id' => $p['user_id'], 'to_phone' => $p['to'],
                'source_event_id' => $p['source_event_id'], 'source_type' => $p['source_type'],
                'template' => $p['template'] ?? null, 'language' => $p['language'] ?? null,
                'params' => json_encode($p['params'] ?? [], JSON_THROW_ON_ERROR), 'body' => $p['body'],
                'status' => 'queued', 'attempts' => 0, 'queued_at' => $at,
                'event_id' => $event->id(), 'seq' => $event->seq, 'created_at' => $event->receivedAt, 'updated_at' => $event->receivedAt,
            ]);

            return;
        }
        $row = $this->db->table('outbound_messages')->where('id', $p['message_id']);
        if ($event->type() === 'messaging.delivered') {
            $row->update([
                'status' => $p['status'], 'attempts' => $p['attempt'], 'driver' => $p['driver'] ?? null, 'provider_id' => $p['provider_id'] ?? null,
                'last_error' => null, 'settled_at' => $at, 'seq' => $event->seq, 'updated_at' => $event->receivedAt,
            ]);

            return;
        }
        $final = (bool) ($p['final'] ?? false);
        $row->update([
            'status' => $final ? 'failed' : 'retrying', 'attempts' => $p['attempt'], 'last_error' => mb_substr((string) $p['error'], 0, 500),
            'settled_at' => $final ? $at : null, 'seq' => $event->seq, 'updated_at' => $event->receivedAt,
        ]);
    }

    public function reset(): void
    {
        $this->db->table('outbound_messages')->delete();
    }
}
