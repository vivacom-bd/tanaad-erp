<?php

declare(strict_types=1);

namespace Tanaad\Identity\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tanaad\Config\Models\Site;
use Tanaad\Identity\Commands\AssignRole;
use Tanaad\Identity\Models\User;
use Tanaad\Identity\Models\UserSiteRole;
use Tanaad\Identity\PinLockout;
use Tanaad\Shared\Ledger\CommandBus;
use Tanaad\Shared\Ledger\CommandContext;

/**
 * The prototype's demo users (apps/web/src/data/seed/build.ts). Password "password",
 * PIN 1234 for everyone, username is the code without "u_" and underscores (smbags). Demo only.
 */
final class DemoUsersSeeder extends Seeder
{
    public const string NAMESPACE = '6f1d2b9e-4c3a-5e87-9b10-2d7c8a4e5f63';

    public function run(PinLockout $pins, CommandBus $bus): void
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
                // Role grants are identity.role_assigned events so a replay rebuilds them; a rerun skips what is there.
                $siteId = $siteCode === null ? null : (string) $sites[$siteCode];
                $eventId = Uuid::uuid5(self::NAMESPACE, "role:{$code}:{$role}:".($siteCode ?? 'group'))->toString();
                $held = UserSiteRole::query()->where(['user_id' => $user->id, 'role' => $role])->where('site_id', $siteId)->exists();
                if ($held || DB::table('events')->where('id', $eventId)->exists()) {
                    continue;
                }
                $bus->dispatch(new AssignRole(CommandContext::system($siteId)->withEventId($eventId), (string) $user->id, $role, $siteId));
            }
        }
    }
}
