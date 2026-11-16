<?php

declare(strict_types=1);

namespace Tanaad\Orders\Handlers;

use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tanaad\Config\Support\Payload;
use Tanaad\Orders\Commands\ConfirmOrderCash;
use Tanaad\Orders\Support\Sales;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\DayLocks;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;
use Tanaad\Shared\Ledger\Rejection;

/**
 * Confirms the cash received for a local order awaiting cash: the amount must equal the order total.
 * The Cash module books orders.cash_confirmed (by type string) as a sales cash-in on that day, so the
 * day's cash book must not be closed. The cash entry id is derived from the order, so it is booked once.
 */
final class ConfirmOrderCashHandler implements Handler
{
    public const string NAMESPACE = '0f5c3b9e-2d71-5a48-9e6b-7c14d8a2f053';

    public function __construct(private readonly DayLocks $locks) {}

    public function handle(Command $command): array
    {
        if (! $command instanceof ConfirmOrderCash) {
            throw new \LogicException('Wrong command for '.self::class);
        }
        $ctx = $command->context();
        $siteId = $ctx->siteId ?? throw Rejection::invalidPayload(['site_id' => 'required']);
        $p = new Payload($command->payload);
        $orderId = $p->uuid('order_id');
        $amount = $p->num('amount', positive: true);
        if ($amount > Sales::MAX_MONEY) {
            $p->error('amount', 'is too large');
        }
        $p->check();

        $o = DB::table('orders')->where(['id' => $orderId, 'site_id' => $siteId])->whereNull('deleted_at')->lockForUpdate()->first();
        if ($o === null) {
            throw Rejection::notFound('Order', $orderId);
        }
        if ((string) $o->channel !== 'local' || (string) $o->status !== 'awaiting_cash') {
            throw Rejection::stateConflict("Order {$o->number} is not awaiting cash.");
        }
        $currency = (string) $o->currency;
        $total = Sales::money((string) $o->total, $currency);
        if (! Sales::money($amount, $currency)->isEqualTo($total)) {
            throw Rejection::invalidPayload(['amount' => 'must equal the order total '.$total]);
        }
        $entryId = Uuid::uuid5(self::NAMESPACE, "cash:{$orderId}")->toString();
        if (DB::table('cash_entries')->where('id', $entryId)->exists()) {
            throw Rejection::duplicateFact("Cash for order {$o->number} is already recorded.");
        }
        $day = Sales::businessDay($siteId, $ctx->occurredAt);
        $this->locks->assertOpen($siteId, Sales::CASH_LEDGER, $day);

        return [Event::from($ctx, 'orders.cash_confirmed', [
            'order_id' => $orderId, 'number' => (string) $o->number, 'entry_id' => $entryId, 'day' => $day,
            'amount' => $total->toFloat(), 'currency' => $currency, 'fx_reference' => $o->fx_reference === null ? null : (float) $o->fx_reference,
            'client_id' => (string) $o->client_id, 'client_name' => (string) $o->client_name,
        ])];
    }
}
