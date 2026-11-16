<?php

declare(strict_types=1);

namespace Tanaad\Production\Projectors;

use Illuminate\Database\ConnectionInterface;
use Tanaad\Shared\Ledger\Projector;
use Tanaad\Shared\Ledger\StoredEvent;

/** The only writer of production_entries, production_batches, production_shifts_closed and waste_weighings. */
final class ProductionProjector implements Projector
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function handles(): array
    {
        return ['production.entry_recorded', 'production.batch_recorded', 'production.shift_closed', 'production.waste_weighed'];
    }

    public function apply(StoredEvent $event): void
    {
        $site = $event->siteId();
        if ($site === null) {
            return;
        }
        $p = $event->payload();
        $tail = ['event_id' => $event->id(), 'recorded_by' => $event->event->actorId, 'at' => $event->event->occurredAt,
            'seq' => $event->seq, 'updated_at' => $event->receivedAt];

        match ($event->type()) {
            'production.entry_recorded' => $this->entry($site, $p, $tail, $event),
            'production.batch_recorded' => $this->db->table('production_batches')->insertOrIgnore([
                'id' => $p['batch_id'], 'site_id' => $site, 'day' => $p['day'], 'shift_id' => $p['shift_id'] ?? null,
                'machine_id' => $p['machine_id'] ?? null, 'product_id' => $p['product_id'] ?? null,
                'lines' => json_encode($p['lines'] ?? [], JSON_THROW_ON_ERROR), 'input_kg' => $p['input_kg'], 'output_kg' => $p['output_kg'],
                'label_code' => $p['label_code'] ?? null, 'note' => $p['note'] ?? null, ...$tail, 'created_at' => $event->receivedAt,
            ]),
            'production.shift_closed' => $this->db->table('production_shifts_closed')->insertOrIgnore([
                'id' => $p['shift_id'], 'site_id' => $site, 'day' => $p['day'], 'shift_kind' => $p['shift_kind'],
                'unlogged_machine_ids' => json_encode($p['unlogged_machine_ids'] ?? [], JSON_THROW_ON_ERROR), ...$tail, 'created_at' => $event->receivedAt,
            ]),
            'production.waste_weighed' => $this->db->table('waste_weighings')->insertOrIgnore([
                'id' => $p['waste_id'], 'site_id' => $site, 'day' => $p['day'], 'stage_code' => $p['stage_code'], 'waste_kind' => $p['waste_kind'],
                'kg' => $p['kg'], 'reason' => $p['reason'] ?? null, 'machine_id' => $p['machine_id'] ?? null, ...$tail, 'created_at' => $event->receivedAt,
            ]),
            default => null,
        };
    }

    public function reset(): void
    {
        foreach (['production_entries', 'production_batches', 'production_shifts_closed', 'waste_weighings'] as $t) {
            $this->db->table($t)->delete();
        }
    }

    /**
     * Upsert per (shift, machine, stage); the id stays the first entry's. Re-applying the same event is a no-op.
     *
     * @param  array<string, mixed>  $p
     * @param  array<string, mixed>  $tail
     */
    private function entry(string $site, array $p, array $tail, StoredEvent $event): void
    {
        $key = ['shift_id' => $p['shift_id'], 'machine_id' => $p['machine_id'], 'stage_code' => $p['stage_code']];
        $current = $this->db->table('production_entries')->where($key)->first(['id', 'seq']);
        if ($current !== null && (int) $current->seq >= $event->seq) {
            return;
        }
        $row = [
            'site_id' => $site, 'day' => $p['day'], 'shift_kind' => $p['shift_kind'],
            'product_id' => $p['product_id'] ?? null, 'operator_id' => $p['operator_id'] ?? null,
            'good_units' => $p['good_units'], 'reject_units' => $p['reject_units'], 'input_kg' => $p['input_kg'], 'output_kg' => $p['output_kg'],
            'downtime_min' => $p['downtime_min'], 'downtime_reason' => $p['downtime_reason'] ?? null,
            'label_codes' => json_encode($p['label_codes'] ?? [], JSON_THROW_ON_ERROR),
            'benchmark' => $p['benchmark'] ?? null, 'below_benchmark' => (bool) ($p['below_benchmark'] ?? false), ...$tail,
        ];
        if ($current === null) {
            $this->db->table('production_entries')->insert(['id' => $p['entry_id'], ...$key, ...$row, 'created_at' => $event->receivedAt]);
        } else {
            $this->db->table('production_entries')->where('id', $current->id)->update($row);
        }
    }
}
