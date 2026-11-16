<?php

declare(strict_types=1);

namespace Tanaad\Costing\Database\Seeders;

use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tanaad\Costing\Commands\ClosePeriod;
use Tanaad\Costing\Support\Period;
use Tanaad\Shared\Ledger\CommandBus;
use Tanaad\Shared\Ledger\CommandContext;

/**
 * Closes the last full month before the seed's pinned day (2026-11-10, so October 2026) at every
 * site that has a cash-closed day in it, as the system, through the command bus. Run last (after
 * Cash, People, Production, Purchases, Spares and Orders). The event id is uuid5 of the site and
 * month, so a second run is all duplicates.
 */
final class CostingSeeder extends Seeder
{
    public const string NAMESPACE = 'a4c2e9d1-7b35-5f08-8e61-3d9b0c2f4a77';

    private const string TODAY = '2026-11-10';

    public function run(CommandBus $bus): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse(self::TODAY.' 12:00:00', 'Africa/Kampala'));

        try {
            $month = CarbonImmutable::parse(self::TODAY)->startOfMonth()->subMonth()->format('Y-m');
            $period = Period::month($month);
            foreach (DB::table('sites')->orderBy('code')->get(['id', 'code']) as $site) {
                $hasData = DB::table('costing_closed_days')->where(['site_id' => $site->id, 'closed' => true])
                    ->whereBetween('day', [$period->from, $period->to])->exists();
                $eventId = Uuid::uuid5(self::NAMESPACE, "close:{$site->code}:{$month}")->toString();
                if (! $hasData || DB::table('events')->where('id', $eventId)->exists()) {
                    continue;
                }
                $ctx = new CommandContext((string) $site->id, null, null, CarbonImmutable::parse(self::TODAY.'T03:00:00+03:00'), $eventId);
                $bus->dispatch(new ClosePeriod($ctx, ['month' => $month]));
            }
        } finally {
            CarbonImmutable::setTestNow();
        }
    }
}
