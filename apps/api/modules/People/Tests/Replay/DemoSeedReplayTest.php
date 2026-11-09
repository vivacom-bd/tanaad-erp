<?php

declare(strict_types=1);

use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
use Tanaad\Cash\Database\Seeders\CashSeeder;
use Tanaad\Config\Database\Seeders\ConfigSeeder;
use Tanaad\Config\Database\Seeders\SitesSeeder;
use Tanaad\Identity\Database\Seeders\DemoUsersSeeder;
use Tanaad\People\Database\Seeders\PeopleSeeder;
use Tanaad\People\Database\Seeders\SettlementSeeder;
use Tanaad\Shared\Ledger\Replay;

beforeEach(function (): void {
    $this->seed([SitesSeeder::class, DemoUsersSeeder::class, ConfigSeeder::class]);
});

/** @return array<string, list<array<string, mixed>>> the seeded projections, without wall-clock columns */
function demoRows(): array
{
    $out = [];
    foreach (['people', 'engagements', 'attendance', 'cash_days', 'cash_entries', 'settlement_runs', 'settlement_lines', 'payouts', 'advances', 'exceptions'] as $table) {
        $out[$table] = DB::table($table)->orderBy('id')->get()->map(function ($r): array {
            $a = (array) $r;
            unset($a['created_at'], $a['updated_at']);

            return $a;
        })->all();
    }

    return $out;
}

function seedDemo(?string $site = null, ?string $fromDay = null): void
{
    app()->call([new PeopleSeeder($site, $fromDay), 'run']);
    app()->call([new CashSeeder($site, $fromDay), 'run']);
    if ($site === null && $fromDay === null) {
        app()->call([new SettlementSeeder, 'run']);
        DatabaseSeeder::raiseContractExpiry();
    }
}

it('seeds a slice idempotently and rebuilds it from the ledger', function (): void {
    seedDemo('mats', '2026-10-08');
    $events = DB::table('events')->count();
    $before = demoRows();

    seedDemo('mats', '2026-10-08');
    expect(DB::table('events')->count())->toBe($events);

    app(Replay::class)->rebuild();
    expect(demoRows())->toEqual($before)
        ->and(DB::table('attendance')->min('day'))->toBe('2026-10-08')
        ->and(DB::table('people')->distinct()->count('site_id'))->toBe(1);
});

it('seeds the whole prototype dataset as ledger events and rebuilds it exactly', function (): void {
    seedDemo();
    $events = DB::table('events')->count();
    $before = demoRows();
    $cash = CashSeeder::data()['rows'];

    expect(count($before['people']))->toBe(count(PeopleSeeder::data('people')['rows']))
        ->and(count($before['engagements']))->toBe(count(PeopleSeeder::data('engagements')['rows']))
        ->and(count($before['attendance']))->toBe(count(PeopleSeeder::data('attendance')['rows']))
        ->and(count($before['cash_days']))->toBe(count($cash['days']))
        // the prototype's entries, the overdrawn day's float in and out, cash payouts and advances
        ->and(count($before['cash_entries']))->toBe(count($cash['entries']) + 2 + DB::table('payouts')->where('method', 'cash')->count() + DB::table('advances')->count())
        ->and(DB::table('people')->where('status', 'archived')->count())->toBe(12)
        ->and(DB::table('events')->where('type', 'people.attendance_marked')->whereNull('actor_id')->exists())->toBeFalse();

    // The prototype's overdrawn recycling day is closed at 0 with a cash-neutral float in and out, so no
    // later opening moves; each site's first day opens with the prototype's balance; only its one
    // genuine UGX 35,000 shortage raises cash_variance.
    $recycling = DB::table('cash_days')->join('sites', 'sites.id', '=', 'cash_days.site_id')->where('sites.code', 'recycling')->orderBy('day')->pluck('cash_days.opening', 'day');
    expect(DB::table('cash_days')->whereNotNull('variance')->where('variance', '!=', 0)->count())->toBe(1)
        ->and(DB::table('cash_days')->where('variance', '-35000')->count())->toBe(1)
        ->and(DB::table('exceptions')->where('kind', 'cash_variance')->count())->toBe(1)
        ->and((float) $recycling['2026-10-05'])->toBe(780000.0)
        ->and((float) $recycling['2026-10-10'])->toBe(2134077.0)
        ->and(DB::table('cash_days')->where('day', '2026-08-18')->value('opening'))->toEqual('1200000.00');

    // Julius Kasozi's contract ended on 9 October; the job as of 10 October raised it, and Isaac Byaruhanga's (3 days left).
    expect(DB::table('exceptions')->where('kind', 'contract_expiring')->where('title', 'Julius Kasozi: contract ended on 2026-10-09')->count())->toBe(1)
        ->and(DB::table('exceptions')->where('kind', 'contract_expiring')->where('title', 'Isaac Byaruhanga: contract ends in 3 days')->count())->toBe(1);

    // Settlement: last week fully paid; this week approved, two thirds paid, three advances deducted; the ended contract paid.
    $runs = DB::table('settlement_runs')->orderBy('period_to')->get();
    expect($runs)->toHaveCount(3)
        ->and(DB::table('advances')->count())->toBe(3)
        ->and((float) DB::table('settlement_lines')->sum('advances_deducted'))->toBe(100000.0)
        ->and(DB::table('settlement_lines')->where('status', 'paid')->count())->toBe(DB::table('payouts')->count())
        ->and(DB::table('settlement_lines')->where('status', 'unpaid')->count())->toBeGreaterThan(0)
        ->and(DB::table('settlement_lines')->whereRaw('net != floor(net)')->exists())->toBeFalse()
        ->and($runs->where('status', 'paid')->count())->toBe(1);

    seedDemo();
    expect(DB::table('events')->count())->toBe($events);

    app(Replay::class)->rebuild();
    expect(demoRows())->toEqual($before);
});
