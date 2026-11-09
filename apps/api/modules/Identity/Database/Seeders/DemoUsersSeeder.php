<?php

declare(strict_types=1);

namespace Tanaad\Identity\Database\Seeders;

use Illuminate\Database\Seeder;
use Tanaad\Config\Models\Site;
use Tanaad\Identity\Models\User;
use Tanaad\Identity\Models\UserSiteRole;
use Tanaad\Identity\PinLockout;

/**
 * The prototype's demo users (apps/web/src/data/seed/build.ts). Password "password",
 * PIN 1234 for everyone, username is the code without "u_" and underscores (smbags). Demo only.
 */
final class DemoUsersSeeder extends Seeder
{
    public function run(PinLockout $pins): void
    {
        $sites = Site::query()->pluck('id', 'code');
        $rows = [
            ['u_ceo1', 'Hassan Warsame', 'HW', [['ceo', null]]],
            ['u_ceo2', 'Abdi Osman', 'AO', [['ceo', null]]],
            ['u_admin', 'Yasir Osman', 'YO', [['group_admin', null]]],
            ['u_sm_mats', 'Moses Tumusiime', 'MT', [['site_manager', 'mats']]],
            ['u_sm_bags', 'Grace Nabirye', 'GN', [['site_manager', 'bags']]],
            ['u_sm_rec', 'Ibrahim Farah', 'IF', [['site_manager', 'recycling']]],
            ['u_sup_mats', 'Amina Nakato', 'AN', [['supervisor', 'mats']]],
            ['u_sup_bags', 'Muzamir Ssentongo', 'MS', [['supervisor', 'bags']]],
            ['u_sup_rec', 'Said Elmi', 'SE', [['supervisor', 'recycling']]],
            ['u_cash_mats', 'Joseph Okello', 'JO', [['cashier', 'mats']]],
            ['u_cash_bags', 'Esther Namuli', 'EN', [['cashier', 'bags']]],
            ['u_cash_rec', 'Halima Jama', 'HJ', [['cashier', 'recycling']]],
            ['u_store_mats', 'Peter Mukasa', 'PM', [['storekeeper', 'mats'], ['gate', 'mats']]],
            ['u_store_bags', 'Ronald Kizza', 'RK', [['storekeeper', 'bags'], ['gate', 'bags']]],
            ['u_store_rec', 'Omar Hersi', 'OH', [['storekeeper', 'recycling'], ['gate', 'recycling']]],
            ['u_mech', 'Kato Mugisha', 'KM', [['mechanic', 'mats'], ['mechanic', 'recycling']]],
            ['u_sales_mats', 'Fatuma Ali', 'FA', [['sales', 'mats']]],
            ['u_sales_bags', 'Brian Lubega', 'BL', [['sales', 'bags']]],
            ['u_sales_rec', 'Nasra Mohamed', 'NM', [['sales', 'recycling']]],
        ];
        foreach ($rows as [$code, $name, $seed, $roles]) {
            $pin = '1234';
            $user = User::query()->updateOrCreate(['code' => $code], [
                'name' => $name,
                'username' => str_replace('_', '', substr($code, 2)),
                'password' => 'password',
                'pin_hash' => $pins->hash($pin),
                'avatar_seed' => $seed,
                'active' => true,
            ]);
            foreach ($roles as [$role, $siteCode]) {
                UserSiteRole::query()->firstOrCreate(['user_id' => $user->id, 'site_id' => $siteCode === null ? null : $sites[$siteCode], 'role' => $role]);
            }
        }
    }
}
