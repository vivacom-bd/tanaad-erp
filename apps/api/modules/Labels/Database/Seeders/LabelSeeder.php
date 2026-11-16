<?php

declare(strict_types=1);

namespace Tanaad\Labels\Database\Seeders;

use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tanaad\Labels\Commands\ConsumeLabel;
use Tanaad\Labels\Commands\CreateLabel;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\CommandBus;
use Tanaad\Shared\Ledger\CommandContext;

/**
 * Labels for the finishing output of the last closed days, printed by the supervisor through the command bus and
 * read from the seeded production entries so they match the good units: mats bundles (a bundle per full
 * bundle_pieces of woven mats of a product), bags rolls (one per extruded roll) and packs (one per packed pack), recycling sacks
 * (one per pelletised sack). The older labels are mostly consumed by the store, the newest stay in stock.
 * Run after ProductionSeeder. Ids are uuid5 of names; codes come from the site's number series, so a second run is all duplicates.
 */
final class LabelSeeder extends Seeder
{
    private \Random\Randomizer $rng;

    public const string NAMESPACE = 'c5e30a96-12d8-5b47-9f60-7a1d4e8b2c35';

    private const int DAYS = 3;

    /** site => [user suffix, [stage => label kind]] */
    private const array SITES = [
        'mats' => ['mats', ['weave' => 'bundle']],
        'bags' => ['bags', ['extrude' => 'roll', 'convert' => 'pack']],
        'recycling' => ['rec', ['pelletise' => 'sack']],
    ];

    public static function id(string $name): string
    {
        return Uuid::uuid5(self::NAMESPACE, $name)->toString();
    }

    public function run(CommandBus $bus): void
    {
        $this->rng = new \Random\Randomizer(new \Random\Engine\Mt19937(20261112));
        /** @var list<array{string, Command}> $out */
        $out = [];
        foreach (self::SITES as $code => [$suffix, $stages]) {
            $site = (string) DB::table('sites')->where('code', $code)->value('id');
            $sup = (string) DB::table('users')->where('code', "u_sup_{$suffix}")->value('id');
            $store = (string) DB::table('users')->where('code', "u_store_{$suffix}")->value('id');
            $days = DB::table('production_shifts_closed')->where('site_id', $site)->distinct()->orderByDesc('day')->limit(self::DAYS)->pluck('day')->map(fn ($d): string => substr((string) $d, 0, 10))->sort()->values()->all();
            $products = DB::table('products')->where('site_id', $site)->get(['id', 'bundle_pieces', 'std_bundle_weight_kg'])->keyBy('id')->all();
            foreach ($days as $di => $day) {
                foreach ($stages as $stage => $kind) {
                    $entries = DB::table('production_entries')->where(['site_id' => $site, 'day' => $day, 'stage_code' => $stage])->orderBy('machine_id')->orderBy('shift_kind')->get();
                    $byProduct = [];
                    foreach ($entries as $e) {
                        if ($e->product_id === null || (int) $e->good_units <= 0) {
                            continue;
                        }
                        $byProduct[$e->product_id][] = $e;
                    }
                    ksort($byProduct);
                    foreach ($byProduct as $productId => $rows) {
                        $p = $products[$productId];
                        // Mat bundles: a bundle per full bundle_pieces of woven mats; rolls, packs and sacks: one per good unit.
                        $units = (int) array_sum(array_map(fn ($r): int => (int) $r->good_units, $rows));
                        $labels = $kind === 'bundle' ? intdiv($units, max(1, (int) $p->bundle_pieces)) : $units;
                        $qtyUnits = $kind === 'bundle' ? (int) $p->bundle_pieces : 1;
                        $std = (float) $p->std_bundle_weight_kg;
                        for ($i = 0; $i < $labels; $i++) {
                            $src = $rows[$i % count($rows)];
                            $name = "label:{$code}:{$day}:{$stage}:{$productId}:{$i}";
                            $at = sprintf('%sT19:%02d:%02d+03:00', $day, intdiv($i, 60) % 50 + 10, $i % 60);
                            $out[] = [$at, new CreateLabel($this->ctx($site, $sup, $at, $name), [
                                'label_id' => self::id($name), 'kind' => $kind, 'stage_code' => $stage, 'product_id' => $productId, 'machine_id' => $src->machine_id,
                                'operator_id' => $src->operator_id, 'shift_kind' => $src->shift_kind, 'day' => $day, 'qty_units' => $qtyUnits,
                                'qty_kg' => round($std * (1 + ($this->rng->getInt(-15, 15) / 1000)), 3), 'status' => 'in_stock',
                            ])];
                            // Everything but the newest day is mostly gone from stock again.
                            if ($di < count($days) - 1 && $this->rng->getInt(0, 9) < 7) {
                                $cat = CarbonImmutable::parse($day)->addDay()->format('Y-m-d').'T10:00:00+03:00';
                                $out[] = [$cat, new ConsumeLabel($this->ctx($site, $store, $cat, "consume:{$name}"), [
                                    'label_id' => self::id($name), 'consumed_by_stage' => 'store',
                                ])];
                            }
                        }
                    }
                }
            }
        }
        usort($out, fn (array $x, array $y): int => strcmp($x[0], $y[0]));
        // A command whose event is already in the ledger was seeded before: skip it (some handlers reject a repeated id).
        $done = array_flip(DB::table('events')->pluck('id')->all());
        foreach ($out as [, $command]) {
            if (! isset($done[(string) $command->context()->eventId])) {
                $bus->dispatch($command);
            }
        }
    }

    private function ctx(string $site, string $by, string $at, string $name): CommandContext
    {
        return new CommandContext($site, $by, null, CarbonImmutable::parse($at, 'Africa/Kampala'), self::id('event:'.$name));
    }
}
