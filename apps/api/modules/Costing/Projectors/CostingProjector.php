<?php

declare(strict_types=1);

namespace Tanaad\Costing\Projectors;

use Illuminate\Database\ConnectionInterface;
use Tanaad\Costing\Support\CostBook;
use Tanaad\Shared\Ledger\Projector;
use Tanaad\Shared\Ledger\StoredEvent;

/**
 * The only writer of the costing tables. Everything except costing.* is a reaction to other
 * modules' events by type string; the payload contracts are noted on each method. Every write
 * is keyed by a source id, so applying an event twice changes nothing.
 */
final class CostingProjector implements Projector
{
    public const array TABLES = ['costing_cost_lines', 'costing_revenue_lines', 'costing_output_lines', 'costing_material_receipts', 'costing_closed_days', 'cost_category_map', 'costing_periods'];

    public function __construct(private readonly ConnectionInterface $db) {}

    public function handles(): array
    {
        return [
            'cash.entry_recorded', 'cash.entry_corrected', 'cash.day_closed', 'cash.day_reopened',
            'people.payout_recorded', 'people.advance_given', 'recycling.collector_paid', 'spares.part_issued',
            'purchases.received', 'production.entry_recorded', 'orders.cash_confirmed', 'orders.invoiced',
            'costing.mapping_saved', 'costing.period_closed', 'costing.period_reopened',
        ];
    }

    public function apply(StoredEvent $event): void
    {
        $p = $event->payload();
        $site = $event->siteId() ?? (is_string($p['site_id'] ?? null) ? $p['site_id'] : null);
        if ($site === null) {
            return;
        }

        match ($event->type()) {
            'cash.entry_recorded' => $this->cashEntry($site, $p, $event),
            'cash.entry_corrected' => $this->cashCorrection($site, $p, $event),
            'cash.day_closed' => $this->closedDay($site, $p, true, $event),
            'cash.day_reopened' => $this->closedDay($site, $p, false, $event),
            'people.payout_recorded' => $this->payout($site, $p, $event),
            'people.advance_given' => $this->line($site, $p['advance_id'] ?? null, self::day($p['given_on'] ?? null, $event), 'advance', true, $p['amount'] ?? null, $event),
            'recycling.collector_paid' => $this->line($site, $p['payment_id'] ?? null, self::day($p['day'] ?? null, $event), 'collector_paid', true, $p['amount'] ?? null, $event),
            'spares.part_issued' => $this->line($site, $p['issue_id'] ?? null, $event->event->occurredAt->setTimezone(CostBook::siteTimezone($site))->toDateString(), 'spares_issue', false, $p['cost'] ?? null, $event, is_string($p['machine_id'] ?? null) ? $p['machine_id'] : null),
            'purchases.received' => $this->receipt($site, $p, $event),
            'production.entry_recorded' => $this->output($site, $p, $event),
            'orders.cash_confirmed' => $this->orderCash($site, $p, $event),
            'orders.invoiced' => $this->invoice($site, $p, $event),
            'costing.mapping_saved' => $this->mapping($site, $p, $event),
            'costing.period_closed' => $this->periodClosed($site, $p, $event),
            'costing.period_reopened' => $this->periodReopened($p, $event),
            default => null,
        };
    }

    public function reset(): void
    {
        foreach (self::TABLES as $table) {
            $this->db->table($table)->delete();
        }
    }

    /**
     * Contract with Cash: {entry_id, day, direction, category, amount, currency, fx_reference?, machine_id?, product_id?}.
     *
     * @param  array<string, mixed>  $p
     */
    private function cashEntry(string $site, array $p, StoredEvent $event): void
    {
        $id = is_string($p['entry_id'] ?? null) ? $p['entry_id'] : null;
        $day = self::day($p['day'] ?? null, $event);
        $currency = is_string($p['currency'] ?? null) ? $p['currency'] : 'UGX';
        $amount = is_numeric($p['amount'] ?? null) ? (string) $p['amount'] : null;
        if ($id === null || $amount === null) {
            return;
        }
        $fx = is_numeric($p['fx_reference'] ?? null) ? (string) $p['fx_reference'] : null;
        $ugx = $currency === 'UGX' ? $amount : ($fx === null ? null : CostBook::mul($amount, $fx));
        $category = is_string($p['category'] ?? null) ? $p['category'] : 'other';
        if (($p['direction'] ?? null) === 'out') {
            if ($ugx === null) {
                return; // a foreign-currency expense without a rate cannot be costed; the cash book requires fx_reference for those
            }
            $this->line($site, $id, $day, 'cash:'.$category, true, $ugx, $event, $p['machine_id'] ?? null, $p['product_id'] ?? null);

            return;
        }
        if ($category === 'sale') {
            $this->db->table('costing_revenue_lines')->insertOrIgnore([
                'id' => $id, 'site_id' => $site, 'day' => $day, 'source' => 'cash_sale', 'amount' => $amount, 'currency' => $currency,
                'amount_ugx' => $ugx, 'event_id' => $event->id(), ...$this->stamp($event),
            ]);
        }
    }

