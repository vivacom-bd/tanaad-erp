<?php

declare(strict_types=1);

namespace Tanaad\Stock\Database\Seeders;

use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\CommandBus;
use Tanaad\Shared\Ledger\CommandContext;
use Tanaad\Stock\Commands\MoveStock;
use Tanaad\Stock\Commands\RecordStockCount;
use Tanaad\Stock\Commands\ScrapLabel;

/**
 * Puts the finished goods of each site into the zones Config defines, through the command bus as its storekeeper.
 * The labels from LabelSeeder have no zone yet: the first ten in stock of the two best-stocked products are
 * placed in the site's first zone, two of the first product are moved on to the second zone, one or two are
 * scrapped (damaged), and on today's morning each zone is counted per product: the second product of the first zone
 * is one bundle short (counted with a reason, so a paired adjustment is booked), all the others agree.
 * Transfers between sites are NOT seeded: a transfer needs the same product at both sites (matched by code), and
 * no product code is shared between mats, bags and recycling. Run after LabelSeeder. Ids are uuid5 of names, so a second run is all duplicates.
 */
final class StockSeeder extends Seeder
{
    public const string NAMESPACE = 'a3d69e54-0f27-5cb8-94b3-82e1f5ca0a36';

    private const string TODAY = '2026-11-10';

    /** site => user suffix */
    private const array SITES = ['mats' => 'mats', 'bags' => 'bags', 'recycling' => 'rec'];

    public static function id(string $name): string
    {
        return Uuid::uuid5(self::NAMESPACE, $name)->toString();
    }

    public function run(CommandBus $bus): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse(self::TODAY.' 12:00:00', 'Africa/Kampala'));

        try {
            /** @var list<array{string, Command}> $out */
            $out = [];
            foreach (self::SITES as $code => $suffix) {
                array_push($out, ...$this->site($code, $suffix));
            }
            usort($out, fn (array $x, array $y): int => strcmp($x[0], $y[0]));
            // A command whose event is already in the ledger was seeded before: skip it.
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

    /** @return list<array{string, Command}> */
    private function site(string $code, string $suffix): array
    {
        $site = (string) DB::table('sites')->where('code', $code)->value('id');
        $store = (string) DB::table('users')->where('code', "u_store_{$suffix}")->value('id');
        $zones = DB::table('zones')->where('site_id', $site)->whereNull('deleted_at')->orderBy('name')->pluck('id')->all();
        if (count($zones) < 2) {
            return [];
        }
        $ctx = fn (string $at, string $name): CommandContext => new CommandContext($site, $store, null, CarbonImmutable::parse($at, 'Africa/Kampala'), self::id("event:{$code}:{$name}"));
        // Labels still in stock, or already scrapped or dispatched (by an earlier run, or by DispatchSeeder, which takes from the newest end), grouped by product, best-stocked products first.
        $labels = DB::table('labels')->where('site_id', $site)->whereIn('status', ['in_stock', 'scrapped', 'dispatched'])->whereNotNull('product_id')->whereNull('deleted_at')
            ->orderBy('code')->get(['id', 'code', 'product_id'])->groupBy('product_id');
        $products = $labels->sortByDesc(fn ($rows): int => count($rows))->keys()->take(2)->values()->all();
        $out = [];
        $system = [];
        foreach ($products as $pi => $product) {
            $rows = $labels->get($product, collect())->values()->all();
            $place = array_slice($rows, 0, $pi === 0 ? 6 : 4);
            foreach ($place as $n => $label) {
                $at = sprintf('%sT08:%02d:%02d+03:00', self::TODAY, $pi * 10 + intdiv($n, 60), $n % 60);
                $out[] = [$at, new MoveStock($ctx($at, "place:{$label->code}"), ['label_id' => $label->id, 'to_zone_id' => $zones[0], 'reason' => 'Put away after production'])];
            }
            $system[$product] = [$zones[0] => count($place), $zones[1] => 0];
            if ($pi === 0) {
                foreach (array_slice($place, 0, 2) as $n => $label) {
                    $at = sprintf('%sT08:30:%02d+03:00', self::TODAY, $n);
                    $out[] = [$at, new MoveStock($ctx($at, "move:{$label->code}"), ['label_id' => $label->id, 'to_zone_id' => $zones[1], 'reason' => 'Moved to make room'])];
                    $system[$product][$zones[0]]--;
                    $system[$product][$zones[1]]++;
                }
            }
            // The next unplaced label of the product is damaged and scrapped.
            $bad = $rows[count($place)] ?? null;
            if ($bad !== null) {
                $at = self::TODAY.'T08:45:00+03:00';
                $out[] = [$at, new ScrapLabel($ctx($at, "scrap:{$bad->code}"), ['label_id' => $bad->id, 'reason' => 'Damaged in handling'])];
            }
        }
        $n = 0;
        foreach ($system as $product => $byZone) {
            foreach ($byZone as $zone => $bundles) {
                if ($bundles === 0) {
                    continue;
                }
                $short = $code === 'mats' && $n === 1;
                $at = sprintf('%sT09:%02d:00+03:00', self::TODAY, 10 + $n);
                $out[] = [$at, new RecordStockCount($ctx($at, "count:{$zone}:{$product}"), [
                    'count_id' => self::id("count:{$code}:{$zone}:{$product}"), 'zone_id' => $zone, 'product_id' => $product, 'day' => self::TODAY,
                    'counted_bundles' => $bundles - ($short ? 1 : 0), 'reason' => $short ? 'One bundle not found on the shelf; checked twice' : null,
                ])];
                $n++;
            }
        }

        return $out;
    }
}
