<?php

declare(strict_types=1);

namespace Database\Seeders;

use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Tanaad\Cash\Database\Seeders\CashSeeder;
use Tanaad\Config\Database\Seeders\ConfigSeeder;
use Tanaad\Config\Database\Seeders\SitesSeeder;
use Tanaad\Identity\Database\Seeders\DemoUsersSeeder;
use Tanaad\People\Database\Seeders\PeopleSeeder;
use Tanaad\People\Database\Seeders\SettlementSeeder;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([SitesSeeder::class, DemoUsersSeeder::class, ConfigSeeder::class, PeopleSeeder::class, CashSeeder::class, SettlementSeeder::class]);
        self::raiseContractExpiry();
    }

    /** The daily contract-expiry job, run once as of the prototype's pinned date (Saturday 10 October 2026, 07:00). */
    public static function raiseContractExpiry(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-10 07:00:00', 'Africa/Kampala'));

        try {
            Artisan::call('people:contract-expiry');
        } finally {
            CarbonImmutable::setTestNow();
        }
    }
}
