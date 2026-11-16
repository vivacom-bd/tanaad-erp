<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tanaad\Cash\Database\Seeders\CashSeeder;
use Tanaad\Config\Database\Seeders\ConfigSeeder;
use Tanaad\Config\Database\Seeders\SitesSeeder;
use Tanaad\Dispatch\Database\Seeders\DispatchSeeder;
use Tanaad\Identity\Database\Seeders\DemoUsersSeeder;
use Tanaad\Labels\Database\Seeders\LabelSeeder;
use Tanaad\Orders\Database\Seeders\OrdersSeeder;
use Tanaad\People\Database\Seeders\PeopleSeeder;
use Tanaad\Production\Database\Seeders\ProductionSeeder;
use Tanaad\Purchases\Database\Seeders\PurchasesSeeder;
use Tanaad\Recycling\Database\Seeders\RecyclingSeeder;
use Tanaad\Shared\Ledger\Replay;
use Tanaad\Spares\Database\Seeders\SparesSeeder;
use Tanaad\Stock\Database\Seeders\StockSeeder;

beforeEach(function (): void {
    $this->seed([SitesSeeder::class, DemoUsersSeeder::class, ConfigSeeder::class]);
});

/** @return array<string, list<array<string, mixed>>> the Phase 3 projections (and what they touch), without wall-clock columns */
function phase3Rows(): array
{
    $out = [];
    foreach (['purchases', 'parts', 'part_receipts', 'part_issues', 'breakdowns', 'stock_on_hand', 'stock_movements', 'stock_label_positions', 'stock_counts', 'dispatches', 'dispatch_bundles', 'delivery_notes', 'labels', 'exceptions', 'number_series'] as $table) {
        $out[$table] = DB::table($table)->orderBy(Schema::getColumnListing($table)[0])->orderBy(Schema::getColumnListing($table)[1])->get()->map(function ($r): array {
            $a = (array) $r;
            unset($a['created_at'], $a['updated_at']);
            if (is_int($a['id'] ?? null)) {
                unset($a['id']); // auto-increment keys are not part of the facts
            }

            return $a;
        })->all();
    }

    return $out;
}

function seedPhase3(): void
{
    app()->call([new PeopleSeeder, 'run']);
    app()->call([new CashSeeder, 'run']);
    app()->call([new RecyclingSeeder, 'run']);
    app()->call([new ProductionSeeder, 'run']);
    app()->call([new LabelSeeder, 'run']);
    app()->call([new PurchasesSeeder, 'run']);
    app()->call([new SparesSeeder, 'run']);
    app()->call([new StockSeeder, 'run']);
    app()->call([new OrdersSeeder, 'run']);
    app()->call([new DispatchSeeder, 'run']);
}

it('seeds purchases, spares, stock and dispatch as ledger events and rebuilds them exactly', function (): void {
    seedPhase3();
    $events = DB::table('events')->count();
    $types = DB::table('events')->selectRaw('type, count(*) c')->groupBy('type')->orderBy('type')->pluck('c', 'type')->all();
    $before = phase3Rows();

    expect(DB::table('purchases')->count())->toBe(12)
        ->and(DB::table('purchases')->distinct()->pluck('status')->sort()->values()->all())->toBe(['at_border', 'in_transit', 'ordered', 'received'])
        ->and(DB::table('purchases')->where('variance', true)->count())->toBe(1)
        ->and(DB::table('parts')->count())->toBe(15)
        ->and(DB::table('part_issues')->count())->toBe(10)
        ->and(DB::table('breakdowns')->whereNull('resolved_at')->count())->toBe(1)
        ->and(DB::table('breakdowns')->whereNotNull('resolved_at')->count())->toBe(1)
        ->and(DB::table('exceptions')->where('kind', 'low_stock')->count())->toBe(2)
        ->and(DB::table('stock_on_hand')->count())->toBeGreaterThan(0)
        ->and(DB::table('stock_movements')->where('kind', 'scrap')->count())->toBeGreaterThan(0)
        ->and(DB::table('stock_counts')->where('variance', '!=', 0)->count())->toBe(1)
        ->and(DB::table('dispatches')->count())->toBe(7)
        ->and(DB::table('dispatches')->whereNotNull('delivered_at')->count())->toBe(4)
        ->and(DB::table('delivery_notes')->count())->toBe(6)
        ->and(DB::table('orders')->whereIn('status', ['picking', 'dispatched', 'delivered'])->count())->toBe(7)
        ->and(DB::table('orders')->where('status', 'delivered')->count())->toBe(4)
        ->and(DB::table('dispatches')->where('client_name', 'like', '%example%')->count())->toBe(0)
        ->and(DB::table('exceptions')->where('kind', 'gate_variance')->whereIn('status', ['open', 'held'])->count())->toBe(1);

    seedPhase3();
    expect(DB::table('events')->selectRaw('type, count(*) c')->groupBy('type')->orderBy('type')->pluck('c', 'type')->all())->toBe($types)
        ->and(DB::table('events')->count())->toBe($events);

    app(Replay::class)->rebuild();
    expect(phase3Rows())->toEqual($before);
});
