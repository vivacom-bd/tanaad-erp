<?php

declare(strict_types=1);

namespace Tanaad\Purchases\Projectors;

use Illuminate\Database\ConnectionInterface;
use Tanaad\Shared\Ledger\Projector;
use Tanaad\Shared\Ledger\StoredEvent;

/** The only writer of purchases. Idempotent by purchase id; a received purchase stays received. */
final class PurchasesProjector implements Projector
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function handles(): array
    {
        return ['purchases.recorded', 'purchases.changed', 'purchases.received'];
    }

    public function apply(StoredEvent $event): void
    {
        $site = $event->siteId();
        if ($site === null) {
            return;
        }
        $p = $event->payload();
        $fields = fn (): array => [
            'supplier' => $p['supplier'], 'origin_country' => $p['origin_country'] ?? null, 'material' => $p['material'],
            'qty_kg' => $p['qty_kg'], 'unit_price' => $p['unit_price'], 'currency' => $p['currency'],
            'fx_reference' => $p['fx_reference'] ?? null, 'costs' => json_encode($p['costs'] ?? []), 'status' => $p['status'],
            'expected_on' => $p['expected_on'] ?? null, 'note' => $p['note'] ?? null,
        ];
        match ($event->type()) {
            'purchases.recorded' => $this->db->table('purchases')->insertOrIgnore([
                'id' => $p['purchase_id'], 'site_id' => $site, 'reference' => $p['reference'], ...$fields(),
                'ordered_on' => $p['ordered_on'], 'variance' => false, 'event_id' => $event->id(), 'recorded_by' => $event->event->actorId,
                'at' => $event->event->occurredAt, 'seq' => $event->seq, 'created_at' => $event->receivedAt, 'updated_at' => $event->receivedAt,
            ]),
            'purchases.changed' => $this->db->table('purchases')->where(['id' => $p['purchase_id'], 'site_id' => $site])
                ->where('status', '!=', 'received')->update([...$fields(), 'seq' => $event->seq, 'updated_at' => $event->receivedAt]),
            'purchases.received' => $this->db->table('purchases')->where(['id' => $p['purchase_id'], 'site_id' => $site])
                ->where('status', '!=', 'received')->update([
                    'status' => 'received', 'received_on' => $p['received_on'], 'received_kg' => $p['received_kg'], 'variance' => (bool) $p['variance'],
                    'goods_ugx' => $p['goods_ugx'], 'extras_ugx' => $p['extras_ugx'], 'total_ugx' => $p['total_ugx'],
                    'per_kg_ugx' => $p['per_kg_ugx'], 'uplift_pct' => $p['uplift_pct'], 'received_by' => $event->event->actorId,
                    'seq' => $event->seq, 'updated_at' => $event->receivedAt,
                ]),
            default => null,
        };
    }

    public function reset(): void
    {
        $this->db->table('purchases')->delete();
    }
}
