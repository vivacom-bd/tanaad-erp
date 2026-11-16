<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tanaad\Cash\Database\Seeders\CashSeeder;
use Tanaad\Config\Database\Seeders\ConfigSeeder;
use Tanaad\Config\Database\Seeders\SitesSeeder;
use Tanaad\Identity\Database\Seeders\DemoUsersSeeder;
use Tanaad\Labels\Database\Seeders\LabelSeeder;
use Tanaad\People\Database\Seeders\PeopleSeeder;
use Tanaad\Production\Database\Seeders\ProductionSeeder;
use Tanaad\Recycling\Database\Seeders\RecyclingSeeder;
use Tanaad\Shared\Ledger\Replay;

beforeEach(function (): void {
    $this->seed([SitesSeeder::class, DemoUsersSeeder::class, ConfigSeeder::class]);
});

/** @return array<string, list<array<string, mixed>>> the Phase 2 projections, without wall-clock columns */
function phase2Rows(): array
{
    $out = [];
    foreach (['production_entries', 'production_shifts_closed', 'waste_weighings', 'labels', 'collector_deliveries', 'cash_days', 'cash_entries', 'exceptions'] as $table) {
        $out[$table] = DB::table($table)->orderBy('id')->get()->map(function ($r): array {
            $a = (array) $r;
            unset($a['created_at'], $a['updated_at']);

            return $a;
        })->all();
    }

    return $out;
}

function seedPhase2(): void
{
    app()->call([new PeopleSeeder, 'run']);
    app()->call([new CashSeeder, 'run']);
    app()->call([new RecyclingSeeder, 'run']);
    app()->call([new ProductionSeeder, 'run']);
    app()->call([new LabelSeeder, 'run']);
}

it('seeds production, labels and collector intakes as ledger events and rebuilds them exactly', function (): void {
    seedPhase2();
    $events = DB::table('events')->count();
    $before = phase2Rows();

    expect(DB::table('production_entries')->count())->toBeGreaterThan(300)
        ->and(DB::table('production_shifts_closed')->count())->toBe(3 * 2 * 10)
        ->and(DB::table('waste_weighings')->count())->toBeGreaterThan(0)
        ->and(DB::table('labels')->count())->toBeGreaterThan(100)
        ->and(DB::table('labels')->where('status', 'in_stock')->count())->toBeGreaterThan(0)
        ->and(DB::table('collector_deliveries')->where('paid', true)->count())->toBeGreaterThan(0)
        ->and(DB::table('collector_deliveries')->where('paid', false)->count())->toBeGreaterThan(0)
        ->and(DB::table('cash_entries')->whereIn('id', DB::table('collector_deliveries')->where('paid', true)->pluck('payment_id'))->count())->toBe(DB::table('collector_deliveries')->where('paid', true)->count())
        ->and(DB::table('exceptions')->where('kind', 'below_benchmark')->exists())->toBeTrue();

    // Today's day shift is open and the mats wall still has two looms unlogged.
    $mats = (string) DB::table('sites')->where('code', 'mats')->value('id');
    expect(DB::table('production_shifts_closed')->where(['site_id' => $mats, 'day' => '2026-11-10'])->exists())->toBeFalse()
        ->and(DB::table('production_entries')->where(['site_id' => $mats, 'day' => '2026-11-10', 'stage_code' => 'weave'])->count())->toBe(DB::table('machines')->where(['site_id' => $mats, 'stage_code' => 'weave', 'status' => 'active'])->count() - 2);

    seedPhase2();
    expect(DB::table('events')->count())->toBe($events);

    app(Replay::class)->rebuild();
    expect(phase2Rows())->toEqual($before);
});
