<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Tanaad\Config\Database\Seeders\ConfigSeeder;
use Tanaad\Config\Database\Seeders\SitesSeeder;
use Tanaad\Identity\Database\Seeders\DemoUsersSeeder;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([SitesSeeder::class, ConfigSeeder::class, DemoUsersSeeder::class]);
    }
}
