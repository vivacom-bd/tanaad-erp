<?php

declare(strict_types=1);

namespace Tanaad\Production\Database\Seeders;

use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tanaad\Production\Commands\CloseShift;
use Tanaad\Production\Commands\RecordProductionEntry;
use Tanaad\Production\Commands\WeighWaste;
use Tanaad\Production\Support\ProductionBook;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\CommandBus;
use Tanaad\Shared\Ledger\CommandContext;

/**
 * Ten working days of production (Sundays off) up to today, Tuesday 10 November 2026, at each site, through the
 * command bus as its supervisor: one entry per machine and shift for the machines that are logged (the wall
 * stage, the input stage and, at bags, the converters), output near the benchmark, a few shifts under it with
 * and without a downtime reason (the reason is a downtime_reason ref item of the site), waste weighings that make
 * the day's mass balance close (one mats day is left 5% out), and a close for every past shift. Today's day shift
 * stays open with machines still unlogged. Recycling's washing line is fed from the seeded collector deliveries.
 * Run after RecyclingSeeder. Ids are uuid5 of names, so a second run is all duplicates.
 */
final class ProductionSeeder extends Seeder
{
    private \Random\Randomizer $rng;

    public const string NAMESPACE = 'a4c81e07-93b2-5d6f-8e21-4f7b05c9d3a8';

    private const string TODAY = '2026-11-10';

    /** site => [user suffix, stage codes that are logged, label kind of the product per stage] */
    private const array SITES = [
        'mats' => ['mats', ['mixer', 'weave'], ['weave' => 'mat']],
        'bags' => ['bags', ['extrude', 'convert'], ['extrude' => 'roll', 'convert' => 'bag_pack']],
        'recycling' => ['rec', ['wash', 'pelletise'], ['pelletise' => 'pellet']],
    ];

    public static function id(string $name): string
    {
        return Uuid::uuid5(self::NAMESPACE, $name)->toString();
    }

    /** @return list<string> */
    private static function days(): array
    {
        $out = [];
        $d = CarbonImmutable::parse(self::TODAY);
        while (count($out) < 11) {
            if ($d->dayOfWeek !== 0) {
                $out[] = $d->toDateString();
            }
            $d = $d->subDay();
        }

        return array_reverse($out);
    }

