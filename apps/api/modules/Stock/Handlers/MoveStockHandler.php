<?php

declare(strict_types=1);

namespace Tanaad\Stock\Handlers;

use Illuminate\Support\Str;
use Tanaad\Config\Support\Payload;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;
use Tanaad\Shared\Ledger\Rejection;
use Tanaad\Stock\Commands\MoveStock;
use Tanaad\Stock\Support\StockBook;
use Tanaad\Stock\Support\StockReads;

/**
 * Three moves. With label_id or code: the in-stock label (locked) goes to to_zone_id
 * (stock.label_moved; zone_id in the payload is the destination, which Labels reads).
 * Without a label: kind "transfer" moves a quantity zone to zone (stock.moved), kind "adjust"
 * corrects a zone's figure by a signed quantity with a mandatory reason (stock.adjusted).
 */
final class MoveStockHandler implements Handler
{
    public function handle(Command $command): array
    {
        if (! $command instanceof MoveStock) {
            throw new \LogicException('Wrong command for '.self::class);
        }
        $ctx = $command->context();
        $siteId = $ctx->siteId ?? throw Rejection::invalidPayload(['site_id' => 'required']);
        $p = new Payload($command->payload);
        $labelId = $p->optUuid('label_id');
        $code = $p->optStr('code', 64) ?? $p->optStr('label_code', 64);
        if ($labelId !== null || $code !== null) {
            return [$this->label($ctx, $siteId, $p, $labelId, $code)];
        }
        $kind = $p->has('kind') ? $p->oneOf('kind', ['transfer', 'adjust']) : 'transfer';
        $productId = $p->uuid('product_id');

        return [$kind === 'adjust' ? $this->adjust($ctx, $siteId, $p, $productId) : $this->transfer($ctx, $siteId, $p, $productId)];
    }

    private function label(\Tanaad\Shared\Ledger\CommandContext $ctx, string $siteId, Payload $p, ?string $labelId, ?string $code): Event
    {
        $to = $p->uuid('to_zone_id');
        $reason = $p->optStr('reason', 500);
        $p->check();
        StockReads::mustBelong($p, 'zones', 'to_zone_id', $to, $siteId);
        $p->check();
        $label = StockReads::inStockLabel($siteId, $labelId, $code);
        StockReads::assertDayOpen($siteId, $ctx, $label->product_id, $label->zone_id, $to);
        if ($label->zone_id === $to) {
            throw Rejection::stateConflict("Label {$label->code} is already in that zone.");
        }

        return Event::from($ctx, 'stock.label_moved', [
            'label_id' => $label->id, 'code' => $label->code, 'product_id' => $label->product_id,
            'from_zone_id' => $label->zone_id, 'to_zone_id' => $to, 'zone_id' => $to,
            'qty_units' => StockBook::qty($label->qty_units), 'qty_kg' => StockBook::qty($label->qty_kg), 'reason' => $reason,
        ]);
    }

    private function transfer(\Tanaad\Shared\Ledger\CommandContext $ctx, string $siteId, Payload $p, string $productId): Event
    {
        $from = $p->uuid('from_zone_id');
        $to = $p->uuid('to_zone_id');
        $q = StockReads::quantities($p);
        $reason = $p->optStr('reason', 500);
        if ($from === $to) {
            $p->error('to_zone_id', 'must differ from from_zone_id');
        }
        $p->check();
        StockReads::mustBelong($p, 'products', 'product_id', $productId, $siteId);
        StockReads::mustBelong($p, 'zones', 'from_zone_id', $from, $siteId);
        StockReads::mustBelong($p, 'zones', 'to_zone_id', $to, $siteId);
        $p->check();
        StockReads::assertDayOpen($siteId, $ctx, $productId, $from, $to);
        StockReads::assertCovers(StockReads::onHand($siteId, $productId, $from), $q, 'in that zone');

        return Event::from($ctx, 'stock.moved', [
            'product_id' => $productId, 'from_zone_id' => $from, 'to_zone_id' => $to, ...$q, 'reason' => $reason,
        ]);
    }

    private function adjust(\Tanaad\Shared\Ledger\CommandContext $ctx, string $siteId, Payload $p, string $productId): Event
    {
        $zone = $p->optUuid('zone_id') ?? $p->uuid('from_zone_id');
        $q = StockReads::quantities($p, signed: true);
        $reason = $p->str('reason', 500);
        if (mb_strlen(trim($reason)) < 3) {
            $p->error('reason', 'must say why (at least 3 characters)');
        }
        $p->check();
        StockReads::mustBelong($p, 'products', 'product_id', $productId, $siteId);
        StockReads::mustBelong($p, 'zones', 'zone_id', $zone, $siteId);
        $p->check();
        StockReads::assertDayOpen($siteId, $ctx, $productId, $zone);
        $have = StockReads::onHand($siteId, $productId, $zone);
        if ($have['bundles'] + $q['bundles'] < 0
            || \Brick\Math\BigDecimal::of($have['pieces'])->plus($q['qty_units'])->isNegative()
            || \Brick\Math\BigDecimal::of($have['kg'])->plus($q['qty_kg'])->isNegative()) {
            throw Rejection::stateConflict('An adjustment may not take the zone below zero.');
        }

        return Event::from($ctx, 'stock.adjusted', [
            'adjust_id' => $p->optUuid('adjust_id') ?? (string) Str::uuid7(),
            'product_id' => $productId, 'zone_id' => $zone, ...$q, 'reason' => trim($reason), 'cause' => 'manual', 'count_id' => null,
        ], reason: trim($reason));
    }
}
