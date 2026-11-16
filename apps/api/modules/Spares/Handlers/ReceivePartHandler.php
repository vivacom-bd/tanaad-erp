<?php

declare(strict_types=1);

namespace Tanaad\Spares\Handlers;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Tanaad\Config\Support\Payload;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;
use Tanaad\Shared\Ledger\Rejection;
use Tanaad\Spares\Commands\ReceivePart;
use Tanaad\Spares\Support\Spares;

/**
 * Parts into the store. The part row is locked, then the weighted average cost and the stock
 * after the receipt are computed and carried in the event (replay reproduces them, and the
 * low_stock exception is settled from them without reading the parts table).
 */
final class ReceivePartHandler implements Handler
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function handle(Command $command): array
    {
        if (! $command instanceof ReceivePart) {
            throw new \LogicException('Wrong command for '.self::class);
        }
        $ctx = $command->context();
        $siteId = $ctx->siteId ?? throw Rejection::invalidPayload(['site_id' => 'required']);
        $p = new Payload($command->payload);
        $partId = $p->uuid('part_id');
        $receiptId = $p->optUuid('receipt_id') ?? (string) Str::uuid7();
        $qty = Spares::qty($p, 'qty', true);
        $cost = $p->optNum('unit_cost');
        $supplier = $p->optStr('supplier', 160);
        if ($cost !== null && $cost > Spares::MAX_COST) {
            $p->error('unit_cost', 'must be at most '.Spares::MAX_COST);
        }
        $p->check();

        $part = Spares::lockedPart($this->db, $siteId, $partId);
        if ($this->db->table('part_receipts')->where('id', $receiptId)->exists()) {
            throw Rejection::duplicateFact("Receipt {$receiptId} is already recorded.");
        }
        $onHand = BigDecimal::of((string) $part->on_hand);
        $avg = BigDecimal::of((string) $part->unit_cost);
        $qtyD = BigDecimal::of(Spares::dec($qty));
        $unitCost = $cost === null ? $avg : BigDecimal::of(Spares::dec($cost));
        $after = $onHand->plus($qtyD);
        $newAvg = $onHand->multipliedBy($avg)->plus($qtyD->multipliedBy($unitCost))->dividedBy($after, 4, RoundingMode::HalfUp);

        return [Event::from($ctx, 'spares.part_received', [
            'receipt_id' => $receiptId, 'part_id' => $partId, 'part_code' => (string) $part->code, 'part_name' => (string) $part->name,
            'qty' => $qty, 'supplier' => $supplier, 'unit_cost' => $unitCost->toFloat(),
            'avg_cost_after' => $newAvg->toFloat(), 'on_hand_after' => $after->toFloat(), 'min_level' => (float) $part->min_level,
        ])];
    }
}
