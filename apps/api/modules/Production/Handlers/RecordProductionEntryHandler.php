<?php

declare(strict_types=1);

namespace Tanaad\Production\Handlers;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Tanaad\Config\Support\Payload;
use Tanaad\Production\Commands\RecordProductionEntry;
use Tanaad\Production\Support\ProductionBook;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;
use Tanaad\Shared\Ledger\Rejection;
use Tanaad\Shared\Money\Rounding;

/**
 * recordProduction() of the prototype: one entry per (shift, machine, stage). A re-log replaces
 * the earlier row and keeps its id. The event carries the benchmark facts at the time of
 * recording (benchmark, benchmark_pct, below_benchmark) so policies and replay need no lookups.
 */
final class RecordProductionEntryHandler implements Handler
{
    public const int MAX_UNITS = 10_000_000;

    public const float MAX_KG = 1e7;

    public function __construct(private readonly ProductionBook $book, private readonly ConnectionInterface $db) {}

    public function handle(Command $command): array
    {
        if (! $command instanceof RecordProductionEntry) {
            throw new \LogicException('Wrong command for '.self::class);
        }
        $ctx = $command->context();
        $siteId = $ctx->siteId ?? throw Rejection::invalidPayload(['site_id' => 'required']);
        $p = new Payload(ShiftInput::flatten($command->payload));
        [$day, $kind] = ShiftInput::read($p, $this->book, $siteId);
        $machineId = $p->uuid('machine_id');
        $stage = $p->optStr('stage_code', 32);
        $productId = $p->optUuid('product_id');
        $operatorId = $p->optUuid('operator_id');
        $good = $p->int('good_units', 0, 0);
        $reject = $p->int('reject_units', 0, 0);
        $inKg = $p->optNum('input_kg') ?? 0.0;
        $outKg = $p->optNum('output_kg') ?? 0.0;
        $downMin = $p->int('downtime_min', 0, 0);
        $reason = $p->optStr('downtime_reason', 255);
        $labels = $command->payload['label_codes'] ?? [];
        if (! is_array($labels) || ! array_is_list($labels) || array_filter($labels, fn ($c) => ! is_string($c) || $c === '' || mb_strlen($c) > 64) !== []) {
            $p->error('label_codes', 'must be a list of label codes');
            $labels = [];
        }
        $entryId = $p->optUuid('entry_id');
        foreach (['good_units' => $good, 'reject_units' => $reject, 'downtime_min' => $downMin] as $key => $n) {
            if ($n > self::MAX_UNITS) {
                $p->error($key, 'must be at most 10000000');
            }
        }
        foreach (['input_kg' => $inKg, 'output_kg' => $outKg] as $key => $kg) {
            if ($kg > self::MAX_KG) {
                $p->error($key, 'must be at most 10000000');
            }
        }
        $p->check();

        // Lock the machine row: two first logs of one (shift, machine, stage) serialise here instead of
        // colliding on the unique index; the second one then sees the first and replaces it.
        $this->db->table('machines')->where(['id' => $machineId, 'site_id' => $siteId])->lockForUpdate()->value('id');
        $machine = $this->book->machine($siteId, $machineId) ?? throw Rejection::invalidPayload(['machine_id' => 'no such machine at this site']);
        $stage ??= (string) $machine->stage_code;
        if (! $this->book->stageExists($siteId, $stage)) {
            throw Rejection::invalidPayload(['stage_code' => 'no such stage at this site']);
        }
        if ($productId !== null && ! $this->book->productExists($siteId, $productId)) {
            throw Rejection::invalidPayload(['product_id' => 'no such product at this site']);
        }
        $shiftId = $this->book->shiftId($siteId, $day, $kind);
        $existing = $this->db->table('production_entries')->where(['shift_id' => $shiftId, 'machine_id' => $machineId, 'stage_code' => $stage])->value('id');
        if (is_string($existing)) {
            $entryId = $existing;
        } else {
            $entryId ??= (string) Str::uuid7();
            if ($this->db->table('production_entries')->where('id', $entryId)->exists()) {
                throw Rejection::duplicateFact("Entry {$entryId} already exists for another machine or shift.");
            }
        }
        $benchmark = $this->book->benchmark($machineId, $productId);
        $pct = $this->book->setting($siteId, 'benchmark_pct');
        // As the machine wall: a shift in progress is judged against the benchmark scaled by how far
        // into the shift the entry was recorded; a past shift against the full benchmark.
        $progress = $this->book->progress($siteId, $day, $kind, $ctx->occurredAt);
        $expected = ProductionBook::expected($benchmark, $progress);
        $ofExpected = ProductionBook::pctOfExpected($good, $expected);

        return [Event::from($ctx, 'production.entry_recorded', [
            'entry_id' => $entryId, 'replaces' => is_string($existing), 'shift_id' => $shiftId, 'day' => $day, 'shift_kind' => $kind,
            'machine_id' => $machineId, 'stage_code' => $stage, 'product_id' => $productId, 'operator_id' => $operatorId,
            'good_units' => $good, 'reject_units' => $reject, 'input_kg' => Rounding::kg($inKg), 'output_kg' => Rounding::kg($outKg),
            'downtime_min' => $downMin, 'downtime_reason' => $reason, 'label_codes' => $labels,
            'benchmark' => $benchmark, 'benchmark_pct' => $pct, 'progress' => $progress, 'expected_units' => $expected,
            'below_benchmark' => $ofExpected !== null && $ofExpected < $pct,
        ])];
    }
}
