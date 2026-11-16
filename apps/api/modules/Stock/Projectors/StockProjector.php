<?php

declare(strict_types=1);

namespace Tanaad\Stock\Projectors;

use Brick\Math\BigDecimal;
use Illuminate\Database\ConnectionInterface;
use Tanaad\Shared\Ledger\DayLocks;
use Tanaad\Shared\Ledger\Projector;
use Tanaad\Shared\Ledger\StoredEvent;
use Tanaad\Stock\Support\StockBook;

/**
 * The only writer of stock_on_hand, stock_label_positions, stock_movements, stock_transfers,
 * stock_counts and the stock count locks. Label events from Labels and Dispatch are read by type
 * string only: Stock keeps its own position per label (product, zone, qty, in stock or not), so
 * nothing is read from the labels table at projection time. Every event writes one movement row
 * keyed by the event id; on-hand changes only when that row is new, so re-applying is a no-op.
 */
final class StockProjector implements Projector
{
    public function __construct(private readonly ConnectionInterface $db, private readonly DayLocks $locks) {}

    public function handles(): array
    {
        return [
            'labels.created', 'labels.consumed', 'dispatch.label_dispatched',
            'stock.received', 'stock.label_moved', 'stock.label_scrapped', 'stock.moved', 'stock.adjusted',
            'stock.transfer_sent', 'stock.transfer_received', 'stock.counted', 'stock.count_reopened',
        ];
    }

    public function apply(StoredEvent $event): void
    {
        $site = $event->siteId();
        if ($site === null) {
            return;
        }
        $p = $event->payload();
        match ($event->type()) {
            'labels.created' => $this->labelCreated($site, $p, $event),
            'labels.consumed' => $this->labelOut($site, $p, $event, 'consumed'),
            'dispatch.label_dispatched' => $this->labelOut($site, $p, $event, 'dispatch_out'),
            'stock.label_scrapped' => $this->labelOut($site, $p, $event, 'scrap'),
            'stock.label_moved' => $this->labelMoved($site, $p, $event),
            'stock.received' => $this->quantity($site, $p, $event, 'receive', null, (string) $p['zone_id'], 1),
            'stock.adjusted' => $this->quantity($site, $p, $event, 'adjust', null, (string) $p['zone_id'], 1),
            'stock.moved' => $this->quantity($site, $p, $event, 'transfer', (string) $p['from_zone_id'], (string) $p['to_zone_id'], 1),
            'stock.transfer_sent' => $this->transferSent($site, $p, $event),
            'stock.transfer_received' => $this->transferReceived($site, $p, $event),
            'stock.counted' => $this->counted($site, $p, $event),
            'stock.count_reopened' => $this->reopened($site, $p, $event),
            default => null,
        };
    }

    public function reset(): void
    {
        foreach (['stock_counts', 'stock_transfers', 'stock_movements', 'stock_label_positions', 'stock_on_hand'] as $t) {
            $this->db->table($t)->delete();
        }
        $this->db->table('day_locks')->where('ledger', 'like', StockBook::LEDGER.':%')->delete();
    }

    /** @param array<string, mixed> $p */
    private function labelCreated(string $site, array $p, StoredEvent $event): void
    {
        $inStock = ($p['status'] ?? null) === 'in_stock' && is_string($p['product_id'] ?? null);
        $this->db->table('stock_label_positions')->insertOrIgnore([
            'label_id' => $p['label_id'], 'site_id' => $site, 'code' => $p['code'], 'product_id' => $p['product_id'] ?? null,
            'zone_id' => $p['zone_id'] ?? null, 'qty_units' => StockBook::qty((string) $p['qty_units']), 'qty_kg' => StockBook::qty((string) $p['qty_kg']),
            'in_stock' => $inStock, 'seq' => $event->seq,
        ]);
        if (! $inStock) {
            return;
        }
        $q = ['bundles' => 1, 'qty_units' => StockBook::qty((string) $p['qty_units']), 'qty_kg' => StockBook::qty((string) $p['qty_kg'])];
        if ($this->movement($site, $event, 'production_in', ['label_id' => $p['label_id'], 'label_code' => $p['code'], 'product_id' => $p['product_id'], 'to_zone_id' => $p['zone_id'] ?? null, ...$q])) {
            $this->bump($site, (string) $p['product_id'], $p['zone_id'] ?? null, $q, 1, $event);
        }
    }

    /** @param array<string, mixed> $p */
    private function labelOut(string $site, array $p, StoredEvent $event, string $kind): void
    {
        $pos = $this->position($site, $p);
        if ($pos === null || ! (bool) $pos->in_stock || $pos->product_id === null) {
            return;
        }
        $q = ['bundles' => 1, 'qty_units' => StockBook::qty((string) $pos->qty_units), 'qty_kg' => StockBook::qty((string) $pos->qty_kg)];
        if (! $this->movement($site, $event, $kind, ['label_id' => $pos->label_id, 'label_code' => $pos->code, 'product_id' => $pos->product_id,
            'from_zone_id' => $pos->zone_id, ...$this->signed($q, -1), 'reason' => $p['reason'] ?? null])) {
            return;
        }
        $this->db->table('stock_label_positions')->where('label_id', $pos->label_id)->update(['in_stock' => false, 'seq' => $event->seq]);
        $this->bump($site, (string) $pos->product_id, $pos->zone_id, $q, -1, $event);
    }

