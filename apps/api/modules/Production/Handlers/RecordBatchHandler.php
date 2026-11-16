<?php

declare(strict_types=1);

namespace Tanaad\Production\Handlers;

use Brick\Math\BigDecimal;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Tanaad\Config\Support\Payload;
use Tanaad\Production\Commands\RecordBatch;
use Tanaad\Production\Support\ProductionBook;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;
use Tanaad\Shared\Ledger\Rejection;
use Tanaad\Shared\Money\Rounding;

/**
 * A mixer batch: {batch_id?, day, kind?, machine_id?, product_id?, lines: [{material, kg}], output_kg, label_code?, note?}.
 * material is free text or a ref item code as the client keeps it; input_kg is the sum of the lines.
 */
final class RecordBatchHandler implements Handler
{
    public function __construct(private readonly ProductionBook $book, private readonly ConnectionInterface $db) {}

    public function handle(Command $command): array
    {
        if (! $command instanceof RecordBatch) {
            throw new \LogicException('Wrong command for '.self::class);
        }
        $ctx = $command->context();
        $siteId = $ctx->siteId ?? throw Rejection::invalidPayload(['site_id' => 'required']);
        $p = new Payload(ShiftInput::flatten($command->payload));
        [$day, $kind] = ShiftInput::read($p, $this->book, $siteId, kindRequired: false);
        $batchId = $p->optUuid('batch_id') ?? (string) Str::uuid7();
        $machineId = $p->optUuid('machine_id');
        $productId = $p->optUuid('product_id');
        $outKg = $p->num('output_kg');
        $label = $p->optStr('label_code', 64);
        $note = $p->optStr('note', 1000);
        $lines = [];
        $total = BigDecimal::zero();
        $children = $p->children('lines');
        if ($children === [] && ! $p->has('lines')) {
            $p->error('lines', 'required');
        }
        foreach ($children as $line) {
            $kg = $line->num('kg', positive: true);
            if ($kg > 1e7) {
                $line->error('kg', 'must be at most 10000000');
            }
            $lines[] = ['material' => $line->str('material', 120), 'kg' => Rounding::kg($kg)];
            $total = $total->plus(BigDecimal::of((string) $kg));
        }
        if ($outKg > 1e7) {
            $p->error('output_kg', 'must be at most 10000000');
        }
        if ($total->isGreaterThan(10_000_000)) {
            $p->error('lines', 'total kg must be at most 10000000');
        }
        $p->check();
        if ($lines === []) {
            throw Rejection::invalidPayload(['lines' => 'at least one recipe line']);
        }
        if ($machineId !== null && $this->book->machine($siteId, $machineId) === null) {
            throw Rejection::invalidPayload(['machine_id' => 'no such machine at this site']);
        }
        if ($productId !== null && ! $this->book->productExists($siteId, $productId)) {
            throw Rejection::invalidPayload(['product_id' => 'no such product at this site']);
        }
        if ($this->db->table('production_batches')->where('id', $batchId)->exists()) {
            throw Rejection::duplicateFact("Batch {$batchId} already exists.");
        }

        return [Event::from($ctx, 'production.batch_recorded', [
            'batch_id' => $batchId, 'day' => $day, 'shift_id' => $kind === '' ? null : $this->book->shiftId($siteId, $day, $kind),
            'machine_id' => $machineId, 'product_id' => $productId, 'lines' => $lines,
            'input_kg' => Rounding::kg($total), 'output_kg' => Rounding::kg($outKg), 'label_code' => $label, 'note' => $note,
        ])];
    }
}
