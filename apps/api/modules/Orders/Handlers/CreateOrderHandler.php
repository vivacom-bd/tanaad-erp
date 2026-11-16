<?php

declare(strict_types=1);

namespace Tanaad\Orders\Handlers;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tanaad\Config\Support\Payload;
use Tanaad\Orders\Commands\CreateOrder;
use Tanaad\Orders\Support\Sales;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;
use Tanaad\Shared\Ledger\NumberSeries;
use Tanaad\Shared\Ledger\Rejection;

/**
 * Creates a sales order. Unit prices are never taken from the client: each line's per-bundle price is the
 * site's Config price for the client's channel (latest effective_from on or before the order day) × the
 * product's bundle pieces, converted at the day's USD/UGX rate when the price is in another currency, and
 * rounded to the order currency. The order number comes from the site's sales_order series.
 * A local order awaits cash, an export one approval. A client with credit (export, or any limit set)
 * whose exposure (open invoices + committed uninvoiced orders, in its currency) plus this order exceeds the limit is flagged over_limit and
 * awaits approval (CreditLimitPolicy raises credit_over_limit for the CEO).
 */
final class CreateOrderHandler implements Handler
{
    public function __construct(private readonly NumberSeries $series) {}

    public function handle(Command $command): array
    {
        if (! $command instanceof CreateOrder) {
            throw new \LogicException('Wrong command for '.self::class);
        }
        $ctx = $command->context();
        $siteId = $ctx->siteId ?? throw Rejection::invalidPayload(['site_id' => 'required']);
        $p = new Payload($command->payload);
        $id = $p->optUuid('order_id') ?? (string) Str::uuid7();
        $clientId = $p->uuid('client_id');
        $notes = $p->optStr('notes', 1000);
        $lines = [];
        foreach ($p->children('lines') as $i => $line) {
            $product = $line->uuid('product_id');
            $bundles = $line->int('bundles', 1);
            if ($bundles > Sales::MAX_BUNDLES) {
                $line->error('bundles', 'must be at most '.Sales::MAX_BUNDLES);
            }
            if ($product !== '' && isset($lines[$product])) {
                $line->error('product_id', 'appears on more than one line');
            }
            $lines[$product] = ['i' => $i, 'bundles' => $bundles];
        }
        if ($lines === []) {
            $p->error('lines', 'at least one line is required');
        }
        $p->check();

        $day = Sales::businessDay($siteId, $ctx->occurredAt);
        if ($day > Sales::businessDay($siteId, CarbonImmutable::now())) {
            throw Rejection::invalidPayload(['day' => 'must not be in the future']);
        }
        if (DB::table('orders')->where('id', $id)->exists()) {
            throw Rejection::duplicateFact("Order {$id} already exists.");
        }
        // Locked so two orders for one client cannot both pass the credit check on the same balance.
        $client = DB::table('clients')->where('id', $clientId)->whereNull('deleted_at')->lockForUpdate()->first();
        if ($client === null || ! (bool) $client->active) {
            throw Rejection::invalidPayload(['client_id' => 'is not an active client']);
        }
        $channel = (string) $client->channel;
        $currency = (string) $client->currency_pref;

        $products = DB::table('products')->where('site_id', $siteId)->whereNull('deleted_at')->where('active', true)
            ->whereIn('id', array_keys($lines))->get(['id', 'bundle_pieces'])->keyBy('id');
        // The day's USD/UGX reference: the latest rate on or before the order day, else the earliest one set.
        $rates = DB::table('fx_rates')->where(['base' => 'USD', 'quote' => 'UGX'])->whereNull('deleted_at');
        $fx = (clone $rates)->where('day', '<=', $day)->orderByDesc('day')->value('rate') ?? (clone $rates)->orderBy('day')->value('rate');
        $rate = is_numeric($fx) ? BigDecimal::of((string) $fx) : null;
        $out = [];
        $total = BigDecimal::zero();
        foreach ($lines as $product => $l) {
            $row = $products[$product] ?? null;
            if ($row === null) {
                $p->error("lines.{$l['i']}.product_id", 'is not an active product of this site');

                continue;
            }
            $prices = DB::table('prices')->where(['site_id' => $siteId, 'product_id' => $product, 'channel' => $channel])->whereNull('deleted_at')
                ->where('effective_from', '<=', $day)->orderByDesc('effective_from')->orderByDesc('seq')->get(['currency', 'amount']);
            $price = $prices->firstWhere('currency', $currency) ?? $prices->first();
            if ($price === null) {
                $p->error("lines.{$l['i']}.product_id", "has no {$channel} price");

                continue;
            }
            $perPiece = BigDecimal::of((string) $price->amount);
            if ((string) $price->currency !== $currency) {
                if ($rate === null || $rate->isZero() || ! in_array($currency.(string) $price->currency, ['USDUGX', 'UGXUSD'], true)) {
                    $p->error("lines.{$l['i']}.product_id", "has no price in {$currency} and no USD/UGX rate to convert it");

                    continue;
                }
                $perPiece = $currency === 'UGX' ? $perPiece->multipliedBy($rate) : $perPiece->dividedBy($rate, 12, RoundingMode::HalfUp);
            }
            $unit = Sales::money($perPiece->multipliedBy((int) $row->bundle_pieces), $currency);
            $amount = Sales::money($unit->multipliedBy($l['bundles']), $currency);
            $total = $total->plus($amount);
            $out[] = ['product_id' => $product, 'bundles' => $l['bundles'], 'unit_price' => $unit->toFloat(), 'amount' => $amount->toFloat(),
                'bundle_pieces' => (int) $row->bundle_pieces, 'price_currency' => (string) $price->currency, 'price_per_piece' => (float) $price->amount];
        }
        $p->check();
        if ($total->toFloat() > Sales::MAX_MONEY) {
            throw Rejection::invalidPayload(['lines' => 'order total is too large']);
        }

        // Credit: the client's exposure across the group, from this module's own tables, in the client's
        // currency (the limit's currency): open invoices plus orders committed but not yet invoiced. A local
        // order whose cash is confirmed owes nothing. Other-currency rows are not added (no cross-currency sums).
        $limit = BigDecimal::of((string) $client->credit_limit);
        $onCredit = $channel === 'export' || $limit->isPositive();
        $invoiced = DB::table('invoices')->where(['client_id' => $clientId, 'currency' => $currency])->whereNull('deleted_at')
            ->selectRaw('COALESCE(SUM(total - paid_amount), 0) AS b')->value('b') ?? '0';
        $uninvoiced = DB::table('orders')->where(['client_id' => $clientId, 'currency' => $currency])->whereNull('deleted_at')
            ->whereIn('status', Sales::EXPOSED_STATUSES)->whereNull('invoice_id')->whereNull('cash_confirmed_at')
            ->selectRaw('COALESCE(SUM(total), 0) AS b')->value('b') ?? '0';
        $balance = BigDecimal::of((string) $invoiced)->plus(BigDecimal::of((string) $uninvoiced));
        $overLimit = $onCredit && $balance->plus($total)->isGreaterThan($limit);
        $status = $channel === 'local' && ! $overLimit ? 'awaiting_cash' : 'awaiting_approval';

        return [Event::from($ctx, 'orders.created', [
            'order_id' => $id,
            'number' => $this->series->allocate($siteId, Sales::ORDER_SERIES),
            'client_id' => $clientId,
            'client_name' => (string) $client->name,
            'channel' => $channel,
            'currency' => $currency,
            'fx_reference' => $rate?->toFloat(),
            'lines' => $out,
            'total' => $total->toFloat(),
            'status' => $status,
            'over_limit' => $overLimit,
            'client_balance' => $balance->toFloat(),
            'credit_limit' => $limit->toFloat(),
            'day' => $day,
            'notes' => $notes,
        ])];
    }
}