    /** @param array<string, mixed> $p */
    private function labelMoved(string $site, array $p, StoredEvent $event): void
    {
        $pos = $this->position($site, $p);
        $to = $p['to_zone_id'] ?? $p['zone_id'] ?? null;
        if ($pos === null || ! is_string($to)) {
            return;
        }
        $q = ['bundles' => 1, 'qty_units' => StockBook::qty((string) $pos->qty_units), 'qty_kg' => StockBook::qty((string) $pos->qty_kg)];
        if (! $this->movement($site, $event, 'label_move', ['label_id' => $pos->label_id, 'label_code' => $pos->code, 'product_id' => $pos->product_id,
            'from_zone_id' => $pos->zone_id, 'to_zone_id' => $to, ...$q, 'reason' => $p['reason'] ?? null])) {
            return;
        }
        $this->db->table('stock_label_positions')->where('label_id', $pos->label_id)->update(['zone_id' => $to, 'seq' => $event->seq]);
        if ((bool) $pos->in_stock && $pos->product_id !== null) {
            $this->bump($site, (string) $pos->product_id, $pos->zone_id, $q, -1, $event);
            $this->bump($site, (string) $pos->product_id, $to, $q, 1, $event);
        }
    }

    /**
     * Receive / adjust (into $to, signed by the payload) and zone-to-zone moves ($from -> $to).
     *
     * @param  array<string, mixed>  $p
     */
    private function quantity(string $site, array $p, StoredEvent $event, string $kind, ?string $from, string $to, int $sign): void
    {
        $q = self::q($p);
        if (! $this->movement($site, $event, $kind, ['product_id' => $p['product_id'], 'from_zone_id' => $from, 'to_zone_id' => $to,
            ...$q, 'reason' => $p['reason'] ?? null, 'cause' => $p['cause'] ?? null, 'count_id' => $p['count_id'] ?? null])) {
            return;
        }
        if ($from !== null) {
            $this->bump($site, (string) $p['product_id'], $from, $q, -$sign, $event);
        }
        $this->bump($site, (string) $p['product_id'], $to, $q, $sign, $event);
    }

    /** @param array<string, mixed> $p */
    private function transferSent(string $site, array $p, StoredEvent $event): void
    {
        $q = self::q($p);
        if (! $this->movement($site, $event, 'transfer_out', ['product_id' => $p['product_id'], 'from_zone_id' => $p['from_zone_id'],
            'from_site_id' => $site, 'to_site_id' => $p['to_site_id'], 'transfer_id' => $p['transfer_id'], ...$this->signed($q, -1), 'reason' => $p['note'] ?? null])) {
            return;
        }
        $this->db->table('stock_transfers')->insert([
            'id' => $p['transfer_id'], 'site_id' => $site, 'to_site_id' => $p['to_site_id'], 'product_id' => $p['product_id'],
            'from_zone_id' => $p['from_zone_id'], ...$q, 'status' => 'sent', 'note' => $p['note'] ?? null,
            'sent_by' => $event->event->actorId, 'sent_at' => $event->event->occurredAt, 'seq' => $event->seq,
            'created_at' => $event->receivedAt, 'updated_at' => $event->receivedAt,
        ]);
        $this->bump($site, (string) $p['product_id'], (string) $p['from_zone_id'], $q, -1, $event);
    }

    /** @param array<string, mixed> $p */
    private function transferReceived(string $site, array $p, StoredEvent $event): void
    {
        $q = self::q($p);
        if (! $this->movement($site, $event, 'transfer_in', ['product_id' => $p['product_id'], 'to_zone_id' => $p['to_zone_id'],
            'from_site_id' => $p['from_site_id'], 'to_site_id' => $site, 'transfer_id' => $p['transfer_id'], ...$q, 'reason' => $p['note'] ?? null])) {
            return;
        }
        $this->db->table('stock_transfers')->where('id', $p['transfer_id'])->update([
            'status' => 'received', 'received_product_id' => $p['product_id'], 'to_zone_id' => $p['to_zone_id'], 'received_bundles' => $q['bundles'], 'received_units' => $q['qty_units'],
            'received_kg' => $q['qty_kg'], 'discrepancy_bundles' => $p['discrepancy_bundles'], 'discrepancy_units' => $p['discrepancy_units'],
            'discrepancy_kg' => $p['discrepancy_kg'], 'receive_note' => $p['note'] ?? null, 'received_by' => $event->event->actorId,
            'received_at' => $event->event->occurredAt, 'seq' => $event->seq, 'updated_at' => $event->receivedAt,
        ]);
        $this->bump($site, (string) $p['product_id'], (string) $p['to_zone_id'], $q, 1, $event);
    }

