<?php

declare(strict_types=1);

namespace Tanaad\Labels\Handlers;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Tanaad\Config\Support\Payload;
use Tanaad\Labels\Commands\CreateLabel;
use Tanaad\Labels\Models\Label;
use Tanaad\Labels\Support\LabelBook;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;
use Tanaad\Shared\Ledger\NumberSeries;
use Tanaad\Shared\Ledger\Rejection;

/**
 * Prints a label. The code is allocated here from the site's "label_<kind>" number series (or the
 * prototype format SITE-KIND-000123 when no series is defined) and travels in the event payload,
 * so it is the same after every Replay. A client's provisional code is never stored.
 */
final class CreateLabelHandler implements Handler
{
    public const float MAX_QTY = 1e7;

    public function __construct(private readonly NumberSeries $series, private readonly ConnectionInterface $db) {}

    public function handle(Command $command): array
    {
        if (! $command instanceof CreateLabel) {
            throw new \LogicException('Wrong command for '.self::class);
        }
        $ctx = $command->context();
        $siteId = $ctx->siteId ?? throw Rejection::invalidPayload(['site_id' => 'required']);
        $site = $this->db->table('sites')->where('id', $siteId)->first(['id', 'code', 'timezone']) ?? throw Rejection::notFound('Site', $siteId);
        $p = new Payload($command->payload);
        $labelId = $p->optUuid('label_id') ?? (string) Str::uuid7();
        $kind = $p->oneOf('kind', LabelBook::KINDS);
        $stage = $p->str('stage_code', 32);
        $productId = $p->optUuid('product_id');
        $machineId = $p->optUuid('machine_id');
        $operatorId = $p->optUuid('operator_id');
        $zoneId = $p->optUuid('zone_id');
        $status = $p->has('status') ? $p->oneOf('status', ['created', 'in_stock']) : 'in_stock';
        $day = $p->optDate('day') ?? $ctx->occurredAt->setTimezone((string) $site->timezone)->toDateString();
        $units = $p->has('qty_units') ? $p->num('qty_units') : 0.0;
        $kg = $p->has('qty_kg') ? $p->num('qty_kg') : 0.0;
        $shift = $p->optStr('shift_kind', 32) ?? $p->optStr('shift', 32);
        $colour = $p->optStr('colour', 64);
        $grade = $p->optStr('grade', 64);
        foreach (['qty_units' => $units, 'qty_kg' => $kg] as $key => $q) {
            if ($q > self::MAX_QTY) {
                $p->error($key, 'must be at most 10000000');
            }
        }
        $parents = $this->parents($p, $command->payload, $siteId);
        $p->check();

        $this->exists($p, 'products', 'product_id', $productId, $siteId);
        $this->exists($p, 'machines', 'machine_id', $machineId, $siteId);
        // People are group-wide (site_id is the home site): an operator must exist and not be deleted.
        $this->exists($p, 'people', 'operator_id', $operatorId, null);
        $this->exists($p, 'zones', 'zone_id', $zoneId, $siteId);
        if (! $this->db->table('stages')->where(['site_id' => $siteId, 'code' => $stage])->whereNull('deleted_at')->exists()) {
            $p->error('stage_code', 'no such stage at this site');
        }
        $p->check();

        if (Label::query()->whereKey($labelId)->exists()) {
            throw Rejection::duplicateFact("Label {$labelId} already exists.");
        }

        return [Event::from($ctx, 'labels.created', [
            'label_id' => $labelId,
            'code' => $this->allocate($siteId, (string) $site->code, $kind),
            'kind' => $kind,
            'product_id' => $productId,
            'stage_code' => $stage,
            'machine_id' => $machineId,
            'operator_id' => $operatorId,
            'shift_kind' => $shift,
            'day' => $day,
            'qty_units' => LabelBook::qty($units),
            'qty_kg' => LabelBook::qty($kg),
            'status' => $status,
            'zone_id' => $zoneId,
            'parent_ids' => $parents,
            'colour' => $colour,
            'grade' => $grade,
        ])];
    }

    private function allocate(string $siteId, string $siteCode, string $kind): string
    {
        $series = 'label_'.$kind;
        if (! $this->db->table('number_series')->where(['site_id' => $siteId, 'kind' => $series])->exists()) {
            $this->series->define($siteId, $series, LabelBook::abbr($siteCode).'-'.LabelBook::abbr($kind), 6, 1);
        }
        // number_series is not rewound by Replay, so an allocated number is never reissued; the code
        // travels in the event. (site_id, code) is unique: a clash here is a config error, not a retry.
        $code = $this->series->allocate($siteId, $series);
        if (Label::query()->where(['site_id' => $siteId, 'code' => $code])->exists()) {
            throw Rejection::stateConflict("Label code {$code} is already issued at this site; check the {$series} number series.");
        }

        return $code;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return list<string>
     */
    private function parents(Payload $p, array $raw, string $siteId): array
    {
        $ids = [];
        foreach (['parent_ids' => 'id', 'parent_codes' => 'code'] as $key => $col) {
            $list = $raw[$key] ?? null;
            if ($list === null) {
                continue;
            }
            if (! is_array($list) || ! array_is_list($list)) {
                $p->error($key, 'must be a list');

                continue;
            }
            foreach ($list as $i => $v) {
                $found = is_string($v) ? $this->db->table('labels')->where('site_id', $siteId)->where($col, $v)->value('id') : null;
                if (! is_string($found)) {
                    $p->error("{$key}.{$i}", 'is not a label of this site');

                    continue;
                }
                $ids[] = $found;
            }
        }

        return array_values(array_unique($ids));
    }

    private function exists(Payload $p, string $table, string $key, ?string $id, ?string $siteId): void
    {
        if ($id === null) {
            return;
        }
        $q = $this->db->table($table)->where('id', $id);
        if ($siteId !== null) {
            $q->where('site_id', $siteId);
        }
        $q->when(\Illuminate\Support\Facades\Schema::hasColumn($table, 'deleted_at'), fn ($w) => $w->whereNull('deleted_at'));
        if (! $q->exists()) {
            $p->error($key, 'not found');
        }
    }
}
