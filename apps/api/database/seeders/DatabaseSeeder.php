<?php

declare(strict_types=1);

namespace Database\Seeders;

use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Tanaad\Cash\Database\Seeders\CashContinuationSeeder;
use Tanaad\Cash\Database\Seeders\CashSeeder;
use Tanaad\Config\Database\Seeders\ConfigSeeder;
use Tanaad\Config\Database\Seeders\SitesSeeder;
use Tanaad\Dispatch\Database\Seeders\DispatchSeeder;
use Tanaad\Identity\Database\Seeders\DemoUsersSeeder;
use Tanaad\Labels\Database\Seeders\LabelSeeder;
use Tanaad\Orders\Database\Seeders\OrdersBillingSeeder;
use Tanaad\Orders\Database\Seeders\OrdersSeeder;
use Tanaad\People\Database\Seeders\ContinuationSeeder;
use Tanaad\People\Database\Seeders\PeopleSeeder;
use Tanaad\People\Database\Seeders\SettlementSeeder;
use Tanaad\Production\Database\Seeders\ProductionSeeder;
use Tanaad\Purchases\Database\Seeders\PurchasesSeeder;
use Tanaad\Recycling\Database\Seeders\RecyclingSeeder;
use Tanaad\Shared\Ledger\ReceiptClock;
use Tanaad\Spares\Database\Seeders\SparesSeeder;
use Tanaad\Stock\Database\Seeders\StockSeeder;

final class DatabaseSeeder extends Seeder
{
    public function run(ReceiptClock $clock): void
    {
        // Business time is pinned per seeder; receipt time stays the real, monotonic server clock.
        $clock->whileSeeding(fn () => $this->seedAll());
    }

    private function seedAll(): void
    {
        // Every module's history ends on the same business day: the one before the demo date (Monday 9 November), with
        // the demo date itself (10 November) open. People, Cash and Settlement start from the prototype's data (to 10 October).
        $this->call([SitesSeeder::class, DemoUsersSeeder::class, ConfigSeeder::class, PeopleSeeder::class, CashSeeder::class, SettlementSeeder::class, ContinuationSeeder::class, CashContinuationSeeder::class]);
        // Recycling's collector payments land in its cash book day by day, so each day is closed right after its payments.
        foreach (RecyclingSeeder::days() as $day) {
            app()->call([new RecyclingSeeder($day), 'run']);
            if ($day <= CashContinuationSeeder::LAST) {
                app()->call([new CashContinuationSeeder('recycling', $day, $day), 'run']);
            }
        }
        // Orders first (the dispatches are built from real orders and move them), then the invoices of what was delivered.
        $this->call([ProductionSeeder::class, LabelSeeder::class, PurchasesSeeder::class, SparesSeeder::class, StockSeeder::class, OrdersSeeder::class, DispatchSeeder::class, OrdersBillingSeeder::class]);
        self::raiseContractExpiry();
        $this->call(\Tanaad\Costing\Database\Seeders\CostingSeeder::class); // last: closes the last full month from the seeded books
    }

    /** The daily contract-expiry job, run once as of the demo date (Tuesday 10 November 2026, 07:00) unless a date is given. */
    public static function raiseContractExpiry(string $at = '2026-11-10 07:00:00'): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse($at, 'Africa/Kampala'));

        try {
            Artisan::call('people:contract-expiry');
        } finally {
            CarbonImmutable::setTestNow();
        }
    }
}
