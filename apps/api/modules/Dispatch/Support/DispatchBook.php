<?php

declare(strict_types=1);

namespace Tanaad\Dispatch\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\ConnectionInterface;
use Tanaad\Config\Support\Payload;
use Tanaad\Dispatch\Models\Dispatch;
use Tanaad\Shared\Ledger\NumberSeries;
use Tanaad\Shared\Ledger\Rejection;

/** Dispatch vocabulary and the handle-time reads every dispatch handler shares. */
final class DispatchBook
{
    public const array STATUSES = ['pending', 'pass', 'hold', 'overridden'];

    /** Gate statuses that still accept scanning and (re-)weighing. */
    public const array OPEN = ['pending', 'hold'];

    /** Gate statuses after which the lorry has left with a delivery note. */
    public const array RELEASED = ['pass', 'overridden'];

    public const float MAX_KG = 1e7;

    public const int MAX_BUNDLES = 100000;

    public const string SERIES = 'delivery_note';

    public function __construct(private readonly ConnectionInterface $db, private readonly NumberSeries $series) {}

    /** Weights are stored at 3 decimals, exactly. */
    public static function kg(float|int|string $v): string
    {
        return BigDecimal::of((string) $v)->toScale(3, RoundingMode::HalfUp)->__toString();
    }

    public static function siteId(?string $siteId): string
    {
        return $siteId ?? throw Rejection::invalidPayload(['site_id' => 'required']);
    }

    /** The dispatch row of this site, locked for the rest of the command transaction. */
    public function lockDispatch(Payload $p, string $siteId): Dispatch
    {
        $id = $p->uuid('dispatch_id');
        $p->check();
        $row = Dispatch::query()->where(['id' => $id, 'site_id' => $siteId])->lockForUpdate()->first();

        return $row ?? throw Rejection::notFound('Dispatch', $id);
    }

    /** site_settings.gate_tolerance_pct; a site with no settings row cannot weigh (stale_config). */
    public function tolerancePct(string $siteId): float
    {
        $v = $this->db->table('site_settings')->where('site_id', $siteId)->whereNull('deleted_at')->value('gate_tolerance_pct');
        if (! is_numeric($v)) {
            throw Rejection::staleConfig('No gate tolerance is configured for this site.');
        }

        return abs((float) $v);
    }

    /** @return list<object{label_id: string, code: string, product_id: ?string, scanned_by: ?string}> */
    public function bundles(string $dispatchId): array
    {
        /** @var list<object{label_id: string, code: string, product_id: ?string, scanned_by: ?string}> */
        return $this->db->table('dispatch_bundles')->where('dispatch_id', $dispatchId)->orderBy('seq')->orderBy('id')
            ->get(['label_id', 'code', 'product_id', 'scanned_by'])->all();
    }

    /**
     * Locks every loaded label (cross-module read of `labels` by table name) and requires each to
     * still be in stock: a label consumed, scrapped or moved off since it was scanned cannot leave
     * on this lorry (409 state_conflict naming the codes; nothing is emitted).
     *
     * @param  list<object{label_id: string, code: string, product_id: ?string, scanned_by: ?string}>  $bundles
     */
    public function assertLoadable(array $bundles): void
    {
        if ($bundles === []) {
            return;
        }
        $ids = array_map(fn (object $b): string => $b->label_id, $bundles);
        $rows = $this->db->table('labels')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get(['id', 'status'])->keyBy('id');
        $bad = [];
        foreach ($bundles as $b) {
            if (($rows[$b->label_id]->status ?? null) !== 'in_stock') {
                $bad[] = $b->code;
            }
        }
        if ($bad !== []) {
            throw Rejection::stateConflict('Labels no longer in stock: '.implode(', ', $bad).'. Unscan them before the gate.');
        }
    }

    /**
     * Allocates the next delivery note number from the site's paper-book series (number_series
     * "delivery_note", whose `next` continues the printed book). Called only at gate pass/override,
     * under the series row lock; the number travels in the event payload so Replay keeps it.
     */
    public function allocateNote(string $siteId): string
    {
        $no = $this->series->allocate($siteId, self::SERIES);
        if ($this->db->table('delivery_notes')->where(['site_id' => $siteId, 'number' => $no])->exists()) {
            throw Rejection::stateConflict("Delivery note {$no} is already issued at this site; check the delivery_note number series.");
        }

        return $no;
    }

    /**
     * @param  list<object{label_id: string, code: string, product_id: ?string, scanned_by: ?string}>  $bundles
     * @return list<array{label_id: string, code: string, dispatch_id: string, delivery_note_no: string}>
     */
    public static function labelFacts(array $bundles, string $dispatchId, string $no): array
    {
        return array_map(fn (object $b): array => ['label_id' => $b->label_id, 'code' => $b->code, 'dispatch_id' => $dispatchId, 'delivery_note_no' => $no], $bundles);
    }
}
