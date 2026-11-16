<?php

declare(strict_types=1);

namespace Tanaad\Recycling\Database\Seeders;

use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tanaad\Recycling\Commands\PayCollector;
use Tanaad\Recycling\Commands\RecordCollectorIntake;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\CommandBus;
use Tanaad\Shared\Ledger\CommandContext;

/**
 * Collector intakes at the recycling site for the last ten working days (Sundays off) up to today,
 * Tuesday 10 November 2026, through the command bus. The storekeeper weighs each delivery with one of the
 * site's deduction options; the cashier pays most of them in cash on the delivery day, leaving a few unpaid
 * (more of the recent ones). A pay opens that day's cash book if it is not open yet; DatabaseSeeder closes each past day after its payments, not this seeder, and none is
 * reopened. Run after CashSeeder (its books) and ConfigSeeder (the intake_voucher series), before ProductionSeeder
 * (the washing line is fed from these deliveries). Ids are uuid5 of names, so a second run is all duplicates.
 */
final class RecyclingSeeder extends Seeder
{
    private \Random\Randomizer $rng;

    public const string NAMESPACE = '6d2f4b1a-7c35-5e90-8a14-b0c93e5d7f26';

    public const string TODAY = '2026-11-10';

    private const array COLLECTORS = ['Mama Aisha', 'Juma Ssebunya', 'Namukasa Rose', 'Kato Brothers', 'Okello Peter', 'Nakato Grace', 'Mugisha Sam', 'Atim Beatrice'];

    /** $onlyDay runs just that day's deliveries and payments (the random draws are the same), for DatabaseSeeder's day-by-day cash closing. */
    public function __construct(private readonly ?string $onlyDay = null) {}

    public static function id(string $name): string
    {
        return Uuid::uuid5(self::NAMESPACE, $name)->toString();
    }

    /** @return list<string> the working days (no Sundays) from the tenth before today up to and including today */
    public static function days(): array
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

    public function run(CommandBus $bus): void
    {
        $site = (string) DB::table('sites')->where('code', 'recycling')->value('id');
        $store = (string) DB::table('users')->where('code', 'u_store_rec')->value('id');
        $cashier = (string) DB::table('users')->where('code', 'u_cash_rec')->value('id');
        $presets = DB::table('ref_items')->where(['list' => 'deduction_option', 'active' => true, 'site_id' => $site])->whereNull('deleted_at')->orderBy('sort_order')->pluck('id')->all();
        $this->rng = new \Random\Randomizer(new \Random\Engine\Mt19937(20261110));
        $ctx = fn (string $by, string $at, string $name): CommandContext => new CommandContext($site, $by, null, CarbonImmutable::parse($at, 'Africa/Kampala'), self::id('event:'.$name));

        /** @var list<array{string, Command}> $out */
        $out = [];
        foreach (self::days() as $di => $day) {
            $today = $day === self::TODAY;
            $count = $today ? 2 : $this->rng->getInt(3, 6);
            for ($i = 0; $i < $count; $i++) {
                $name = "delivery:{$day}:{$i}";
                $at = sprintf('%sT%02d:%02d:00+03:00', $day, 8 + $i * 2 % 8, $this->rng->getInt(0, 5) * 10);
                $gross = $this->rng->getInt(18, 90) * 10 + $this->rng->getInt(0, 9);
                $price = [850, 900, 950, 1000][$this->rng->getInt(0, 3)];
                $out[] = [$at, new RecordCollectorIntake($ctx($store, $at, $name), [
                    'delivery_id' => self::id($name), 'day' => $day, 'collector' => self::COLLECTORS[$this->rng->getInt(0, count(self::COLLECTORS) - 1)],
                    'gross_kg' => $gross, 'tare_kg' => $this->rng->getInt(1, 4) * 10, 'price_per_kg' => $price,
                    'deduction_preset_id' => $presets === [] ? null : $presets[$this->rng->getInt(0, count($presets) - 1)],
                    'deduction_pct' => $presets === [] ? 0 : null, 'has_scale_photo' => $this->rng->getInt(0, 4) > 0,
                ])];
                // Today's deliveries and a share of the recent ones wait for the cashier.
                $unpaid = $today || ($di >= 8 && $this->rng->getInt(0, 2) === 0) || $this->rng->getInt(0, 9) === 0;
                if (! $unpaid) {
                    $pay = sprintf('%sT16:%02d:00+03:00', $day, 10 + $i);
                    $out[] = [$pay, new PayCollector($ctx($cashier, $pay, "pay:{$day}:{$i}"), ['delivery_id' => self::id($name), 'payment_id' => self::id("payment:{$day}:{$i}")])];
                }
            }
        }
        usort($out, fn (array $x, array $y): int => strcmp($x[0], $y[0]));
        if ($this->onlyDay !== null) {
            $out = array_values(array_filter($out, fn (array $c): bool => str_starts_with($c[0], $this->onlyDay)));
        }
        // A command whose event is already in the ledger was seeded before: skip it (some handlers reject a repeated id).
        $done = array_flip(DB::table('events')->pluck('id')->all());
        foreach ($out as [, $command]) {
            if (! isset($done[(string) $command->context()->eventId])) {
                $bus->dispatch($command);
            }
        }
    }
}