    /** @param array<string, mixed> $p */
    private function counted(string $site, array $p, StoredEvent $event): void
    {
        $this->db->table('stock_counts')->insertOrIgnore([
            'id' => $p['count_id'], 'site_id' => $site, 'zone_id' => $p['zone_id'], 'product_id' => $p['product_id'], 'day' => $p['day'],
            'counted_bundles' => $p['counted_bundles'], 'system_bundles' => $p['system_bundles'], 'variance' => $p['variance'],
            'reason' => $p['reason'] ?? null, 'status' => 'locked', 'ledger' => $p['ledger'], 'event_id' => $event->id(),
            'by' => $event->event->actorId, 'at' => $event->event->occurredAt, 'seq' => $event->seq,
            'created_at' => $event->receivedAt, 'updated_at' => $event->receivedAt,
        ]);
        $this->locks->lock($site, (string) $p['ledger'], (string) $p['day'], $event->id());
    }

    /** @param array<string, mixed> $p */
    private function reopened(string $site, array $p, StoredEvent $event): void
    {
        $this->db->table('stock_counts')->where(['site_id' => $site, 'ledger' => $p['ledger'], 'day' => $p['day'], 'status' => 'locked'])
            ->update(['status' => 'reopened', 'reopen_reason' => $p['reason'] ?? null, 'seq' => $event->seq, 'updated_at' => $event->receivedAt]);
        $this->locks->reopen($site, (string) $p['ledger'], (string) $p['day'], $event->id());
    }

    /** @param array<string, mixed> $data */
    private function movement(string $site, StoredEvent $event, string $kind, array $data): bool
    {
        return $this->db->table('stock_movements')->insertOrIgnore([
            'id' => $event->id(), 'site_id' => $site, 'kind' => $kind, ...$data,
            'by' => $event->event->actorId, 'at' => $event->event->occurredAt, 'seq' => $event->seq,
            'created_at' => $event->receivedAt, 'updated_at' => $event->receivedAt,
        ]) === 1;
    }

    /** @param array{bundles: int, qty_units: string, qty_kg: string} $q */
    private function bump(string $site, string $productId, ?string $zoneId, array $q, int $sign, StoredEvent $event): void
    {
        $id = StockBook::onHandId($site, $productId, $zoneId);
        $this->db->table('stock_on_hand')->insertOrIgnore([
            'id' => $id, 'site_id' => $site, 'product_id' => $productId, 'zone_id' => $zoneId,
            'bundles' => 0, 'pieces' => '0', 'kg' => '0', 'seq' => $event->seq, 'created_at' => $event->receivedAt, 'updated_at' => $event->receivedAt,
        ]);
        $s = $this->signed($q, $sign);
        $row = $this->db->table('stock_on_hand')->where('id', $id)->first(['bundles', 'pieces', 'kg']);
        if (! $row instanceof \stdClass) {
            return;
        }
        $this->db->table('stock_on_hand')->where('id', $id)->update([
            'bundles' => (int) $row->bundles + $s['bundles'],
            'pieces' => (string) BigDecimal::of((string) $row->pieces)->plus($s['qty_units'])->toScale(3),
            'kg' => (string) BigDecimal::of((string) $row->kg)->plus($s['qty_kg'])->toScale(3),
            'seq' => $event->seq, 'updated_at' => $event->receivedAt,
        ]);
    }

    /**
     * @param  array{bundles: int, qty_units: string, qty_kg: string}  $q
     * @return array{bundles: int, qty_units: string, qty_kg: string}
     */
    private function signed(array $q, int $sign): array
    {
        return $sign >= 0 ? $q : ['bundles' => -$q['bundles'], 'qty_units' => StockBook::neg($q['qty_units']), 'qty_kg' => StockBook::neg($q['qty_kg'])];
    }

    /**
     * @param  array<string, mixed>  $p
     * @return array{bundles: int, qty_units: string, qty_kg: string}
     */
    private static function q(array $p): array
    {
        return ['bundles' => (int) ($p['bundles'] ?? 0), 'qty_units' => StockBook::qty((string) ($p['qty_units'] ?? 0)), 'qty_kg' => StockBook::qty((string) ($p['qty_kg'] ?? 0))];
    }

    /** @param array<string, mixed> $p */
    private function position(string $site, array $p): ?\stdClass
    {
        $q = $this->db->table('stock_label_positions')->where('site_id', $site);
        if (is_string($p['label_id'] ?? null)) {
            $q->where('label_id', $p['label_id']);
        } elseif (is_string($p['code'] ?? null)) {
            $q->where('code', $p['code']);
        } else {
            return null;
        }

        $row = $q->first();

        return $row instanceof \stdClass ? $row : null;
    }
}
