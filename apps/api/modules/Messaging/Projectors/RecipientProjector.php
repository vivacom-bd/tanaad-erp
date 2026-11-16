<?php

declare(strict_types=1);

namespace Tanaad\Messaging\Projectors;

use Illuminate\Database\ConnectionInterface;
use Tanaad\Shared\Ledger\Projector;
use Tanaad\Shared\Ledger\StoredEvent;

/** The only writer of `message_recipients`. */
final class RecipientProjector implements Projector
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function handles(): array
    {
        return ['messaging.recipient_saved'];
    }

    public function apply(StoredEvent $event): void
    {
        $p = $event->payload();
        $this->db->table('message_recipients')->updateOrInsert(['user_id' => $p['user_id']], [
            'phone' => $p['phone'], 'opted_out' => (bool) $p['opted_out'], 'seq' => $event->seq,
            'created_at' => $event->receivedAt, 'updated_at' => $event->receivedAt,
        ]);
    }

    public function reset(): void
    {
        $this->db->table('message_recipients')->delete();
    }
}
