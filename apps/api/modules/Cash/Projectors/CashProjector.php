<?php

declare(strict_types=1);

namespace Tanaad\Cash\Projectors;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Tanaad\Cash\Support\CashBook;
use Tanaad\Shared\Ledger\DayLocks;
use Tanaad\Shared\Ledger\Projector;
use Tanaad\Shared\Ledger\StoredEvent;

/**
 * The only writer of cash_days and cash_entries, and of the cash day locks.
 * Also listens to people.payout_recorded and people.advance_given (by type string only):
 * a cash payout and an advance (always cash) are wages entries in that day's cash book.
 * recycling.collector_paid (also by type string) is a materials cash-out paid to the collector.
 * orders.cash_confirmed and cash orders.payment_recorded (by type string) are sales cash-in.
 */
final class CashProjector implements Projector
{
    public function __construct(private readonly ConnectionInterface $db, private readonly DayLocks $locks) {}

    public function handles(): array
    {
        return ['cash.day_opened', 'cash.entry_recorded', 'cash.entry_corrected', 'cash.day_closed', 'cash.day_reopened', 'cash.opening_set', 'people.payout_recorded', 'people.advance_given', 'recycling.collector_paid', 'orders.cash_confirmed', 'orders.payment_recorded'];
    }

    public function apply(StoredEvent $event): void
    {
        $site = $event->siteId();
        if ($site === null) {
            return;
        }
        $p = $event->payload();

        match ($event->type()) {
            'cash.day_opened' => $this->openDay($site, (string) $p['day'], (string) $p['currency'], (float) $p['opening'], $event),
            'cash.entry_recorded' => $this->insertEntry($site, $p, $event),
            'cash.entry_corrected' => $this->correct($site, $p, $event),
            'cash.day_closed' => $this->closeDay($site, $p, $event),
            'cash.day_reopened' => $this->reopenDay($site, $p, $event),
            'cash.opening_set' => $this->setOpening($site, $p, $event),
            'people.payout_recorded' => $this->payout($site, $p, $event),
            'people.advance_given' => $this->advance($site, $p, $event),
            'recycling.collector_paid' => $this->collectorPaid($site, $p, $event),
            'orders.cash_confirmed' => $this->salesIn($site, (string) $p['entry_id'], $p, 'Cash for order '.((string) ($p['number'] ?? '')), $event),
            'orders.payment_recorded' => ($p['is_cash'] ?? false) === true
                ? $this->salesIn($site, (string) $p['payment_id'], $p, 'Payment on '.((string) ($p['invoice_number'] ?? '')), $event) : null,
            default => null,
        };
    }

    public function reset(): void
    {
        $this->db->table('cash_entries')->delete();
        $this->db->table('cash_days')->delete();
        $this->db->table('day_locks')->where('ledger', CashBook::LEDGER)->delete();
    }

    private function openDay(string $site, string $day, string $currency, float $opening, StoredEvent $event): void
    {
        $this->db->table('cash_days')->insertOrIgnore([
            'id' => CashBook::dayId($site, $day), 'site_id' => $site, 'day' => $day, 'currency' => $currency,
            'opening' => $opening, 'status' => 'open', 'seq' => $event->seq,
            'created_at' => $event->receivedAt, 'updated_at' => $event->receivedAt,
        ]);
    }