    /** @param array<string, mixed> $p */
    private function cashCorrection(string $site, array $p, StoredEvent $event): void
    {
        $old = is_string($p['corrects_entry_id'] ?? null) ? $p['corrects_entry_id'] : '';
        $new = is_string($p['entry_id'] ?? null) ? $p['entry_id'] : null;
        foreach (['costing_cost_lines', 'costing_revenue_lines'] as $table) {
            $this->db->table($table)->where('id', $old)->update(['superseded_by' => $new, 'seq' => $event->seq, 'updated_at' => $event->receivedAt]);
        }
        $this->cashEntry($site, $p, $event);
    }

    /**
     * Contract with Cash: {day} on cash.day_closed and cash.day_reopened.
     *
     * @param  array<string, mixed>  $p
     */
    private function closedDay(string $site, array $p, bool $closed, StoredEvent $event): void
    {
        if (! is_string($p['day'] ?? null)) {
            return;
        }
        $this->db->table('costing_closed_days')->upsert(
            [['site_id' => $site, 'day' => $p['day'], 'closed' => $closed, 'event_id' => $event->id(), ...$this->stamp($event)]],
            ['site_id', 'day'], ['closed', 'event_id', 'seq', 'updated_at'],
        );
    }

    /**
     * Contract with People: {payout_id, amount, method, paid_at}. Cash payouts are in the cash book; others are not.
     *
     * @param  array<string, mixed>  $p
     */
    private function payout(string $site, array $p, StoredEvent $event): void
    {
        $cash = ($p['method'] ?? null) === 'cash';
        $day = is_string($p['paid_at'] ?? null)
            ? \Carbon\CarbonImmutable::parse($p['paid_at'])->setTimezone(CostBook::siteTimezone($site))->toDateString()
            : $event->event->occurredAt->setTimezone(CostBook::siteTimezone($site))->toDateString();
        $this->line($site, $p['payout_id'] ?? null, $day, $cash ? 'payout:cash' : 'payout:non_cash', $cash, $p['amount'] ?? null, $event);
    }

    /**
     * Contract with Purchases: {purchase_id, received_kg, received_on, total_ugx}.
     *
     * @param  array<string, mixed>  $p
     */
    private function receipt(string $site, array $p, StoredEvent $event): void
    {
        if (! is_string($p['purchase_id'] ?? null) || ! is_numeric($p['received_kg'] ?? null) || ! is_numeric($p['total_ugx'] ?? null)) {
            return;
        }
        $this->db->table('costing_material_receipts')->insertOrIgnore([
            'id' => $p['purchase_id'], 'site_id' => $site, 'day' => self::day($p['received_on'] ?? null, $event),
            'received_kg' => (string) $p['received_kg'], 'total_ugx' => (string) $p['total_ugx'], 'event_id' => $event->id(), ...$this->stamp($event),
        ]);
    }

    /**
     * Contract with Production: {entry_id, day, stage_code, product_id?, good_units, output_kg}; a re-sent entry_id replaces.
     *
     * @param  array<string, mixed>  $p
     */
    private function output(string $site, array $p, StoredEvent $event): void
    {
        if (! is_string($p['entry_id'] ?? null) || ! is_string($p['day'] ?? null)) {
            return;
        }
        $this->db->table('costing_output_lines')->upsert([[
            'id' => $p['entry_id'], 'site_id' => $site, 'day' => $p['day'], 'product_id' => is_string($p['product_id'] ?? null) ? $p['product_id'] : null,
            'stage_code' => (string) ($p['stage_code'] ?? ''), 'good_units' => is_numeric($p['good_units'] ?? null) ? (string) $p['good_units'] : '0',
            'output_kg' => is_numeric($p['output_kg'] ?? null) ? (string) $p['output_kg'] : '0', 'event_id' => $event->id(), ...$this->stamp($event),
        ]], ['id'], ['day', 'product_id', 'stage_code', 'good_units', 'output_kg', 'event_id', 'seq', 'updated_at']);
    }

    /**
     * Contract with Orders: orders.invoiced {invoice_id, order_id, number, total, currency, prepaid, fully_paid, issued_day, fx_reference?};
     * the site is the event's site.
     *
     * Revenue rule (no double counting, none missing):
     *  - a local order's confirmed cash (orders.cash_confirmed {entry_id, order_id, day, amount, currency, fx_reference?})
     *    is its revenue, recognised on the cash day, whether or not it is ever invoiced;
     *  - an invoice adds its total less what was prepaid by that confirmed cash (`prepaid`), on issued_day, so a
     *    fully prepaid invoice adds nothing and a credit (export) invoice adds its whole total.
     * Payments against invoices (orders.payment_recorded) are receipts, not revenue. The other cash revenue is a manual
     * cash.entry_recorded in category `sale` (a counter sale with no order).
     *
     * @param  array<string, mixed>  $p
     */
    private function invoice(string $site, array $p, StoredEvent $event): void
    {
        $prepaid = is_numeric($p['prepaid'] ?? null) ? (string) $p['prepaid'] : '0';
        $total = is_numeric($p['total'] ?? null) ? \Brick\Math\BigDecimal::of((string) $p['total'])->minus($prepaid) : null;
        if ($total === null || ! $total->isPositive()) {
            return;
        }
        $this->revenue($site, is_string($p['invoice_id'] ?? null) ? $p['invoice_id'] : $event->id(), self::day($p['issued_day'] ?? null, $event), 'invoice', (string) $total, $p, $event);
    }

