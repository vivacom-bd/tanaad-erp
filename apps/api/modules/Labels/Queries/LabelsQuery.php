<?php

declare(strict_types=1);

namespace Tanaad\Labels\Queries;

use Illuminate\Database\ConnectionInterface;
use Tanaad\Labels\Models\Label;
use Tanaad\Labels\Support\LabelBook;
use Tanaad\Shared\Ledger\Rejection;

/** Label read shapes in the prototype's camelCase (selectors.ts labelHistory, stockByProduct). */
final class LabelsQuery
{
    public function __construct(private readonly ConnectionInterface $db) {}

    /** @return array<string, mixed> */
    public function history(Label $label): array
    {
        $product = $label->product_id === null ? null : $this->db->table('products')->where('id', $label->product_id)->first();
        $machine = $label->machine_id === null ? null : $this->db->table('machines')->where('id', $label->machine_id)->first();
        $operator = $label->operator_id === null ? null : $this->db->table('people')->where('id', $label->operator_id)->first();
        $parents = Label::query()->whereIn('id', $label->parent_ids)->orderBy('seq')->get();
        $timeline = $this->db->table('label_history')->where('label_id', $label->id)->orderBy('seq')->orderBy('id')->get();

        return [
            'label' => self::shape($label),
            'product' => $product === null ? null : ['id' => $product->id, 'code' => $product->code, 'name' => $product->commercial_name ?? $product->internal_name, 'internalName' => $product->internal_name],
            'machine' => $machine === null ? null : ['id' => $machine->id, 'code' => $machine->code, 'stageCode' => $machine->stage_code],
            'operator' => $operator === null ? null : ['id' => $operator->id, 'fullName' => $operator->full_name ?? null],
            'parents' => array_values($parents->map(fn (Label $l): array => self::shape($l))->all()),
            'history' => array_values($timeline->map(fn (object $h): array => [
                'type' => $h->type, 'at' => \Carbon\CarbonImmutable::parse($h->at)->toIso8601String(), 'by' => $h->by,
                'data' => json_decode((string) $h->data, true) ?: (object) [],
            ])->all()),
        ];
    }

    /** @return array<string, mixed> */
    public function list(string $siteId, ?string $status, ?string $kind, ?string $day, int $page, int $perPage): array
    {
        foreach (['status' => [$status, LabelBook::STATUSES], 'kind' => [$kind, LabelBook::KINDS]] as $name => [$v, $allowed]) {
            if ($v !== null && ! in_array($v, $allowed, true)) {
                throw Rejection::invalidPayload([$name => 'must be one of: '.implode(', ', $allowed)]);
            }
        }
        if ($day !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1) {
            throw Rejection::invalidPayload(['day' => 'must be a date (YYYY-MM-DD)']);
        }
        $q = Label::query()->where('site_id', $siteId)
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            ->when($kind !== null, fn ($q) => $q->where('kind', $kind))
            ->when($day !== null, fn ($q) => $q->where('day', $day));
        $total = (clone $q)->count();
        $rows = $q->orderByDesc('seq')->forPage($page, $perPage)->get();

        return [
            'data' => array_values($rows->map(fn (Label $l): array => self::shape($l))->all()),
            'page' => $page, 'perPage' => $perPage, 'total' => $total,
        ];
    }

    /**
     * In-stock labels per product, most bundles first (selectors.ts stockByProduct).
     *
     * @return list<array<string, mixed>>
     */
    public function stock(string $siteId): array
    {
        $labels = Label::query()->where(['site_id' => $siteId, 'status' => 'in_stock'])->whereNotNull('product_id')->get();
        $by = [];
        foreach ($labels as $l) {
            $pid = (string) $l->product_id;
            $by[$pid] ??= ['bundles' => 0, 'pieces' => \Brick\Math\BigDecimal::zero(), 'kg' => \Brick\Math\BigDecimal::zero(), 'zones' => []];
            $by[$pid]['bundles']++;
            $by[$pid]['pieces'] = $by[$pid]['pieces']->plus($l->qty_units);
            $by[$pid]['kg'] = $by[$pid]['kg']->plus($l->qty_kg);
            if ($l->zone_id !== null) {
                $by[$pid]['zones'][$l->zone_id] = ($by[$pid]['zones'][$l->zone_id] ?? 0) + 1;
            }
        }
        $products = $this->db->table('products')->whereIn('id', array_keys($by))->get()->keyBy('id');
        $out = [];
        foreach ($by as $pid => $row) {
            $prod = $products[$pid] ?? null;
            $out[] = [
                'product' => ['id' => $pid, 'code' => $prod->code ?? null, 'name' => $prod === null ? null : ($prod->commercial_name ?? $prod->internal_name)],
                'bundles' => $row['bundles'], 'pieces' => LabelBook::num((string) $row['pieces']), 'kg' => LabelBook::num((string) $row['kg']),
                'zones' => (object) $row['zones'],
            ];
        }
        usort($out, fn (array $a, array $b): int => $b['bundles'] <=> $a['bundles']);

        return $out;
    }

    /** @return array<string, mixed> */
    public static function shape(Label $l): array
    {
        return [
            'id' => $l->id, 'code' => $l->code, 'siteId' => $l->site_id, 'kind' => $l->kind, 'productId' => $l->product_id,
            'stageCode' => $l->stage_code, 'machineId' => $l->machine_id, 'operatorId' => $l->operator_id, 'shiftKind' => $l->shift_kind,
            'day' => $l->dayString(), 'qtyUnits' => LabelBook::num($l->qty_units), 'qtyKg' => LabelBook::num($l->qty_kg),
            'status' => $l->status, 'zoneId' => $l->zone_id, 'parentIds' => $l->parent_ids, 'colour' => $l->colour, 'grade' => $l->grade,
            'consumedByStage' => $l->consumed_by_stage, 'consumedRef' => $l->consumed_ref,
            'at' => $l->at->toIso8601String(), 'by' => $l->created_by ?? null,
        ];
    }
}
