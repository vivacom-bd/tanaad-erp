<?php

declare(strict_types=1);

namespace Tanaad\Spares\Database\Seeders;

use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\CommandBus;
use Tanaad\Shared\Ledger\CommandContext;
use Tanaad\Spares\Commands\IssuePart;
use Tanaad\Spares\Commands\ReceivePart;
use Tanaad\Spares\Commands\ReportBreakdown;
use Tanaad\Spares\Commands\ResolveBreakdown;
use Tanaad\Spares\Commands\SavePart;

/**
 * The spares store of each site, through the command bus: the parts catalogue from the prototype's list (saved by
 * the site manager, with the machine kinds each part fits), a first receipt by the storekeeper and a later
 * dearer one for a few, issues against machines, and two breakdowns: Loom 3 at mats stopped last Wednesday and is
 * back (with the belt and bearing issued to it), Extruder C at bags is down since this morning (heater band issued).
 * Two parts fall under their minimum when issued (the mats bearing, and the recycling seal kit that was only ever received
 * below it): a low_stock exception each. Run after ConfigSeeder. Ids are uuid5 of names, so a second run is all duplicates.
 */
final class SparesSeeder extends Seeder
{
    public const string NAMESPACE = 'f2c58d43-9e16-5ba7-83a2-71d0e4b98f25';

    private const string TODAY = '2026-11-10';

    /** site => user suffix, then parts: [code, name, unit, min, unit cost UGX, first receipt, machine kinds] */
    private const array SITES = [
        'mats' => ['mats', [
            ['MT-BELT', 'Loom drive belt B-52', 'pc', 4, 85000, 6, ['loom']],
            ['MT-BRG', 'Bearing 6205-2RS', 'pc', 10, 18000, 12, ['loom', 'extruder']],
            ['MT-HEAT', 'Heater band 220V 1.2kW', 'pc', 6, 95000, 8, ['extruder']],
            ['MT-NDL', 'Sewing needle pack', 'pack', 5, 12000, 14, ['loom']],
            ['MT-OIL', 'Compressor oil 20L', 'drum', 2, 400000, 3, ['crusher', 'mixer']],
            ['MT-SCR', 'Extruder screen pack', 'pc', 8, 32000, 11, ['extruder']],
            ['MT-HOOK', 'Shuttle hook', 'pc', 6, 24000, 9, ['loom']],
        ]],
        'bags' => ['bags', [
            ['BG-HEAT', 'Heater band 180mm', 'pc', 6, 88000, 9, ['extruder']],
            ['BG-WIRE', 'Sealing wire 2m', 'pc', 10, 15000, 12, ['converter']],
            ['BG-BLD', 'Cutting blade', 'pc', 8, 21000, 16, ['converter']],
            ['BG-MESH', 'Screen mesh 80', 'pc', 10, 9000, 30, ['extruder']],
        ]],
        'recycling' => ['rec', [
            ['RC-BLD', 'Pelletiser blade set', 'set', 2, 650000, 3, ['line']],
            ['RC-MESH', 'Screen changer mesh', 'pc', 10, 14000, 22, ['line']],
            ['RC-SEAL', 'Wash pump seal kit', 'kit', 2, 120000, 1, ['washer']],
            ['RC-BRG', 'Bearing 6308', 'pc', 6, 42000, 8, ['line', 'washer']],
        ]],
    ];

    /** [site, part code, qty, days ago, machine code, linked breakdown key or '', reason] */
    private const array ISSUES = [
        ['mats', 'MT-NDL', 3, 20, 'Loom 5', '', 'Needles worn out'],
        ['mats', 'MT-HOOK', 2, 15, 'Loom 9', '', 'Hook bent'],
        ['mats', 'MT-BELT', 2, 6, 'Loom 3', 'mats', 'Belt snapped'],
        ['mats', 'MT-BRG', 3, 6, 'Loom 3', 'mats', 'Bearing seized'],
        ['bags', 'BG-BLD', 2, 11, 'Converter 2', '', 'Dull blade'],
        ['bags', 'BG-MESH', 5, 9, 'Extruder A', '', 'Screen change'],
        ['bags', 'BG-HEAT', 1, 0, 'Extruder C', 'bags', 'Heater band burnt out'],
        ['recycling', 'RC-MESH', 4, 8, 'Line 10T', '', 'Screen change'],
        ['recycling', 'RC-BRG', 2, 5, 'Wash line', '', 'Noisy bearing'],
        ['recycling', 'RC-SEAL', 1, 3, 'Wash line', '', 'Pump seal leaking'],
    ];

    public static function id(string $name): string
    {
        return Uuid::uuid5(self::NAMESPACE, $name)->toString();
    }

    /** @param array{string, array<string, string>} $site the site id and its users by role */
    private static function ctx(array $site, string $role, string $at, string $name): CommandContext
    {
        return new CommandContext($site[0], $site[1][$role], null, CarbonImmutable::parse($at), self::id("event:{$name}"));
    }

