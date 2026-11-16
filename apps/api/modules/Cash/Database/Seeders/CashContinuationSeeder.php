<?php

declare(strict_types=1);

namespace Tanaad\Cash\Database\Seeders;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Seeder;
use Ramsey\Uuid\Uuid;
use Tanaad\Cash\Commands\CloseCashDay;
use Tanaad\Cash\Commands\RecordCashEntry;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\CommandBus;
use Tanaad\Shared\Ledger\CommandContext;

/**
 * Carries each site's cash book from the prototype's last day (the open Saturday 10 October) up to the business day
 * before the demo date (Monday 9 November), through the real commands, day by day (Sundays are rest days): the day's
 * cash sale and its usual outgoings (food, transport, fuel, now and then spares, materials or small items), a bank
 * deposit when the box holds more than the site keeps, and the evening count, which is exact (no variance, so the
 * only cash_variance exception stays the prototype's). The demo date itself is left open. Entry sizes follow the
 * site's scale. Recycling is carried only up to 26 October: from the 27th its days hold the collector payments
 * RecyclingSeeder makes, so that seeder closes them (with a cash sale that covers them) in its own day loop.
 * Run after CashSeeder, SettlementSeeder and the People ContinuationSeeder, before RecyclingSeeder. Ids are uuid5 of
 * names, so a second run adds nothing.
 */
final class CashContinuationSeeder extends Seeder
{
    public const string NAMESPACE = '5e7a1c93-b2d4-5f08-9a6e-4c3d2b1f8e07';

    public const string FIRST = '2026-10-10';

    public const string LAST = '2026-11-09';

    private const string RECYCLING_UNTIL = '2026-10-26';

    private const string TODAY = '2026-11-10';

    /** [cashier code, scale to mats' sizes in per cent, most the box holds, what stays after a deposit] */
    private const array SITES = [
        'mats' => ['u_cash_mats', 100, 14_000_000, 7_000_000],
        'bags' => ['u_cash_bags', 70, 10_000_000, 5_000_000],
        'recycling' => ['u_cash_rec', 30, 4_000_000, 2_000_000],
    ];

    /**
     * $site, $fromDay and $toDay take a slice (DatabaseSeeder uses it to close Recycling's days one by one, after the
     * collector payments of each); with none, every site is carried as above.
     */
    public function __construct(private readonly ?string $site = null, private readonly ?string $fromDay = null, private readonly ?string $toDay = null) {}

    public static function id(string $name): string
    {
        return Uuid::uuid5(self::NAMESPACE, $name)->toString();
    }

