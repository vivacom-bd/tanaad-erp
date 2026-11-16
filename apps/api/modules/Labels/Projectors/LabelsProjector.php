<?php

declare(strict_types=1);

namespace Tanaad\Labels\Projectors;

use Illuminate\Database\ConnectionInterface;
use Tanaad\Labels\Support\LabelBook;
use Tanaad\Shared\Ledger\Projector;
use Tanaad\Shared\Ledger\StoredEvent;

/**
 * The only writer of labels and label_history. Also listens, by type string only, to the
 * stock.* / dispatch.* events later phases emit about a label (payload label_id or code):
 * each is appended to the history and may move the label's status or zone.
 */
final class LabelsProjector implements Projector
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function handles(): array
    {
        return ['labels.created', 'labels.consumed', ...array_keys(LabelBook::HOOKS)];
    }

    public function apply(StoredEvent $event): void
    {
        $site = $event->siteId();
        if ($site === null) {
            return;
        }
        $p = $event->payload();
        match ($event->type()) {
            'labels.created' => $this->create($site, $p, $event),
            'labels.consumed' => $this->consume($p, $event),
            default => $this->hook($site, $p, $event),
        };
    }

    public function reset(): void
    {
        $this->db->table('label_history')->delete();
        $this->db->table('labels')->delete();
    }

    /** @param array<string, mixed> $p */
    private function create(string $site, array $p, StoredEvent $event): void
    {
        $now = $event->receivedAt;
        $this->db->table('labels')->insertOrIgnore([
            'id' => $p['label_id'], 'site_id' => $site, 'code' => $p['code'], 'kind' => $p['kind'],
            'product_id' => $p['product_id'] ?? null, 'stage_code' => $p['stage_code'],
            'machine_id' => $p['machine_id'] ?? null, 'operator_id' => $p['operator_id'] ?? null,
            'shift_kind' => $p['shift_kind'] ?? null, 'day' => $p['day'],
            'qty_units' => $p['qty_units'], 'qty_kg' => $p['qty_kg'], 'status' => $p['status'],
            'zone_id' => $p['zone_id'] ?? null, 'parent_ids' => json_encode($p['parent_ids'] ?? [], JSON_THROW_ON_ERROR),
            'colour' => $p['colour'] ?? null, 'grade' => $p['grade'] ?? null,
            'event_id' => $event->id(), 'created_by' => $event->event->actorId, 'at' => $event->event->occurredAt,
            'seq' => $event->seq, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->history((string) $p['label_id'], $site, 'created', [
            'stage_code' => $p['stage_code'], 'machine_id' => $p['machine_id'] ?? null, 'operator_id' => $p['operator_id'] ?? null,
        ], $event);
    }

    /** @param array<string, mixed> $p */
    private function consume(array $p, StoredEvent $event): void
    {
        $site = (string) $event->siteId();
        $this->db->table('labels')->where('id', $p['label_id'])->update([
            'status' => 'consumed', 'consumed_by_stage' => $p['consumed_by_stage'] ?? null, 'consumed_ref' => $p['consumed_ref'] ?? null,
            'seq' => $event->seq, 'updated_at' => $event->receivedAt,
        ]);
        $this->history((string) $p['label_id'], $site, 'consumed', [
            'consumed_by_stage' => $p['consumed_by_stage'] ?? null, 'consumed_ref' => $p['consumed_ref'] ?? null,
        ], $event);
    }

    /** @param array<string, mixed> $p */
    private function hook(string $site, array $p, StoredEvent $event): void
    {
        $q = $this->db->table('labels')->where('site_id', $site);
        if (is_string($p['label_id'] ?? null)) {
            $q->where('id', $p['label_id']);
        } elseif (is_string($p['code'] ?? null)) {
            $q->where('code', $p['code']);
        } else {
            return;
        }
        $id = $q->value('id');
        if (! is_string($id)) {
            return;
        }
        $update = ['seq' => $event->seq, 'updated_at' => $event->receivedAt];
        $status = LabelBook::HOOKS[$event->type()] ?? null;
        if ($status !== null) {
            $update['status'] = $status;
        }
        if (is_string($p['zone_id'] ?? null)) {
            $update['zone_id'] = $p['zone_id'];
        }
        $this->db->table('labels')->where('id', $id)->update($update);
        $type = substr($event->type(), (int) strpos($event->type(), '_') + 1);
        $this->history($id, $site, $type, $p, $event);
    }

    /** @param array<string, mixed> $data */
    private function history(string $labelId, string $site, string $type, array $data, StoredEvent $event): void
    {
        $this->db->table('label_history')->insertOrIgnore([
            'label_id' => $labelId, 'site_id' => $site, 'type' => $type, 'data' => json_encode((object) $data, JSON_THROW_ON_ERROR),
            'event_id' => $event->id(), 'by' => $event->event->actorId, 'at' => $event->event->occurredAt, 'seq' => $event->seq,
        ]);
    }
}
