<?php

declare(strict_types=1);

namespace Tanaad\Orders\Support;

use Illuminate\Support\Facades\DB;
use Tanaad\Config\Support\Payload;
use Tanaad\Shared\Ledger\CommandContext;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Rejection;

/**
 * The credit desk: approves or holds an order awaiting approval (a held one may still be approved).
 * Holding needs a reason. An approved local order (forced to approval by its credit limit) then
 * awaits cash; an export one is released. The order's credit_over_limit exception follows the
 * decision (ExceptionProjector reads these events by type string).
 */
final class OrderDecision
{
    /**
     * @param  array<string, mixed>  $payload
     * @return list<Event>
     */
    public static function decide(CommandContext $ctx, array $payload, bool $approve): array
    {
        $type = $approve ? 'orders.approved' : 'orders.held';
        $siteId = $ctx->siteId ?? throw Rejection::invalidPayload(['site_id' => 'required']);
        $p = new Payload($payload);
        $orderId = $p->uuid('order_id');
        $reason = $approve ? $p->optStr('reason', 1000) : $p->str('reason', 1000);
        $p->check();

        $o = DB::table('orders')->where(['id' => $orderId, 'site_id' => $siteId])->whereNull('deleted_at')->lockForUpdate()->first();
        if ($o === null) {
            throw Rejection::notFound('Order', $orderId);
        }
        $current = (string) $o->status;
        if ($current !== 'awaiting_approval' && ! ($approve && $current === 'held')) {
            throw Rejection::stateConflict("Order {$o->number} is {$o->status}, not awaiting approval.");
        }
        $awaitsCash = (string) $o->channel === 'local' && $o->cash_entry_id === null;
        $status = $approve ? ($awaitsCash ? 'awaiting_cash' : 'approved') : 'held';

        return [Event::from($ctx, $type, [
            'order_id' => $orderId, 'number' => (string) $o->number, 'client_name' => (string) $o->client_name,
            'status' => $status, 'reason' => $reason, 'over_limit' => (bool) $o->over_limit,
        ], reason: $reason)];
    }
}
