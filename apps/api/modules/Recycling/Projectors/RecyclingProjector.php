<?php

declare(strict_types=1);

namespace Tanaad\Recycling\Projectors;

use Illuminate\Database\ConnectionInterface;
use Tanaad\Shared\Ledger\Projector;
use Tanaad\Shared\Ledger\StoredEvent;

/** The only writer of collector_deliveries. Idempotent by delivery id; a paid delivery stays paid. */
final class RecyclingProjector implements Projector
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function handles(): array
    {
        return ['recycling.collector_intake_recorded', 'recycling.collector_paid'];
    }

    public function apply(StoredEvent $event): void
    {
        $site = $event->siteId();
        if ($site === null) {
            return;
        }
        $p = $event->payload();
        match ($event->type()) {
            'recycling.collector_intake_recorded' => $this->db->table('collector_deliveries')->insertOrIgnore([
                'id' => $p['delivery_id'], 'site_id' => $site, 'day' => $p['day'], 'collector' => $p['collector'],
                'gross_kg' => $p['gross_kg'], 'tare_kg' => $p['tare_kg'], 'deduction_pct' => $p['deduction_pct'],
                'deduction_preset_id' => $p['deduction_preset_id'] ?? null, 'net_kg' => $p['net_kg'],
                'price_per_kg' => $p['price_per_kg'], 'amount' => $p['amount'], 'currency' => $p['currency'] ?? 'UGX',
                'has_scale_photo' => (bool) ($p['has_scale_photo'] ?? false), 'scale_photo_hash' => $p['scale_photo_hash'] ?? null,
                'voucher_no' => $p['voucher_no'], 'provisional_no' => $p['provisional_no'] ?? null,
                'paid' => false, 'paid_at' => null, 'paid_day' => null, 'paid_amount' => null, 'payment_id' => null, 'paid_by' => null,
                'event_id' => $event->id(), 'recorded_by' => $event->event->actorId, 'at' => $event->event->occurredAt,
                'seq' => $event->seq, 'created_at' => $event->receivedAt, 'updated_at' => $event->receivedAt,
            ]),
            'recycling.collector_paid' => $this->db->table('collector_deliveries')
                ->where(['id' => $p['delivery_id'], 'site_id' => $site, 'paid' => false])->update([
                    'paid' => true, 'paid_at' => $event->event->occurredAt, 'paid_day' => $p['day'], 'paid_amount' => $p['amount'],
                    'payment_id' => $p['payment_id'], 'paid_by' => $event->event->actorId,
                    'seq' => $event->seq, 'updated_at' => $event->receivedAt,
                ]),
            default => null,
        };
    }

    public function reset(): void
    {
        $this->db->table('collector_deliveries')->delete();
    }
}