    private static function issueTime(string $breakdown, int $n): string
    {
        if ($breakdown === '') {
            return '10:30:00';
        }

        return $breakdown === 'mats' ? sprintf('11:%02d:00', $n) : '09:00:00';
    }

    public function run(CommandBus $bus): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse(self::TODAY.' 12:00:00', 'Africa/Kampala'));

        try {
            /** @var list<array{string, Command}> $out */
            $out = [];
            /** @var array<string, array{string, array<string, string>}> $ctxs */
            $ctxs = [];
            $stamp = fn (int $ago, string $time): string => CarbonImmutable::parse(self::TODAY)->subDays($ago)->format('Y-m-d').'T'.$time.'+03:00';
            foreach (self::SITES as $code => [$suffix, $parts]) {
                $site = (string) DB::table('sites')->where('code', $code)->value('id');
                $users = [];
                foreach (['sm', 'store', 'sup'] as $r) {
                    $users[$r] = (string) DB::table('users')->where('code', "u_{$r}_{$suffix}")->value('id');
                }
                $ctxs[$code] = [$site, $users];
                foreach ($parts as $i => [$pcode, $name, $unit, $min, $cost, $first, $kinds]) {
                    $partId = self::id("part:{$pcode}");
                    $at = $stamp(30, sprintf('08:%02d:00', $i));
                    $out[] = [$at, new SavePart(self::ctx($ctxs[$code], 'sm', $at, "part:{$pcode}"), [
                        'part_id' => $partId, 'code' => $pcode, 'name' => $name, 'unit' => $unit, 'min_level' => $min, 'unit_cost' => $cost, 'machine_kinds' => $kinds, 'active' => true,
                    ])];
                    $at = $stamp(29, sprintf('09:%02d:00', $i));
                    $out[] = [$at, new ReceivePart(self::ctx($ctxs[$code], 'store', $at, "receipt:{$pcode}:1"), [
                        'receipt_id' => self::id("receipt:{$pcode}:1"), 'part_id' => $partId, 'qty' => $first, 'supplier' => 'Spares Supplier 1', 'unit_cost' => $cost,
                    ])];
                    // A later, dearer top-up of a few parts moves the average cost (the part stays above its minimum).
                    if ($i % 4 === 0 && $first > $min) {
                        $at = $stamp(18, sprintf('11:%02d:00', $i));
                        $out[] = [$at, new ReceivePart(self::ctx($ctxs[$code], 'store', $at, "receipt:{$pcode}:2"), [
                            'receipt_id' => self::id("receipt:{$pcode}:2"), 'part_id' => $partId, 'qty' => 2, 'supplier' => 'Spares Supplier 1', 'unit_cost' => (int) round($cost * 1.08),
                        ])];
                    }
                }
            }
            // Breakdowns: mats Loom 3 (resolved the same afternoon), bags Extruder C (still down).
            $breakdowns = [
                'mats' => ['Loom 3', 6, '09:10:00', 'Belt snapped and the bearing seized', '15:40:00'],
                'bags' => ['Extruder C', 0, '08:10:00', 'Heater band burnt out', null],
            ];
            $machineIds = [];
            foreach ($breakdowns as $code => [$machine, $ago, $time, $cause, $resolved]) {
                $site = (string) DB::table('sites')->where('code', $code)->value('id');
                $machineIds[$code] = (string) DB::table('machines')->where(['site_id' => $site, 'code' => $machine])->value('id');
                $at = $stamp($ago, $time);
                $out[] = [$at, new ReportBreakdown(self::ctx($ctxs[$code], 'sup', $at, "breakdown:{$code}"), [
                    'breakdown_id' => self::id("breakdown:{$code}"), 'machine_id' => $machineIds[$code], 'cause' => $cause, 'started_at' => $at,
                ])];
                if ($resolved !== null) {
                    $rat = $stamp($ago, $resolved);
                    $out[] = [$rat, new ResolveBreakdown(self::ctx($ctxs[$code], 'sup', $rat, "breakdown-resolved:{$code}"), [
                        'breakdown_id' => self::id("breakdown:{$code}"), 'resolved_at' => $rat,
                    ])];
                }
            }
            foreach (self::ISSUES as $n => [$code, $pcode, $qty, $ago, $machine, $link, $reason]) {
                $site = (string) DB::table('sites')->where('code', $code)->value('id');
                $machineId = (string) DB::table('machines')->where(['site_id' => $site, 'code' => $machine])->value('id');
                $breakdownId = $link === '' ? null : self::id("breakdown:{$link}");
                // Issues against a breakdown follow its report (mats: 11:00, bags: 09:00 on the day).
                $at = $stamp($ago, self::issueTime($link, $n));
                $out[] = [$at, new IssuePart(self::ctx($ctxs[$code], 'store', $at, "issue:{$n}"), [
                    'issue_id' => self::id("issue:{$n}"), 'part_id' => self::id("part:{$pcode}"), 'machine_id' => $machineId,
                    'breakdown_id' => $breakdownId, 'qty' => $qty, 'reason' => $reason,
                ])];
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
