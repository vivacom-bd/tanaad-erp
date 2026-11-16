<?php

declare(strict_types=1);

namespace Tanaad\Dispatch\Projectors;

use Illuminate\Database\ConnectionInterface;
use Tanaad\Shared\Ledger\Projector;
use Tanaad\Shared\Ledger\StoredEvent;

/** The only writer of dispatches, dispatch_bundles and delivery_notes. Every value comes from the payload. */
final class DispatchProjector implements Projector
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function handles(): array
    {
        return ['dispatch.started', 'dispatch.bundle_scanned', 'dispatch.bundle_unscanned', 'dispatch.gate_weighed',
            'dispatch.gate_passed', 'dispatch.gate_held', 'dispatch.gate_overridden', 'dispatch.delivered'];
    }

    public function apply(StoredEvent $event): void
    {
        $site = $event->siteId();
        if ($site === null) {
            return;
        }
        $p = $event->payload();
        $id = (string) ($p['dispatch_id'] ?? '');
        $at = $event->event->occurredAt;
        $touch = ['seq' => $event->seq, 'updated_at' => $event->receivedAt];
        $q = fn () => $this->db->table('dispatches')->where('id', $id);

        match ($event->type()) {
            'dispatch.started' => $this->db->table('dispatches')->insertOrIgnore([
                'id' => $id, 'site_id' => $site, 'order_ref' => $p['order']['order_ref'], 'client_name' => $p['order']['client_name'],
                'lines' => json_encode($p['order']['lines'], JSON_THROW_ON_ERROR), 'ordered_bundles' => $p['ordered_bundles'],
                'expected_kg' => $p['expected_kg'], 'driver' => $p['driver'], 'vehicle' => $p['vehicle'], 'gate_status' => 'pending',
                'event_id' => $event->id(), 'created_by' => $event->event->actorId, 'at' => $at, 'created_at' => $event->receivedAt, ...$touch,
            ]),
            'dispatch.bundle_scanned' => $this->scanned($site, $id, $p, $event),
            'dispatch.bundle_unscanned' => $this->unscanned($id, $p, $event),
            'dispatch.gate_weighed' => $q()->update([
                'gate_status' => $p['decision'], 'gate_kg' => $p['kg'], 'variance_pct' => $p['variance_pct'], 'tolerance_pct' => $p['tolerance_pct'],
                'gate_reasons' => json_encode($p['reasons'], JSON_THROW_ON_ERROR), 'weighed_by' => $event->event->actorId, 'weighed_at' => $at, ...$touch,
            ]),
            'dispatch.gate_held' => $q()->update($touch),
            'dispatch.gate_passed' => $this->released($site, $id, (string) $p['delivery_note_no'], 'pass', [], $event),
            'dispatch.gate_overridden' => $this->released($site, $id, (string) $p['delivery_note_no'], 'override', [
                'gate_status' => 'overridden', 'override_by' => $event->event->actorId, 'override_reason' => $p['reason'],
            ], $event),
            'dispatch.delivered' => $q()->update([
                'received_by' => $p['received_by'], 'bundles_received' => $p['bundles_received'], 'discrepancy' => $p['discrepancy'],
                'signature_hash' => $p['signature_hash'] ?? null, 'photo_hash' => $p['photo_hash'] ?? null, 'delivered_at' => $at, ...$touch,
            ]),
            default => null,
        };
    }

    public function reset(): void
    {
        $this->db->table('delivery_notes')->delete();
        $this->db->table('dispatch_bundles')->delete();
        $this->db->table('dispatches')->delete();
    }

    /** @param array<string, mixed> $p */
    private function scanned(string $site, string $id, array $p, StoredEvent $event): void
    {
        $this->db->table('dispatch_bundles')->insertOrIgnore([
            'dispatch_id' => $id, 'site_id' => $site, 'label_id' => $p['label_id'], 'code' => $p['code'], 'product_id' => $p['product_id'] ?? null,
            'scanned_by' => $event->event->actorId, 'event_id' => $event->id(), 'at' => $event->event->occurredAt, 'seq' => $event->seq,
        ]);
        $this->db->table('dispatches')->where('id', $id)->update(['seq' => $event->seq, 'updated_at' => $event->receivedAt]);
    }

    /** @param array<string, mixed> $p */
    private function unscanned(string $id, array $p, StoredEvent $event): void
    {
        $this->db->table('dispatch_bundles')->where(['dispatch_id' => $id, 'label_id' => $p['label_id']])->delete();
        $this->db->table('dispatches')->where('id', $id)->update(['seq' => $event->seq, 'updated_at' => $event->receivedAt]);
    }

    /** @param array<string, mixed> $extra */
    private function released(string $site, string $id, string $no, string $via, array $extra, StoredEvent $event): void
    {
        $this->db->table('dispatches')->where('id', $id)->update([
            'delivery_note_no' => $no, 'gate_passed_at' => $event->event->occurredAt, ...$extra, 'seq' => $event->seq, 'updated_at' => $event->receivedAt,
        ]);
        $this->db->table('delivery_notes')->insertOrIgnore([
            'dispatch_id' => $id, 'site_id' => $site, 'number' => $no, 'via' => $via, 'issued_by' => $event->event->actorId,
            'event_id' => $event->id(), 'at' => $event->event->occurredAt, 'seq' => $event->seq,
        ]);
    }
}
