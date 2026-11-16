<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tanaad\Cash\Database\Seeders\CashSeeder;
use Tanaad\Config\Database\Seeders\ConfigSeeder;
use Tanaad\Config\Database\Seeders\SitesSeeder;
use Tanaad\Identity\Database\Seeders\DemoUsersSeeder;
use Tanaad\Orders\Database\Seeders\OrdersSeeder;
use Tanaad\People\Database\Seeders\PeopleSeeder;
use Tanaad\Shared\Ledger\Replay;

beforeEach(function (): void {
    $this->seed([SitesSeeder::class, DemoUsersSeeder::class, ConfigSeeder::class]);
});

/** @return array<string, list<array<string, mixed>>> the Orders projections and the cash they book, without wall-clock columns */
function phase4OrderRows(): array
{
    $out = [];
    foreach (['clients', 'orders', 'invoices', 'payments', 'cash_entries', 'cash_days', 'exceptions'] as $table) {
        $out[$table] = DB::table($table)->orderBy('id')->get()->map(function ($r): array {
            $a = (array) $r;
            unset($a['created_at'], $a['updated_at']);

            return $a;
        })->all();
    }

    return $out;
}

function seedPhase4Orders(): void
{
    app()->call([new PeopleSeeder, 'run']);
    app()->call([new CashSeeder, 'run']);
    app()->call([new OrdersSeeder, 'run']);
}

it('seeds clients, orders, invoices and payments as ledger events and rebuilds them exactly', function (): void {
    seedPhase4Orders();
    $events = DB::table('events')->count();
    $before = phase4OrderRows();

    expect(DB::table('clients')->count())->toBe(8)
        ->and(DB::table('orders')->count())->toBe(14)
        ->and(DB::table('orders')->where('status', 'awaiting_cash')->count())->toBe(2)
        ->and(DB::table('orders')->where('status', 'awaiting_approval')->count())->toBe(1)
        ->and(DB::table('orders')->where('status', 'held')->count())->toBe(1)
        ->and(DB::table('invoices')->count())->toBe(1) // only the prepaid local order: the others are invoiced after dispatch (OrdersBillingSeeder)
        ->and(DB::table('payments')->count())->toBe(0)
        ->and(DB::table('cash_entries')->where('category', 'sale')->where(fn ($q) => $q->where('note', 'like', 'Cash for order%')->orWhere('note', 'like', 'Payment on%'))->count())->toBe(2);
    expect(DB::table('invoices')->where('balance', '>', 0)->count())->toBe(0);
    $juba = DB::table('orders')->where('id', OrdersSeeder::id('o_ss1_juba'))->first();
    expect((bool) $juba->over_limit)->toBeTrue()->and($juba->status)->toBe('awaiting_approval')
        ->and(DB::table('exceptions')->where(['kind' => 'credit_over_limit', 'subject_ref' => $juba->id, 'status' => 'open', 'owner_role' => 'ceo'])->exists())->toBeTrue()
        ->and(DB::table('exceptions')->where('kind', 'credit_over_limit')->count())->toBe(1);

    seedPhase4Orders();
    expect(DB::table('events')->count())->toBe($events);

    app(Replay::class)->rebuild();
    expect(phase4OrderRows())->toEqual($before);
});
