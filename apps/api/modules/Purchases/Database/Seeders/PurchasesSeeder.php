<?php

declare(strict_types=1);

namespace Tanaad\Purchases\Database\Seeders;

use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tanaad\Purchases\Commands\ChangePurchase;
use Tanaad\Purchases\Commands\ReceivePurchase;
use Tanaad\Purchases\Commands\RecordPurchase;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\CommandBus;
use Tanaad\Shared\Ledger\CommandContext;

/**
 * Six purchase orders at each buying site (mats, bags), raised by the site manager through the command bus over
 * the last weeks: the oldest are received (one of them short, flagged as a variance), then one at the border,
 * one in transit and the newest only ordered. Materials come from the site's material list; suppliers are plain
 * numbered names (not real trading partners). References come from the PO series
 * and the FX reference is the one Config holds. Run after ConfigSeeder. Ids are uuid5 of names, so a second run is all duplicates.
 */
final class PurchasesSeeder extends Seeder
{
    public const string NAMESPACE = 'e1b47c32-8d05-5a96-b2f1-60c9d3a87e14';

    private const string TODAY = '2026-11-10';

    /** site => [manager user suffix, supplier prefix, origin, currency, price low/high per kg, ordered kg low/high] */
    private const array SITES = [
        'mats' => ['mats', 'Polymer Supplier', 'Egypt', 'USD', [0.9, 1.4], [8000, 20000]],
        'bags' => ['bags', 'Resin Supplier', 'Saudi Arabia', 'USD', [1.0, 1.3], [12000, 26000]],
    ];

    /** Days before today of each order, oldest first, and what happened to it. */
    private const array PLAN = [
        [30, 'received'], [24, 'received'], [19, 'received'], [12, 'at_border'], [7, 'in_transit'], [2, 'ordered'],
    ];

    public static function id(string $name): string
    {
        return Uuid::uuid5(self::NAMESPACE, $name)->toString();
    }

    public function run(CommandBus $bus): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse(self::TODAY.' 12:00:00', 'Africa/Kampala'));
        $rng = new \Random\Randomizer(new \Random\Engine\Mt19937(20261120));

        try {
            /** @var list<array{string, Command}> $out */
            $out = [];
            foreach (self::SITES as $code => [$suffix, $supplier, $origin, $currency, $price, $qty]) {
                $site = (string) DB::table('sites')->where('code', $code)->value('id');
                $mgr = (string) DB::table('users')->where('code', "u_sm_{$suffix}")->value('id');
                $materials = DB::table('ref_items')->where(['list' => 'material', 'site_id' => $site, 'active' => true])->whereNull('deleted_at')->orderBy('sort_order')->pluck('label')->all();
                if ($materials === []) {
                    continue;
                }
                foreach (self::PLAN as $i => [$ago, $state]) {
                    $name = "purchase:{$code}:{$i}";
                    $day = CarbonImmutable::parse(self::TODAY)->subDays($ago);
                    $at = fn (CarbonImmutable $d, string $time): string => $d->format('Y-m-d').'T'.$time.'+03:00';
                    $ctx = fn (string $event, string $when): CommandContext => new CommandContext($site, $mgr, null, CarbonImmutable::parse($when), self::id("event:{$name}:{$event}"));
                    $fields = [
                        'supplier' => "{$supplier} ".(1 + $i % 3),
                        'origin_country' => $origin,
                        'material' => $materials[$i % count($materials)],
                        'qty_kg' => (float) (intdiv($rng->getInt($qty[0], $qty[1]), 100) * 100),
                        'unit_price' => round($price[0] + $rng->getInt(0, 100) / 100 * ($price[1] - $price[0]), 2),
                        'currency' => $currency,
                        'costs' => [
                            ['label' => 'Sea freight to Mombasa', 'amount' => (float) (1800 + $rng->getInt(0, 12) * 100), 'currency' => 'USD'],
                            ['label' => 'Clearing and transit to Kampala', 'amount' => (float) (2_800_000 + $rng->getInt(0, 14) * 100_000), 'currency' => 'UGX'],
                        ],
                        'expected_on' => $day->addDays(28)->toDateString(),
                        'note' => null,
                    ];
                    $purchaseId = self::id($name);
                    $out[] = [$at($day, '09:00:00'), new RecordPurchase($ctx('record', $at($day, '09:00:00')), ['purchase_id' => $purchaseId, ...$fields])];
                    $steps = match ($state) {
                        'received' => ['in_transit', 'at_border'],
                        'at_border' => ['in_transit', 'at_border'],
                        'in_transit' => ['in_transit'],
                        default => [],
                    };
                    foreach ($steps as $n => $status) {
                        $when = $at($day->addDays(3 + 4 * $n), '10:00:00');
                        $out[] = [$when, new ChangePurchase($ctx("status:{$status}", $when), ['purchase_id' => $purchaseId, ...$fields, 'status' => $status])];
                    }
                    if ($state === 'received') {
                        $when = $at($day->addDays(14), '14:00:00');
                        // The second order of the mats site arrives short (below the tolerance), the others in full.
                        $short = $code === 'mats' && $i === 1;
                        $received = $short ? round($fields['qty_kg'] * 0.94, 3) : $fields['qty_kg'];
                        $out[] = [$when, new ReceivePurchase($ctx('receive', $when), ['purchase_id' => $purchaseId, 'received_kg' => $received, 'received_on' => $day->addDays(14)->toDateString()])];
                    }
                }
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
}