    public function run(CommandBus $bus, ConnectionInterface $db): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse(self::TODAY.' 12:00:00', 'Africa/Kampala'));

        try {
            $done = array_fill_keys($db->table('events')->where('type', 'like', 'cash.%')->pluck('id')->all(), true);
            $rng = new \Random\Randomizer(new \Random\Engine\Mt19937(20261112));
            foreach (self::SITES as $code => [$cashierCode, $scale, $cap, $keep]) {
                if ($this->site !== null && $this->site !== $code) {
                    continue;
                }
                $siteId = (string) $db->table('sites')->where('code', $code)->value('id');
                $cashier = (string) $db->table('users')->where('code', $cashierCode)->value('id');
                $until = $this->toDay ?? ($code === 'recycling' ? self::RECYCLING_UNTIL : self::LAST);
                for ($day = CarbonImmutable::parse($this->fromDay ?? self::FIRST); $day->toDateString() <= $until; $day = $day->addDay()) {
                    $d = $day->toDateString();
                    if ($day->dayOfWeek === 0) {
                        continue;
                    }
                    $send = function (string $name, Command $command) use ($bus, $done): void {
                        if (! isset($done[self::id("event:{$name}")])) {
                            $bus->dispatch($command);
                        }
                    };
                    $ctx = fn (string $name, string $time): CommandContext => new CommandContext($siteId, $cashier, null, CarbonImmutable::parse("{$d}T{$time}:00+03:00"), self::id("event:{$name}"));
                    $entry = function (string $key, string $time, string $direction, string $category, ?string $sub, float|int $amount, string $payee) use ($send, $ctx, $d, $code): void {
                        $name = "{$code}:{$d}:{$key}";
                        $send($name, new RecordCashEntry($ctx($name, $time), [
                            'entry_id' => self::id("entry:{$name}"), 'day' => $d, 'direction' => $direction, 'category' => $category, 'subcategory' => $sub,
                            'amount' => $amount, 'currency' => 'UGX', 'fx_reference' => null, 'machine_id' => null, 'product_id' => null,
                            'payee' => $payee, 'note' => null, 'has_receipt_photo' => false,
                        ]));
                    };
                    $size = fn (int $low, int $high): int => intdiv($rng->getInt($low, $high) * $scale, 100_000) * 1000;
                    // The draws are made in the same order on every run, whether or not a command is then skipped.
                    $sale = $size(3_500_000, 6_500_000);
                    $food = $size(150_000, 300_000);
                    $transport = $size(40_000, 90_000);
                    $fuel = $size(250_000, 450_000);
                    $extraRoll = $rng->getInt(0, 9);
                    $extra = $size(120_000, 400_000);
                    if ($d !== self::FIRST) {
                        // Enough cash sales that the day's outgoings (and any collector payments already made) leave the box above zero.
                        $planned = $food + $transport + $fuel + ($extraRoll < 7 ? $extra : 0);
                        $sale = max($sale, (int) (ceil(($planned - $this->expected($db, $siteId, $d) + 300_000) / 1000) * 1000));
                        $entry('sale', '10:00', 'in', 'sale', null, max(1000, $sale), 'Cash sales');
                        $entry('food', '12:30', 'out', 'food', null, max(1000, $food), 'Staff lunch');
                        $entry('transport', '10:30', 'out', 'transport', 'Boda', max(1000, $transport), 'Boda rides');
                        $entry('fuel', '08:20', 'out', 'utilities', 'Generator fuel', max(1000, $fuel), 'Generator fuel');
                        match (true) {
                            $extraRoll < 3 => $entry('spares', '09:30', 'out', 'spares', null, max(1000, $extra), 'Spares for repairs'),
                            $extraRoll < 5 => $entry('materials', '14:15', 'out', 'materials', null, max(1000, $extra), 'Masterbatch and other small materials'),
                            $extraRoll < 7 => $entry('other', '15:00', 'out', 'other', null, max(1000, intdiv($extra, 4000) * 1000), 'Airtime and stationery'),
                            default => null,
                        };
                    }
                    $expected = $this->expected($db, $siteId, $d);
                    if ($expected > $cap) {
                        $entry('deposit', '16:30', 'out', 'other', 'Bank deposit', intdiv((int) ($expected - $keep), 100_000) * 100_000, 'Deposit to the company bank account');
                        $expected = $this->expected($db, $siteId, $d);
                    }
                    if ($expected < 0) {
                        throw new \LogicException("The {$code} cash book would close below zero on {$d}.");
                    }
                    $send("{$code}:{$d}:close", new CloseCashDay($ctx("{$code}:{$d}:close", '18:05'), ['day' => $d, 'counted' => $expected, 'denominations' => []]));
                }
            }
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    /** The day's opening plus what came in and went out so far; on a second run the day is already closed and this is not used. */
    private function expected(ConnectionInterface $db, string $siteId, string $day): float
    {
        $row = $db->table('cash_days')->where(['site_id' => $siteId, 'day' => $day])->first(['opening', 'status', 'counted_closing']);
        if ($row !== null && $row->status === 'closed') {
            return (float) $row->counted_closing;
        }
        $opening = $row !== null ? (float) $row->opening : (float) ($db->table('cash_days')->where('site_id', $siteId)->where('day', '<', $day)->whereNotNull('counted_closing')->orderByDesc('day')->value('counted_closing') ?? 0);
        $net = (float) $db->table('cash_entries')->where(['site_id' => $siteId, 'day' => $day])->whereNull('superseded_by')->whereNull('deleted_at')
            ->selectRaw("coalesce(sum(case when direction = 'in' then amount else -amount end), 0) as net")->value('net');

        return $opening + $net;
    }
}
