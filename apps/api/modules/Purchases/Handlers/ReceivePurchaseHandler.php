<?php

declare(strict_types=1);

namespace Tanaad\Purchases\Handlers;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tanaad\Config\Support\Payload;
use Tanaad\Purchases\Commands\ReceivePurchase;
use Tanaad\Purchases\Support\Purchasing;
use Tanaad\Shared\Arithmetic\Arithmetic;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;
use Tanaad\Shared\Ledger\Rejection;

/**
 * Receives a purchase once. Landed cost per kg is computed here with Arithmetic::landedCost on
 * the received kg and the order's own FX reference and cost lines; the variance flag travels in
 * the event. Books no cash and no stock: those are separate, explicit facts.
 */
final class ReceivePurchaseHandler implements Handler
{
    public function handle(Command $command): array
    {
        if (! $command instanceof ReceivePurchase) {
            throw new \LogicException('Wrong command for '.self::class);
        }
        $ctx = $command->context();
        $siteId = $ctx->siteId ?? throw Rejection::invalidPayload(['site_id' => 'required']);
        $p = new Payload($command->payload);
        $purchaseId = $p->uuid('purchase_id');
        $kg = $p->num('received_kg', positive: true);
        if ($kg > Purchasing::MAX_KG) {
            $p->error('received_kg', 'must be at most 10000000');
        }
        $today = Purchasing::businessDay($siteId, $ctx->occurredAt);
        $day = $p->optDate('received_on') ?? $today;
        if ($day > Purchasing::businessDay($siteId, CarbonImmutable::now())) {
            $p->error('received_on', 'must not be in the future');
        }
        $p->check();

        $row = DB::table('purchases')->where(['id' => $purchaseId, 'site_id' => $siteId])->whereNull('deleted_at')->lockForUpdate()->first();
        if ($row === null) {
            throw Rejection::notFound('Purchase', $purchaseId);
        }
        if ($row->status === 'received') {
            throw Rejection::stateConflict("Purchase {$row->reference} is already received.");
        }
        if ($day < (string) $row->ordered_on) {
            throw Rejection::invalidPayload(['received_on' => "must not be before the order date {$row->ordered_on}"]);
        }
        $stored = json_decode((string) $row->costs, true);
        $costs = [];
        foreach (is_array($stored) ? $stored : [] as $c) {
            if (is_array($c) && is_numeric($c['amount'] ?? null) && is_string($c['currency'] ?? null)) {
                $costs[] = ['amount' => (float) $c['amount'], 'currency' => $c['currency']];
            }
        }
        $landed = Arithmetic::landedCost([
            'qtyKg' => (float) $row->qty_kg, 'unitPrice' => (float) $row->unit_price, 'currency' => (string) $row->currency,
            'fx' => (float) ($row->fx_reference ?? 1), 'costs' => $costs, 'receivedKg' => $kg,
        ]);

        return [Event::from($ctx, 'purchases.received', [
            'purchase_id' => $purchaseId,
            'reference' => (string) $row->reference,
            'received_kg' => $kg,
            'ordered_kg' => (float) $row->qty_kg,
            'received_on' => $day,
            'variance' => Purchasing::isVariance((float) $row->qty_kg, $kg),
            'goods_ugx' => $landed['goodsUgx'],
            'extras_ugx' => $landed['extrasUgx'],
            'total_ugx' => $landed['totalUgx'],
            'per_kg_ugx' => $landed['perKgUgx'],
            'uplift_pct' => $landed['upliftPct'],
        ])];
    }
}
