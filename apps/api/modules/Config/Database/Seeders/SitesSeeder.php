<?php

declare(strict_types=1);

namespace Tanaad\Config\Database\Seeders;

use Illuminate\Database\Seeder;
use Tanaad\Config\Models\Site;

/**
 * The three operations, mirroring apps/web/src/config/sites/*.ts. Idempotent on code.
 */
final class SitesSeeder extends Seeder
{
    public function run(): void
    {
        $shifts = [['kind' => 'day', 'starts' => '07:00', 'ends' => '19:00'], ['kind' => 'night', 'starts' => '19:00', 'ends' => '07:00']];
        $rows = [
            ['code' => 'mats', 'name' => 'Mats Factory', 'legal_name' => 'Tanaad · Mats factory', 'short_name' => 'Mats', 'tagline' => 'Woven mats', 'machine_wall_title' => 'Loom Wall', 'to_be_confirmed' => null],
            ['code' => 'bags', 'name' => 'Bags Factory', 'legal_name' => 'Tanaad Packages Ltd', 'short_name' => 'Bags', 'tagline' => 'Carrier bags', 'machine_wall_title' => 'Extruder Wall', 'to_be_confirmed' => 'Process after extrusion to be confirmed with Tanaad Packages. The roll sticker is confirmed and exact.'],
            ['code' => 'recycling', 'name' => 'Recycling Plant', 'legal_name' => 'Tanaad · Recycling plant', 'short_name' => 'Recycling', 'tagline' => 'Recycling', 'machine_wall_title' => 'Line Wall', 'to_be_confirmed' => 'Intake deduction rule, process stages and sack size to be confirmed with the plant.'],
        ];
        foreach ($rows as $row) {
            Site::query()->updateOrCreate(['code' => $row['code']], [...$row, 'currency_primary' => 'UGX', 'shifts' => $shifts, 'closed_days' => [0]]);
        }
    }
}
