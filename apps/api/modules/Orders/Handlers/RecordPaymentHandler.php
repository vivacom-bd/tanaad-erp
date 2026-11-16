<?php

declare(strict_types=1);

namespace Tanaad\Orders\Handlers;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tanaad\Config\Support\Payload;
use Tanaad\Orders\Commands\RecordPayment;
use Tanaad\Orders\Support\Sales;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\DayLocks;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;
use Tanaad\Shared\Ledger\Rejection;
use Tanaad\Shared\Ledger\RejectionCode;

/**
 * Records a payment against an invoice of this site, in the invoice currency, never more than its open
 * balance (409, rule overpay). The method is an entry of the `payment_method` master-data list when that
 * list has entries; a "Cash" payment is also booked by the Cash module (by type string) as a sales cash-in,
 * so its cash day must not be closed. The invoice row is locked, so concurrent payments cannot overpay.
 */
final class RecordPaymentHandler implements Handler
{
    public function __construct(private readonly DayLocks $locks) {}

    public function handle(Command $command): array
    {
        if (! $command instanceof RecordPayment) {
            throw new \LogicException('Wrong command for '.self::class);
        }
        $ctx = $command->context();
        $siteId = $ctx->siteId ?? throw Rejection::invalidPayload(['site_id' => 'required']);
        $p = new Payload($command->payload);
        $paymentId = $p->optUuid('payment_id') ?? (string) Str::uuid7();
        $invoiceId = $p->uuid('invoice_id');
        $amount = $p->num('amount', positive: true);
        if ($amount > Sales::MAX_MONEY) {
            $p->error('amount', 'is too large');
        }
        $currency = $p->currency('currency');
        $method = $p->str('method', 80);
        $reference = $p->text('reference', 120);
        $p->check();

        $day = Sales::businessDay($siteId, $ctx->occurredAt);
        if ($day > Sales::businessDay($siteId, CarbonImmutable::now())) {
            throw Rejection::invalidPayload(['day' => 'must not be in the future']);
        }
        $methods = DB::table('ref_items')->where('list', 'payment_method')->whereNull('deleted_at')->where('active', true)
            ->where(fn ($q) => $q->whereNull('site_id')->orWhere('site_id', $siteId))->pluck('label')->all();
        if ($methods !== [] && ! in_array($method, $methods, true)) {
            throw Rejection::invalidPayload(['method' => 'must be a payment method from master data']);
        }
        $inv = DB::table('invoices')->where(['id' => $invoiceId, 'site_id' => $siteId])->whereNull('deleted_at')->lockForUpdate()->first();
        if ($inv === null) {
            throw Rejection::notFound('Invoice', $invoiceId);
        }
        if ($currency !== (string) $inv->currency) {
            throw Rejection::invalidPayload(['currency' => "must be the invoice currency {$inv->currency}"]);
        }
        // The id becomes the cash entry id for a cash payment: a reused one is a duplicate, not a 500.
        if (DB::table('payments')->where('id', $paymentId)->exists() || DB::table('cash_entries')->where('id', $paymentId)->exists()) {
            throw Rejection::duplicateFact("Payment {$paymentId} is already recorded.");
        }
        $paid = Sales::money($amount, $currency);
        $balance = Sales::money((string) $inv->total, $currency)->minus((string) $inv->paid_amount);
        if ($paid->isGreaterThan($balance)) {
            throw Rejection::of(RejectionCode::StateConflict, "Payment exceeds the open balance {$balance} of invoice {$inv->number}.", ['rule' => 'overpay', 'balance' => $balance->toFloat()]);
        }
        $isCash = mb_strtolower($method) === 'cash';
        if ($isCash) {
            $this->locks->assertOpen($siteId, Sales::CASH_LEDGER, $day);
        }
        $client = DB::table('clients')->where('id', $inv->client_id)->value('name');

        return [Event::from($ctx, 'orders.payment_recorded', [
            'payment_id' => $paymentId, 'invoice_id' => $invoiceId, 'invoice_number' => (string) $inv->number,
            'order_id' => (string) $inv->order_id, 'client_id' => (string) $inv->client_id, 'client_name' => is_string($client) ? $client : '',
            'amount' => $paid->toFloat(), 'currency' => $currency, 'method' => $method, 'is_cash' => $isCash, 'reference' => $reference,
            'day' => $day, 'settles' => $paid->isEqualTo($balance),
        ])];
    }
}