    /** @param array<string, mixed> $p */
    private function insertEntry(string $site, array $p, StoredEvent $event, ?string $payoutId = null): void
    {
        $this->db->table('cash_entries')->insert([
            'id' => $p['entry_id'], 'site_id' => $site, 'cash_day_id' => $p['cash_day_id'], 'day' => $p['day'],
            'direction' => $p['direction'], 'category' => $p['category'], 'subcategory' => $p['subcategory'] ?? null,
            'amount' => $p['amount'], 'currency' => $p['currency'], 'fx_reference' => $p['fx_reference'] ?? null,
            'machine_id' => $p['machine_id'] ?? null, 'product_id' => $p['product_id'] ?? null,
            'payee' => $p['payee'] ?? null, 'note' => $p['note'] ?? null, 'has_receipt_photo' => (bool) ($p['has_receipt_photo'] ?? false),
            'payout_id' => $payoutId, 'corrects_entry_id' => $p['corrects_entry_id'] ?? null, 'superseded_by' => null,
            'event_id' => $event->id(), 'recorded_by' => $event->event->actorId, 'at' => $event->event->occurredAt,
            'seq' => $event->seq, 'created_at' => $event->receivedAt, 'updated_at' => $event->receivedAt,
        ]);
        $this->touchDay((string) $p['cash_day_id'], $event);
    }

    /** @param array<string, mixed> $p */
    private function correct(string $site, array $p, StoredEvent $event): void
    {
        $this->db->table('cash_entries')->where('id', $p['corrects_entry_id'])
            ->update(['superseded_by' => $p['entry_id'], 'seq' => $event->seq, 'updated_at' => $event->receivedAt]);
        $this->insertEntry($site, $p, $event);
    }

    /** @param array<string, mixed> $p */
    private function closeDay(string $site, array $p, StoredEvent $event): void
    {
        $this->db->table('cash_days')->where('id', $p['cash_day_id'])->update([
            'status' => 'closed', 'expected_closing' => $p['expected_closing'], 'counted_closing' => $p['counted'],
            'variance' => $p['variance'], 'denominations' => json_encode((object) ($p['denominations'] ?? []), JSON_THROW_ON_ERROR),
            'signed_by' => $event->event->actorId, 'closed_at' => $event->event->occurredAt, 'reopen_reason' => null,
            'seq' => $event->seq, 'updated_at' => $event->receivedAt,
        ]);
        $this->locks->lock($site, CashBook::LEDGER, (string) $p['day'], $event->id());
    }

    /** @param array<string, mixed> $p */
    private function reopenDay(string $site, array $p, StoredEvent $event): void
    {
        $this->db->table('cash_days')->where('id', $p['cash_day_id'])->update([
            'status' => 'reopened', 'reopen_reason' => $p['reason'] ?? $event->event->reason,
            'seq' => $event->seq, 'updated_at' => $event->receivedAt,
        ]);
        $this->locks->reopen($site, CashBook::LEDGER, (string) $p['day'], $event->id());
    }

    /**
     * Contract with People: {payout_id, settlement_run_id, person_id, amount, method, paid_at}.
     *
     * @param  array<string, mixed>  $p
     */
    private function payout(string $site, array $p, StoredEvent $event): void
    {
        if (($p['method'] ?? null) !== 'cash') {
            return;
        }
        $paidAt = is_string($p['paid_at'] ?? null) ? CarbonImmutable::parse($p['paid_at']) : $event->event->occurredAt;
        $day = $paidAt->toDateString();
        $dayId = CashBook::dayId($site, $day);
        if (! $this->db->table('cash_days')->where('id', $dayId)->exists()) {
            $this->openDay($site, $day, 'UGX', CashBook::openingFor($site, $day), $event);
        }
        $this->insertEntry($site, [
            'entry_id' => $p['payout_id'], 'cash_day_id' => $dayId, 'day' => $day, 'direction' => 'out', 'category' => 'wages',
            'amount' => CashBook::money(is_numeric($p['amount'] ?? null) ? (string) $p['amount'] : 0), 'currency' => 'UGX',
            'payee' => null, 'note' => 'Payout', 'has_receipt_photo' => false,
        ], $event, (string) $p['payout_id']);
    }

    /** @param array<string, mixed> $p */
    private function setOpening(string $site, array $p, StoredEvent $event): void
    {
        $this->db->table('cash_days')->where('id', $p['cash_day_id'])
            ->update(['opening' => $p['opening'], 'seq' => $event->seq, 'updated_at' => $event->receivedAt]);
    }

