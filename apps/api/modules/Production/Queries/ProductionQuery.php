<?php

declare(strict_types=1);

namespace Tanaad\Production\Queries;

use Illuminate\Database\ConnectionInterface;
use Tanaad\Production\Support\ProductionBook;
use Tanaad\Shared\Arithmetic\Arithmetic;
use Tanaad\Shared\Money\Rounding;

/** Read shapes in the prototype's camelCase (selectors.ts machineWall, massBalanceDay, outputToday, yieldByLine). */
final class ProductionQuery
{
    public function __construct(private readonly ConnectionInterface $db, private readonly ProductionBook $book) {}

    /** @return list<array<string, mixed>> */
    public function machineWall(string $siteId, string $day, string $kind, ?string $stage = null): array
    {
        $stage ??= $this->book->wallStage($siteId);
        if ($stage === null) {
            return [];
        }
        $shiftId = $this->book->shiftId($siteId, $day, $kind);
        $entries = $this->db->table('production_entries')->where(['shift_id' => $shiftId, 'stage_code' => $stage])->get()->keyBy('machine_id');
        $products = $this->db->table('products')->where('site_id', $siteId)->get(['id', 'internal_name', 'commercial_name'])->keyBy('id');
        $progress = $this->book->progress($siteId, $day, $kind);
        $pctThreshold = $this->book->setting($siteId, 'benchmark_pct');
        $machines = $this->db->table('machines')->where(['site_id' => $siteId, 'stage_code' => $stage])->whereNull('deleted_at')
            ->orderByRaw('length(code), code')->get();
        $out = [];
        foreach ($machines as $m) {
            $e = $entries->has($m->id) ? $entries->get($m->id) : null;
            $productId = $e?->product_id;
            $bench = $e !== null ? $this->book->benchmark((string) $m->id, is_string($productId) ? $productId : null) : null;
            $expected = ProductionBook::expected($bench, $progress);
            $pct = $e === null ? null : ProductionBook::pctOfExpected((int) $e->good_units, $expected);
            $state = match (true) {
                $m->status === 'arriving' => 'arriving',
                $m->status === 'down' => 'down',
                $e === null => 'not_logged',
                $pct !== null && $pct < $pctThreshold && $e->downtime_reason === null => 'below',
                $pct !== null => 'ok',
                default => 'logged',
            };
            $product = is_string($productId) ? $products->get($productId) : null;
            $out[] = [
                'machine' => ['id' => $m->id, 'code' => $m->code, 'kind' => $m->kind, 'stageCode' => $m->stage_code, 'status' => $m->status,
                    'make' => $m->make, 'model' => $m->model, 'capacityKgPerDay' => $m->capacity_kg_per_day === null ? null : (float) $m->capacity_kg_per_day],
                'entry' => $e === null ? null : self::entry($e),
                'product' => $product === null ? null : ['id' => $product->id, 'internalName' => $product->internal_name, 'commercialName' => $product->commercial_name],
                'benchmark' => $bench, 'expectedSoFar' => $expected, 'progress' => $progress, 'operatorId' => $e?->operator_id,
                'pct' => $pct, 'goodUnits' => $e === null ? 0 : (int) $e->good_units,
                'downtime' => $e === null ? null : ['minutes' => (int) $e->downtime_min, 'reason' => $e->downtime_reason],
                'state' => $state,
            ];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function massBalance(string $siteId, string $day): array
    {
        return ['day' => $day, ...$this->book->massBalance($siteId, $day)];
    }

    /**
     * outputToday(): wall-stage entries of the day grouped by product.
     *
     * @return list<array<string, mixed>>
     */
    public function output(string $siteId, string $day): array
    {
        $stage = $this->book->wallStage($siteId);
        $by = [];
        foreach ($this->db->table('production_entries')->where(['site_id' => $siteId, 'day' => $day, 'stage_code' => $stage])->orderBy('id')->get() as $e) {
            $key = $e->product_id ?? 'all';
            $by[$key] ??= ['productId' => $e->product_id, 'units' => 0, 'kg' => 0.0, 'target' => 0.0];
            $by[$key]['units'] += (int) $e->good_units;
            $by[$key]['kg'] = Rounding::kg((string) ($by[$key]['kg'] + (float) $e->output_kg));
            $by[$key]['target'] += $this->book->benchmark((string) $e->machine_id, $e->product_id) ?? 0.0;
        }
        $rows = array_values($by);
        usort($rows, fn ($a, $b) => $b['units'] <=> $a['units']);

        return $rows;
    }

    /**
     * yieldByLine(): per wall-stage machine, kg in/out and yield of the day.
     *
     * @return list<array<string, mixed>>
     */
    public function yieldByLine(string $siteId, string $day): array
    {
        $stage = $this->book->wallStage($siteId);
        $rows = [];
        foreach ($this->db->table('machines')->where(['site_id' => $siteId, 'stage_code' => $stage])->where('status', '!=', 'retired')->whereNull('deleted_at')->orderBy('code')->get() as $m) {
            $q = $this->db->table('production_entries')->where(['machine_id' => $m->id, 'day' => $day, 'stage_code' => $stage]);
            $in = Rounding::kg((string) ((clone $q)->sum('input_kg') ?: '0'));
            $outKg = Rounding::kg((string) ($q->sum('output_kg') ?: '0'));
            $rows[] = ['machineId' => $m->id, 'code' => $m->code, 'inKg' => $in, 'outKg' => $outKg,
                'yieldPct' => Arithmetic::yieldPct($in, $outKg), 'capacityKg' => (float) ($m->capacity_kg_per_day ?? 0)];
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    public static function entry(\stdClass $e): array
    {
        $labels = is_string($e->label_codes ?? null) ? json_decode($e->label_codes, true) : [];

        return [
            'id' => $e->id, 'siteId' => $e->site_id, 'shiftId' => $e->shift_id, 'day' => substr((string) $e->day, 0, 10), 'machineId' => $e->machine_id,
            'stageCode' => $e->stage_code, 'productId' => $e->product_id, 'operatorId' => $e->operator_id,
            'goodUnits' => (int) $e->good_units, 'rejectUnits' => (int) $e->reject_units, 'inputKg' => (float) $e->input_kg, 'outputKg' => (float) $e->output_kg,
            'downtimeMin' => (int) $e->downtime_min, 'downtimeReason' => $e->downtime_reason, 'labelCodes' => is_array($labels) ? $labels : [],
            'at' => $e->at,
        ];
    }
}
