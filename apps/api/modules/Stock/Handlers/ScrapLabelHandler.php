<?php

declare(strict_types=1);

namespace Tanaad\Stock\Handlers;

use Tanaad\Config\Support\Payload;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;
use Tanaad\Shared\Ledger\Rejection;
use Tanaad\Stock\Commands\ScrapLabel;
use Tanaad\Stock\Support\StockBook;
use Tanaad\Stock\Support\StockReads;

/** Writes off an in-stock label (locked) with a mandatory reason; Labels marks it scrapped. */
final class ScrapLabelHandler implements Handler
{
    public function handle(Command $command): array
    {
        if (! $command instanceof ScrapLabel) {
            throw new \LogicException('Wrong command for '.self::class);
        }
        $ctx = $command->context();
        $siteId = $ctx->siteId ?? throw Rejection::invalidPayload(['site_id' => 'required']);
        $p = new Payload($command->payload);
        $labelId = $p->optUuid('label_id');
        $code = $p->optStr('code', 64);
        if ($labelId === null && $code === null) {
            $p->error('label_id', 'label_id or code is required');
        }
        $reason = $p->str('reason', 500);
        if (mb_strlen(trim($reason)) < 3) {
            $p->error('reason', 'must say why (at least 3 characters)');
        }
        $p->check();
        $label = StockReads::inStockLabel($siteId, $labelId, $code);
        StockReads::assertDayOpen($siteId, $ctx, $label->product_id, $label->zone_id);

        return [Event::from($ctx, 'stock.label_scrapped', [
            'label_id' => $label->id, 'code' => $label->code, 'product_id' => $label->product_id, 'from_zone_id' => $label->zone_id,
            'qty_units' => StockBook::qty($label->qty_units), 'qty_kg' => StockBook::qty($label->qty_kg), 'reason' => trim($reason),
        ], reason: trim($reason))];
    }
}