    /**
     * Contract with People: {advance_id, person_id, amount, reason, given_on}. Advances are paid in cash.
     *
     * @param  array<string, mixed>  $p
     */
    private function advance(string $site, array $p, StoredEvent $event): void
    {
        $day = is_string($p['given_on'] ?? null) ? (string) $p['given_on'] : $event->event->occurredAt->toDateString();
        $dayId = CashBook::dayId($site, $day);
        if (! $this->db->table('cash_days')->where('id', $dayId)->exists()) {
            $this->openDay($site, $day, 'UGX', CashBook::openingFor($site, $day), $event);
        }
        $this->insertEntry($site, [
            'entry_id' => $p['advance_id'], 'cash_day_id' => $dayId, 'day' => $day, 'direction' => 'out', 'category' => 'wages',
            'amount' => CashBook::money(is_numeric($p['amount'] ?? null) ? (string) $p['amount'] : 0), 'currency' => 'UGX',
            'payee' => null, 'note' => 'Advance', 'has_receipt_photo' => false,
        ], $event);
    }

    /**
     * Contract with Recycling: {payment_id, delivery_id, collector, day, net_kg, price_per_kg, amount, voucher_no}.
     *
     * @param  array<string, mixed>  $p
     */
    private function collectorPaid(string $site, array $p, StoredEvent $event): void
    {
        $day = (string) $p['day'];
        $dayId = CashBook::dayId($site, $day);
        if (! $this->db->table('cash_days')->where('id', $dayId)->exists()) {
            $this->openDay($site, $day, 'UGX', CashBook::openingFor($site, $day), $event);
        }
        $plain = fn (mixed $n): string => is_numeric($n) ? (string) \Tanaad\Shared\Money\Rounding::to((string) $n, 6)->strippedOfTrailingZeros() : '0';
        $this->insertEntry($site, [
            'entry_id' => $p['payment_id'], 'cash_day_id' => $dayId, 'day' => $day, 'direction' => 'out', 'category' => 'materials',
            'amount' => CashBook::money(is_numeric($p['amount'] ?? null) ? (string) $p['amount'] : 0), 'currency' => 'UGX',
            'payee' => $p['collector'] ?? null, 'note' => 'Scrap '.$plain($p['net_kg'] ?? 0).' kg @ '.$plain($p['price_per_kg'] ?? 0),
            'has_receipt_photo' => (bool) ($p['has_scale_photo'] ?? false),
        ], $event);
    }

    /**
     * Contract with Orders: {entry_id|payment_id, day, amount, currency, fx_reference?, client_name}, a sales cash-in.
     *
     * @param  array<string, mixed>  $p
     */
    private function salesIn(string $site, string $entryId, array $p, string $note, StoredEvent $event): void
    {
        $day = (string) $p['day'];
        $dayId = CashBook::dayId($site, $day);
        if (! $this->db->table('cash_days')->where('id', $dayId)->exists()) {
            $this->openDay($site, $day, 'UGX', CashBook::openingFor($site, $day), $event);
        }
        $this->insertEntry($site, [
            'entry_id' => $entryId, 'cash_day_id' => $dayId, 'day' => $day, 'direction' => 'in', 'category' => CashBook::SALE,
            'amount' => CashBook::money(is_numeric($p['amount'] ?? null) ? (string) $p['amount'] : 0), 'currency' => (string) ($p['currency'] ?? 'UGX'),
            'fx_reference' => $p['fx_reference'] ?? null, 'payee' => $p['client_name'] ?? null, 'note' => $note, 'has_receipt_photo' => false,
        ], $event);
    }

    private function touchDay(string $dayId, StoredEvent $event): void
    {
        $this->db->table('cash_days')->where('id', $dayId)->update(['seq' => $event->seq, 'updated_at' => $event->receivedAt]);
    }
}