    public function run(CommandBus $bus, ProductionBook $book): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse(self::TODAY.' 12:00:00', 'Africa/Kampala'));
        $this->rng = new \Random\Randomizer(new \Random\Engine\Mt19937(20261111));

        try {
            /** @var list<array{string, Command}> $out */
            $out = [];
            foreach (self::SITES as $code => [$suffix, $stages, $kinds]) {
                $out = [...$out, ...$this->site($book, $code, $suffix, $stages, $kinds)];
            }
            usort($out, fn (array $x, array $y): int => strcmp($x[0], $y[0]));
            // A command whose event is already in the ledger was seeded before: skip it (some handlers reject a repeated id).
            $done = array_flip(DB::table('events')->pluck('id')->all());
            foreach ($out as [, $command]) {
                if (! isset($done[(string) $command->context()->eventId])) {
                    $bus->dispatch($command);
                }
            }
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    /**
     * @param  list<string>  $stages
     * @param  array<string, string>  $kinds
     * @return list<array{string, Command}>
     */
    private function site(ProductionBook $book, string $code, string $suffix, array $stages, array $kinds): array
    {
        $site = (string) DB::table('sites')->where('code', $code)->value('id');
        $sup = (string) DB::table('users')->where('code', "u_sup_{$suffix}")->value('id');
        $wall = $book->wallStage($site);
        $input = $book->inputStage($site);
        $operators = DB::table('people')->where(['site_id' => $site, 'status' => 'active'])->orderBy('full_name')->orderBy('id')->limit(40)->pluck('id')->all();
        $downtime = DB::table('ref_items')->where(['list' => 'downtime_reason', 'active' => true, 'site_id' => $site])->whereNull('deleted_at')
            ->orderBy('sort_order')->pluck('label')->filter(fn ($l): bool => strtolower((string) $l) !== 'other')->values()->all();
        $wasteKinds = DB::table('ref_items')->where(['list' => ProductionBook::WASTE_LIST, 'active' => true])->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('site_id', $site)->orWhereNull('site_id'))->orderBy('sort_order')->pluck('code')->all();
        $machines = [];
        foreach ($stages as $stage) {
            $machines[$stage] = DB::table('machines')->where(['site_id' => $site, 'stage_code' => $stage, 'status' => 'active'])->whereNull('deleted_at')
                ->orderBy('code')->get(['id', 'code'])->all();
        }
        $products = [];
        foreach ($kinds as $stage => $kind) {
            $products[$stage] = DB::table('products')->where(['site_id' => $site, 'kind' => $kind, 'active' => true])->whereNull('deleted_at')
                ->orderBy('code')->get(['id', 'bundle_pieces', 'std_bundle_weight_kg'])->all();
        }
        $bench = DB::table('benchmarks')->whereNull('deleted_at')->get(['machine_id', 'product_id', 'units_per_shift'])
            ->mapWithKeys(fn ($r): array => [$r->machine_id.'|'.$r->product_id => (float) $r->units_per_shift])->all();
        $ctx = fn (string $at, string $name): CommandContext => new CommandContext($site, $sup, null, CarbonImmutable::parse($at, 'Africa/Kampala'), self::id("event:{$code}:{$name}"));
        $days = self::days();
        $gapDay = $days[count($days) - 5];

        $out = [];
        foreach ($days as $di => $day) {
            foreach (['day', 'night'] as $kind) {
                $today = $day === self::TODAY;
                if ($today && $kind === 'night') {
                    continue;
                }
                $stamp = $today ? self::TODAY.'T10:40:00+03:00' : ($kind === 'day' ? "{$day}T18:30:00+03:00" : CarbonImmutable::parse($day)->addDay()->toDateString().'T06:30:00+03:00');
                $closeAt = CarbonImmutable::parse(substr($stamp, 0, 10).' 00:00')->format('Y-m-d').'T'.($kind === 'day' ? '19:05' : '07:05').':00+03:00';
                $recent = $di >= count($days) - 4;
                $rows = [];
                $outKg = 0.0;
                foreach ($stages as $stage) {
                    if ($stage === $input && $stage !== $wall) {
                        continue;
                    }
                    $list = $machines[$stage];
                    // Today's open shift leaves two of the wall machines (one when there are only two) not logged yet.
                    $skip = $today && $stage === $wall ? min(2, max(1, count($list) - 1)) : 0;
                    foreach (array_slice($list, 0, count($list) - $skip) as $mi => $m) {
                        $pool = $products[$stage] ?? [];
                        $product = $pool === [] ? null : $pool[($mi + $di + ($kind === 'night' ? 1 : 0)) % count($pool)];
                        $b = $product === null ? null : (float) ($bench["{$m->id}|{$product->id}"] ?? 0);
                        $reason = null;
                        $downMin = 0;
                        if ($b !== null && $b > 0) {
                            $f = 0.86 + $this->rng->getInt(0, 24) / 100;
                            // A few shifts well under the benchmark: with a reason, or without (the recent ones stay open).
                            $pick = $this->rng->getInt(0, 99);
                            if ($pick < 4 && $downtime !== []) {
                                $f = 0.4 + $this->rng->getInt(0, 25) / 100;
                                $reason = $downtime[$this->rng->getInt(0, count($downtime) - 1)];
                                $downMin = $this->rng->getInt(90, 300);
                            } elseif ($recent && ! $today && $pick >= 93) {
                                $f = 0.45 + $this->rng->getInt(0, 20) / 100;
                            }
                            $units = (int) round($b * $f);
                            $kg = round($units * (float) $product->std_bundle_weight_kg / max(1, (int) $product->bundle_pieces), 3);
                            $in = $kg;
                        } elseif ($product !== null && $stage === $wall && $code === 'recycling') {
                            $kg = 0.0;
                            $units = 0;
                            $in = 0.0;
                        } elseif ($product !== null) {
                            $units = $stage === 'convert' ? $this->rng->getInt(14, 22) : $this->rng->getInt(3, 5);
                            $kg = round($units * (float) $product->std_bundle_weight_kg, 3);
                            $in = round($kg * 1.03, 3);
                            if ($downtime !== [] && $this->rng->getInt(0, 9) === 0) {
                                $reason = $downtime[$this->rng->getInt(0, count($downtime) - 1)];
                                $downMin = $this->rng->getInt(30, 120);
                            }
                        } else {
                            continue;
                        }
                        $rows[] = new ShiftRow($stage, (string) $m->id, (string) $product->id, $units, $kg, $in, $reason, $downMin, (float) $product->std_bundle_weight_kg);
                        if ($stage === $wall) {
                            $outKg += $kg;
                        }
                    }
                }
                // Recycling: the washing line takes this shift's share of the day's deliveries; the pelletisers turn 92% of it into sacks.
                $washKg = 0.0;
                if ($code === 'recycling') {
                    $net = (float) DB::table('collector_deliveries')->where(['site_id' => $site, 'day' => $day])->sum('net_kg');
                    $washKg = round($net * ($today ? 1.0 : ($kind === 'day' ? 0.6 : 0.4)), 3);
                    $wallRows = array_keys(array_filter($rows, fn (ShiftRow $r): bool => $r->stage === $wall));
                    foreach ($wallRows as $k) {
                        $share = $washKg * 0.92 / count($wallRows);
                        $sack = $rows[$k]->unitKg > 0 ? $rows[$k]->unitKg : 25.0;
                        $rows[$k]->units = (int) floor($share / $sack);
                        $rows[$k]->kg = round($rows[$k]->units * $sack, 3);
                        $rows[$k]->in = round($share, 3);
                        $outKg += $rows[$k]->kg;
                    }
                }
                $waste = $wasteKinds === [] ? 0.0 : round($outKg * (0.012 + $this->rng->getInt(0, 10) / 1000), 3);
                $feed = round($outKg + $waste, 3);
                if ($day === $gapDay && $code === 'mats') {
                    $feed = round($feed * 1.05, 3);
                }
                if ($input !== null && $input !== $wall) {
                    foreach ($machines[$input] as $m) {
                        $rows[] = new ShiftRow($input, (string) $m->id, null, 0, $code === 'recycling' ? round($washKg * 0.97, 3) : $feed, $code === 'recycling' ? $washKg : $feed, null, 0, 0.0);
                        break;
                    }
                } elseif ($input === $wall) {
                    // The input stage is the wall (extruders): each machine's input is its output plus its share of this shift's waste.
                    $wallRows = array_keys(array_filter($rows, fn (ShiftRow $r): bool => $r->stage === $wall));
                    $left = $feed;
                    foreach ($wallRows as $n => $k) {
                        $rows[$k]->in = $n === count($wallRows) - 1 ? round($left, 3) : round($rows[$k]->kg + $waste / count($wallRows), 3);
                        $left -= $rows[$k]->in;
                    }
                }
                foreach ($rows as $n => $r) {
                    $name = "entry:{$day}:{$kind}:{$r->machine}";
                    $at = CarbonImmutable::parse($stamp)->addSeconds($n)->format('Y-m-d\TH:i:sP');
                    $payload = [
                        'entry_id' => self::id($name), 'day' => $day, 'kind' => $kind, 'machine_id' => $r->machine, 'stage_code' => $r->stage, 'product_id' => $r->product,
                        'operator_id' => $operators === [] ? null : $operators[($n + $di) % count($operators)],
                        'good_units' => $r->units, 'reject_units' => $r->units > 0 ? $this->rng->getInt(0, max(1, intdiv($r->units, 25))) : 0,
                        'input_kg' => $r->in, 'output_kg' => $r->kg, 'downtime_min' => $r->down, 'downtime_reason' => $r->reason,
                    ];
                    $out[] = [$at, new RecordProductionEntry($ctx($at, $name), $payload)];
                }
                if ($waste > 0) {
                    $parts = [round($waste * 0.6, 3), round($waste - round($waste * 0.6, 3), 3)];
                    foreach ($parts as $n => $kg) {
                        $name = "waste:{$code}:{$day}:{$kind}:{$n}";
                        $at = CarbonImmutable::parse($stamp)->addMinutes(2 + $n)->format('Y-m-d\TH:i:sP');
                        $out[] = [$at, new WeighWaste($ctx($at, $name), [
                            'waste_id' => self::id($name), 'day' => $day, 'stage_code' => (string) $wall, 'waste_kind' => $wasteKinds[($di + $n + ($kind === 'day' ? 0 : 1)) % count($wasteKinds)],
                            'kg' => $kg, 'reason' => $n === 0 ? 'Weighed at the end of the shift' : null,
                        ])];
                    }
                }
                if (! $today) {
                    $out[] = [$closeAt, new CloseShift($ctx($closeAt, "close:{$day}:{$kind}"), ['day' => $day, 'kind' => $kind])];
                }
            }
        }

        return $out;
    }
}
