<?php

declare(strict_types=1);

namespace Tanaad\Identity\Projectors;

use Illuminate\Database\ConnectionInterface;
use Ramsey\Uuid\Uuid;
use Tanaad\Shared\Ledger\Projector;
use Tanaad\Shared\Ledger\StoredEvent;

/**
 * Devices and role grants are projections of identity.* events. Users themselves are not
 * ledgered (credentials never go in the ledger); only these audited changes are.
 */
final class IdentityProjector implements Projector
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function handles(): array
    {
        return ['identity.device_registered', 'identity.role_assigned', 'identity.session_opened'];
    }

    public function apply(StoredEvent $event): void
    {
        $p = $event->payload();
        $now = $event->receivedAt;
        match ($event->type()) {
            'identity.device_registered' => $this->db->table('devices')->insert([
                'id' => $p['device_id'], 'site_id' => $event->siteId(), 'name' => $p['name'], 'kind' => $p['kind'],
                'registered_by' => $event->event->actorId, 'active' => true, 'seq' => $event->seq, 'created_at' => $now, 'updated_at' => $now,
            ]),
            'identity.role_assigned' => $this->db->table('user_site_roles')->upsert(
                [['id' => Uuid::uuid5(Uuid::NAMESPACE_URL, 'tanaad:user_site_roles:'.$p['user_id'].'|'.($p['role_site_id'] ?? '').'|'.$p['role'])->toString(), 'user_id' => $p['user_id'], 'site_id' => $p['role_site_id'], 'role' => $p['role'], 'seq' => $event->seq, 'deleted_at' => null, 'created_at' => $now, 'updated_at' => $now]],
                ['user_id', 'site_key', 'role'],
                ['seq', 'deleted_at', 'updated_at'],
            ),
            'identity.session_opened' => $p['device_id'] === null ? null : $this->db->table('devices')->where('id', $p['device_id'])
                ->update(['last_seen_at' => $event->event->occurredAt, 'seq' => $event->seq]),
            default => null,
        };
    }

    public function reset(): void
    {
        // Only ledgered rows: a grant written outside the ledger (seq 0) has no event to rebuild it from.
        $this->db->table('devices')->where('seq', '>', 0)->delete();
        $this->db->table('user_site_roles')->where('seq', '>', 0)->delete();
    }
}