    /** @param array<string, mixed> $p */
    private function orderCash(string $site, array $p, StoredEvent $event): void
    {
        if (! is_string($p['entry_id'] ?? null) || ! is_numeric($p['amount'] ?? null)) {
            return;
        }
        $this->revenue($site, $p['entry_id'], self::day($p['day'] ?? null, $event), 'order_cash', (string) $p['amount'], $p, $event);
    }

    /** @param array<string, mixed> $p */
    private function revenue(string $site, string $id, string $day, string $source, string $amount, array $p, StoredEvent $event): void
    {
        if (! is_string($p['currency'] ?? null) || preg_match('/^[A-Z]{3}$/', $p['currency']) !== 1) {
            return;
        }
        $fx = is_numeric($p['fx_reference'] ?? null) ? (string) $p['fx_reference'] : null;
        $this->db->table('costing_revenue_lines')->insertOrIgnore([
            'id' => $id, 'site_id' => $site, 'day' => $day, 'source' => $source, 'amount' => $amount, 'currency' => $p['currency'],
            'amount_ugx' => $p['currency'] === 'UGX' ? $amount : ($fx === null ? null : CostBook::mul($amount, $fx)),
            'event_id' => $event->id(), ...$this->stamp($event),
        ]);
    }

    /** @param array<string, mixed> $p */
    private function mapping(string $site, array $p, StoredEvent $event): void
    {
        $this->db->table('cost_category_map')->upsert([[
            'id' => $p['mapping_id'], 'site_id' => $site, 'source_key' => $p['source_key'], 'cost_category' => $p['cost_category'],
            'event_id' => $event->id(), ...$this->stamp($event),
        ]], ['site_id', 'source_key'], ['cost_category', 'event_id', 'seq', 'updated_at']);
    }

    /** @param array<string, mixed> $p */
    private function periodClosed(string $site, array $p, StoredEvent $event): void
    {
        $this->db->table('costing_periods')->upsert([[
            'id' => $p['period_id'], 'site_id' => $site, 'label' => $p['label'], 'from_day' => $p['from'], 'to_day' => $p['to'],
            'status' => 'closed', 'version' => $p['version'], 'snapshot' => json_encode($p['snapshot'], JSON_THROW_ON_ERROR),
            'closed_by' => $event->event->actorId, 'closed_at' => $event->event->occurredAt, 'reopen_reason' => null,
            'event_id' => $event->id(), ...$this->stamp($event),
        ]], ['id'], ['status', 'version', 'snapshot', 'closed_by', 'closed_at', 'reopen_reason', 'event_id', 'seq', 'updated_at']);
    }

    /** @param array<string, mixed> $p */
    private function periodReopened(array $p, StoredEvent $event): void
    {
        $this->db->table('costing_periods')->where('id', $p['period_id'])->update([
            'status' => 'reopened', 'reopen_reason' => $p['reason'], 'event_id' => $event->id(), 'seq' => $event->seq, 'updated_at' => $event->receivedAt,
        ]);
    }

    private function line(string $site, mixed $id, string $day, string $sourceKey, bool $cash, mixed $amount, StoredEvent $event, mixed $machineId = null, mixed $productId = null): void
    {
        if (! is_string($id) || ! is_numeric($amount)) {
            return;
        }
        $this->db->table('costing_cost_lines')->insertOrIgnore([
            'id' => $id, 'site_id' => $site, 'day' => $day, 'source_key' => $sourceKey, 'cash' => $cash, 'amount_ugx' => (string) $amount,
            'machine_id' => is_string($machineId) ? $machineId : null, 'product_id' => is_string($productId) ? $productId : null,
            'event_id' => $event->id(), ...$this->stamp($event),
        ]);
    }

    private static function day(mixed $day, StoredEvent $event): string
    {
        return is_string($day) && preg_match('/^\d{4}-\d{2}-\d{2}/', $day) === 1 ? substr($day, 0, 10) : $event->event->occurredAt->toDateString();
    }

    /** @return array{seq: int, created_at: \Carbon\CarbonImmutable, updated_at: \Carbon\CarbonImmutable} */
    private function stamp(StoredEvent $event): array
    {
        return ['seq' => $event->seq, 'created_at' => $event->receivedAt, 'updated_at' => $event->receivedAt];
    }
}
