<?php

declare(strict_types=1);

namespace Tanaad\Spares\Projectors;

use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Tanaad\Shared\Ledger\Projector;
use Tanaad\Shared\Ledger\StoredEvent;
use Tanaad\Spares\Support\Spares;

/**
 * The only writer of parts, part_receipts, part_issues and breakdowns. Idempotent by id: stock moves
 * only when the receipt/issue row is newly inserted, so a re-applied event changes nothing.
 */
final class SparesProjector implements Projector
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function handles(): array
    {
        return ['spares.part_saved', 'spares.part_received', 'spares.part_issued', 'spares.breakdown_reported', 'spares.breakdown_resolved'];
    }

    public function apply(StoredEvent $event): void
    {
        $site = $event->siteId();
        if ($site === null) {
            return;
        }
        $p = $event->payload();
        match ($event->type()) {
            'spares.part_saved' => $this->saved($site, $p, $event),
            'spares.part_received' => $this->received($site, $p, $event),
            'spares.part_issued' => $this->issued($site, $p, $event),
            'spares.breakdown_reported' => $this->reported($site, $p, $event),
            'spares.breakdown_resolved' => $this->resolved($site, $p, $event),
            default => null,
        };
    }

    public function reset(): void
    {
        foreach (['breakdowns', 'part_issues', 'part_receipts', 'parts'] as $t) {
            $this->db->table($t)->delete();
        }
    }

    /** @param array<string, mixed> $p */
    private function saved(string $site, array $p, StoredEvent $event): void
    {
        $fields = [
            'code' => $p['code'], 'name' => $p['name'], 'unit' => $p['unit'], 'min_level' => $p['min_level'], 'unit_cost' => $p['unit_cost'],
            'machine_kinds' => json_encode($p['machine_kinds'] ?? [], JSON_THROW_ON_ERROR), 'active' => (bool) ($p['active'] ?? true),
            'seq' => $event->seq, 'updated_at' => $event->receivedAt,
        ];
        $this->db->table('parts')->insertOrIgnore(['id' => $p['part_id'], 'site_id' => $site, 'on_hand' => 0, 'created_at' => $event->receivedAt] + $fields);
        $this->db->table('parts')->where(['id' => $p['part_id'], 'site_id' => $site])->where('seq', '<', $event->seq)->update($fields);
    }

    /** @param array<string, mixed> $p */
    private function received(string $site, array $p, StoredEvent $event): void
    {
        $new = $this->db->table('part_receipts')->insertOrIgnore([
            'id' => $p['receipt_id'], 'site_id' => $site, 'part_id' => $p['part_id'], 'qty' => $p['qty'], 'unit_cost' => $p['unit_cost'],
            'supplier' => $p['supplier'] ?? null, 'event_id' => $event->id(), 'received_by' => $event->event->actorId,
            'at' => $event->event->occurredAt, 'seq' => $event->seq, 'created_at' => $event->receivedAt, 'updated_at' => $event->receivedAt,
        ]);
        if ($new > 0) {
            $this->db->table('parts')->where(['id' => $p['part_id'], 'site_id' => $site])->update([
                'on_hand' => $this->add('parts', $p['part_id'], 'on_hand', $p['qty']), 'unit_cost' => $p['avg_cost_after'],
                'seq' => $event->seq, 'updated_at' => $event->receivedAt,
            ]);
        }
    }

    /** @param array<string, mixed> $p */
    private function issued(string $site, array $p, StoredEvent $event): void
    {
        $new = $this->db->table('part_issues')->insertOrIgnore([
            'id' => $p['issue_id'], 'site_id' => $site, 'part_id' => $p['part_id'], 'machine_id' => $p['machine_id'],
            'mechanic_id' => $p['mechanic_id'] ?? null, 'breakdown_id' => $p['breakdown_id'] ?? null, 'qty' => $p['qty'],
            'unit_cost' => $p['unit_cost'], 'cost' => $p['cost'], 'reason' => $p['reason'] ?? null, 'event_id' => $event->id(),
            'issued_by' => $event->event->actorId, 'at' => $event->event->occurredAt, 'seq' => $event->seq,
            'created_at' => $event->receivedAt, 'updated_at' => $event->receivedAt,
        ]);
        if ($new > 0) {
            $this->db->table('parts')->where(['id' => $p['part_id'], 'site_id' => $site])->update([
                'on_hand' => $this->add('parts', $p['part_id'], 'on_hand', -(float) $p['qty']), 'seq' => $event->seq, 'updated_at' => $event->receivedAt,
            ]);
            if (is_string($p['breakdown_id'] ?? null)) {
                $this->db->table('breakdowns')->where(['id' => $p['breakdown_id'], 'site_id' => $site])->whereNull('resolved_at')->update([
                    'spares_cost' => $this->add('breakdowns', $p['breakdown_id'], 'spares_cost', $p['cost']), 'updated_at' => $event->receivedAt,
                ]);
            }
        }
    }

    /** @param array<string, mixed> $p */
    private function reported(string $site, array $p, StoredEvent $event): void
    {
        $this->db->table('breakdowns')->insertOrIgnore([
            'id' => $p['breakdown_id'], 'site_id' => $site, 'machine_id' => $p['machine_id'], 'cause' => $p['cause'],
            'started_at' => self::at((string) $p['started_at']), 'spares_cost' => 0, 'reported_by' => $event->event->actorId,
            'event_id' => $event->id(), 'seq' => $event->seq, 'created_at' => $event->receivedAt, 'updated_at' => $event->receivedAt,
        ]);
    }

    /** @param array<string, mixed> $p */
    private function resolved(string $site, array $p, StoredEvent $event): void
    {
        $this->db->table('breakdowns')->where(['id' => $p['breakdown_id'], 'site_id' => $site])->whereNull('resolved_at')->update([
            'resolved_at' => self::at((string) $p['resolved_at']), 'duration_minutes' => $p['duration_minutes'],
            'spares_cost' => $p['spares_cost'], 'resolved_by' => $event->event->actorId, 'seq' => $event->seq, 'updated_at' => $event->receivedAt,
        ]);
    }

    /** The column plus a delta, in exact decimals (the row is locked by the command, or replay is single-threaded). */
    private function add(string $table, mixed $id, string $column, mixed $delta): string
    {
        $current = (string) $this->db->table($table)->where('id', $id)->value($column);

        return BigDecimal::of($current === '' ? '0' : $current)->plus(Spares::dec(is_numeric($delta) ? $delta : 0))->toString();
    }

    private static function at(string $iso): string
    {
        return CarbonImmutable::parse($iso)->utc()->format('Y-m-d H:i:s.u');
    }
}
