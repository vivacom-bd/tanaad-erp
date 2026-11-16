<?php

declare(strict_types=1);

namespace Tanaad\Orders\Projectors;

use Brick\Math\BigDecimal;
use Illuminate\Database\ConnectionInterface;
use Tanaad\Orders\Support\Sales;
use Tanaad\Shared\Ledger\Projector;
use Tanaad\Shared\Ledger\StoredEvent;

/**
 * The only writer of clients, orders, invoices and payments. Idempotent by row id; statuses only move forward.
 * Also follows Dispatch by type string (order_ref is the order number at the site): dispatch.started →
 * picking, dispatch.gate_passed / dispatch.gate_overridden → dispatched, dispatch.delivered → delivered
 * (an invoiced order shows as invoiced, or paid, instead).
 */
final class OrdersProjector implements Projector
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function handles(): array
    {
        return ['orders.client_saved', 'orders.created', 'orders.cash_confirmed', 'orders.approved', 'orders.held', 'orders.invoiced', 'orders.payment_recorded',
            'dispatch.started', 'dispatch.gate_passed', 'dispatch.gate_overridden', 'dispatch.delivered'];
    }

    public function apply(StoredEvent $event): void
    {
        $p = $event->payload();
        if ($event->type() === 'orders.client_saved') {
            $this->client($p, $event);

            return;
        }
        $site = $event->siteId();
        if ($site === null) {
            return;
        }
        $at = $event->event->occurredAt;
        $touch = ['seq' => $event->seq, 'updated_at' => $event->receivedAt];

        match ($event->type()) {
            'orders.created' => $this->db->table('orders')->insertOrIgnore([
                'id' => $p['order_id'], 'site_id' => $site, 'number' => $p['number'], 'client_id' => $p['client_id'], 'client_name' => $p['client_name'],
                'channel' => $p['channel'], 'status' => $p['status'], 'currency' => $p['currency'], 'fx_reference' => $p['fx_reference'] ?? null,
                'total' => $p['total'], 'lines' => json_encode($p['lines'], JSON_THROW_ON_ERROR), 'over_limit' => (bool) ($p['over_limit'] ?? false),
                'notes' => $p['notes'] ?? null, 'day' => $p['day'], 'created_by' => $event->event->actorId, 'at' => $at,
                'event_id' => $event->id(), 'created_at' => $event->receivedAt, ...$touch,
            ]),
            'orders.cash_confirmed' => $this->db->table('orders')->where(['id' => $p['order_id'], 'status' => 'awaiting_cash'])->update([
                'status' => 'approved', 'cash_confirmed_at' => $at, 'cash_amount' => $p['amount'], 'cash_entry_id' => $p['entry_id'], ...$touch,
            ]),
            'orders.approved' => $this->db->table('orders')->where('id', $p['order_id'])->whereIn('status', ['awaiting_approval', 'held'])->update([
                'status' => $p['status'], 'approved_by' => $event->event->actorId, 'approved_at' => $at, ...(is_string($p['reason'] ?? null) ? ['notes' => $p['reason']] : []), ...$touch,
            ]),
            'orders.held' => $this->db->table('orders')->where(['id' => $p['order_id'], 'status' => 'awaiting_approval'])->update([
                'status' => 'held', 'hold_reason' => $p['reason'] ?? null, ...$touch,
            ]),
            'orders.invoiced' => $this->invoiced($site, $p, $event),
            'orders.payment_recorded' => $this->payment($site, $p, $event),
            'dispatch.started' => $this->advance($site, is_array($p['order'] ?? null) ? ($p['order']['order_ref'] ?? null) : null, 'picking', $event),
            'dispatch.gate_passed', 'dispatch.gate_overridden' => $this->advance($site, $p['order_ref'] ?? null, 'dispatched', $event),
            'dispatch.delivered' => $this->advance($site, $p['order_ref'] ?? null, 'delivered', $event),
            default => null,
        };
    }

    public function reset(): void
    {
        foreach (['payments', 'invoices', 'orders', 'clients'] as $t) {
            $this->db->table($t)->delete();
        }
    }

    /** @param array<string, mixed> $p */
    private function client(array $p, StoredEvent $event): void
    {
        $row = [
            'name' => $p['name'], 'country' => $p['country'], 'channel' => $p['channel'], 'currency_pref' => $p['currency_pref'],
            'credit_limit' => $p['credit_limit'], 'credit_days' => $p['credit_days'], 'whatsapp' => $p['whatsapp'] ?? '', 'contact' => $p['contact'] ?? '',
            'active' => (bool) ($p['active'] ?? true), 'event_id' => $event->id(), 'seq' => $event->seq, 'updated_at' => $event->receivedAt,
        ];
        if ($this->db->table('clients')->where('id', $p['client_id'])->exists()) {
            $this->db->table('clients')->where('id', $p['client_id'])->update($row);

            return;
        }
        $this->db->table('clients')->insert(['id' => $p['client_id'], 'site_id' => null, 'registered_site_id' => $event->siteId(), 'created_at' => $event->receivedAt, ...$row]);
    }

    /** @param array<string, mixed> $p */
    private function invoiced(string $site, array $p, StoredEvent $event): void
    {
        $inserted = $this->db->table('invoices')->insertOrIgnore([
            'id' => $p['invoice_id'], 'site_id' => $site, 'order_id' => $p['order_id'], 'order_number' => $p['order_number'], 'client_id' => $p['client_id'],
            'number' => $p['number'], 'total' => $p['total'], 'currency' => $p['currency'], 'paid_amount' => $p['prepaid'] ?? 0,
            'balance' => (string) BigDecimal::of((string) $p['total'])->minus((string) ($p['prepaid'] ?? 0)),
            'issued_day' => $p['issued_day'], 'issued_at' => $event->event->occurredAt, 'efris_fdn' => $p['efris_fdn'] ?? null,
            'efris_verification_code' => $p['efris_verification_code'] ?? null, 'issued_by' => $event->event->actorId,
            'event_id' => $event->id(), 'seq' => $event->seq, 'created_at' => $event->receivedAt, 'updated_at' => $event->receivedAt,
        ]);
        $order = $this->db->table('orders')->where('id', $p['order_id'])->first(['status']);
        if ($inserted === 0 || $order === null) {
            return;
        }
        $paid = (bool) ($p['fully_paid'] ?? false);
        $this->db->table('orders')->where('id', $p['order_id'])->update([
            'invoice_id' => $p['invoice_id'], 'fully_paid' => $paid,
            'status' => Sales::settled((string) $order->status, true, $paid), 'seq' => $event->seq, 'updated_at' => $event->receivedAt,
        ]);
    }

    /** @param array<string, mixed> $p */
    private function payment(string $site, array $p, StoredEvent $event): void
    {
        $inserted = $this->db->table('payments')->insertOrIgnore([
            'id' => $p['payment_id'], 'site_id' => $site, 'client_id' => $p['client_id'], 'invoice_id' => $p['invoice_id'], 'order_id' => $p['order_id'],
            'amount' => $p['amount'], 'currency' => $p['currency'], 'method' => $p['method'], 'is_cash' => (bool) ($p['is_cash'] ?? false),
            'reference' => $p['reference'] ?? '', 'day' => $p['day'], 'at' => $event->event->occurredAt, 'recorded_by' => $event->event->actorId,
            'event_id' => $event->id(), 'seq' => $event->seq, 'created_at' => $event->receivedAt, 'updated_at' => $event->receivedAt,
        ]);
        if ($inserted === 0) {
            return;
        }
        $inv = $this->db->table('invoices')->where('id', $p['invoice_id'])->first(['total', 'paid_amount']);
        $paid = BigDecimal::of($inv === null ? '0' : (string) $inv->paid_amount)->plus(BigDecimal::of((string) $p['amount']));
        $this->db->table('invoices')->where('id', $p['invoice_id'])->update([
            'paid_amount' => (string) $paid, 'balance' => (string) BigDecimal::of($inv === null ? '0' : (string) $inv->total)->minus($paid),
            'seq' => $event->seq, 'updated_at' => $event->receivedAt,
        ]);
        if (! (bool) ($p['settles'] ?? false)) {
            return;
        }
        $order = $this->db->table('orders')->where('id', $p['order_id'])->first(['status']);
        if ($order === null) {
            return;
        }
        $status = Sales::RANK[(string) $order->status] >= Sales::RANK['dispatched'] ? 'paid' : (string) $order->status;
        $this->db->table('orders')->where('id', $p['order_id'])->update(['fully_paid' => true, 'status' => $status, 'seq' => $event->seq, 'updated_at' => $event->receivedAt]);
    }

    private function advance(string $site, mixed $ref, string $target, StoredEvent $event): void
    {
        if (! is_string($ref) || $ref === '') {
            return;
        }
        $order = $this->db->table('orders')->where(['site_id' => $site, 'number' => $ref])->first(['id', 'status', 'invoice_id', 'fully_paid']);
        if ($order === null || ! in_array((string) $order->status, Sales::RELEASED, true)) {
            return;
        }
        $status = Sales::settled($target, $order->invoice_id !== null, (bool) $order->fully_paid);
        if (! Sales::forward((string) $order->status, $status)) {
            return;
        }
        $this->db->table('orders')->where('id', $order->id)->update(['status' => $status, 'seq' => $event->seq, 'updated_at' => $event->receivedAt]);
    }
}
