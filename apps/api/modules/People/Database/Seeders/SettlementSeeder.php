<?php

declare(strict_types=1);

namespace Tanaad\People\Database\Seeders;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Seeder;
use Ramsey\Uuid\Uuid;
use Tanaad\Config\Models\Site;
use Tanaad\Identity\Models\User;
use Tanaad\People\Commands\ApproveSettlement;
use Tanaad\People\Commands\CreateSettlementRun;
use Tanaad\People\Commands\GiveAdvance;
use Tanaad\People\Commands\RecordPayout;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\CommandBus;
use Tanaad\Shared\Ledger\CommandContext;

/**
 * Settlement runs for Mats, built from the seeded attendance through the real commands (the
 * prototype computes its Settlement screen live and keeps no runs). Three runs:
 * - last week's casual wages (Mon 28 Sep to Sat 3 Oct): created, approved, every line paid by mobile money;
 * - this week's casual wages (Mon 5 to Sat 10 Oct): three advances given that morning are deducted,
 *   then the run is approved and about two thirds of the lines are paid (cash and mobile money), the rest left unpaid;
 * - the contracts ending within a week, including the one that has already ended: its line is paid.
 * The site manager creates, the CEO approves, the cashier pays and gives advances. Amounts are
 * the handler's, in whole shillings. Run after PeopleSeeder and CashSeeder: cash payouts land in
 * the open 10 October cash day. Ids are uuid5 of names, so a second run is all duplicates.
 */
final class SettlementSeeder extends Seeder
{
    public const string NAMESPACE = '9c2b7d54-0e8a-5f31-8a64-b1d3e5f70a29';

    public static function id(string $name): string
    {
        return Uuid::uuid5(self::NAMESPACE, $name)->toString();
    }

    public function run(CommandBus $bus, ConnectionInterface $db): void
    {
        $site = Site::query()->where('code', 'mats')->firstOrFail();
        $user = fn (string $code): string => (string) User::query()->where('code', $code)->value('id');
        [$manager, $ceo, $cashier] = [$user('u_sm_mats'), $user('u_ceo1'), $user('u_cash_mats')];
        $ctx = fn (string $actor, string $at, string $source): CommandContext => new CommandContext(
            $site->id, $actor, null, CarbonImmutable::parse($at, 'Africa/Kampala'), self::id('event:'.$source),
        );
        $dispatch = fn (Command $c) => $bus->dispatch($c);
        /** @return list<string> person ids of a run's lines, by name so the order is the same every time */
        $lines = fn (string $runId): array => $db->table('settlement_lines')->join('people', 'people.id', '=', 'settlement_lines.person_id')
            ->where('settlement_lines.settlement_run_id', $runId)->orderBy('people.full_name')->orderBy('people.id')->pluck('settlement_lines.person_id')->map(fn ($id): string => (string) $id)->all();
        $pay = function (string $key, string $runId, array $people, string $at, callable $method) use ($dispatch, $ctx, $cashier): void {
            foreach ($people as $i => $personId) {
                $when = CarbonImmutable::parse($at, 'Africa/Kampala')->addSeconds($i * 40)->toIso8601String();
                $m = $method($i);
                $dispatch(new RecordPayout($ctx($cashier, $when, "payout:{$key}:{$personId}"), [
                    'payout_id' => self::id("payout:{$key}:{$personId}"), 'settlement_run_id' => $runId, 'person_id' => $personId,
                    'method' => $m, 'paid_at' => $when, 'reference' => $m === 'mobile_money' ? sprintf('MM%09d', (crc32("{$key}:{$personId}") % 900_000_000) + 100_000_000) : null,
                ]));
            }
        };

        // Last week: fully paid.
        $a = self::id('run:weekly-2026-10-03');
        $dispatch(new CreateSettlementRun($ctx($manager, '2026-10-03T15:00:00', 'create:'.$a), ['settlement_run_id' => $a, 'kind' => 'weekly_casual', 'from' => '2026-09-28', 'to' => '2026-10-03']));
        $dispatch(new ApproveSettlement($ctx($ceo, '2026-10-03T15:30:00', 'approve:'.$a), ['settlement_run_id' => $a]));
        $pay('a', $a, $lines($a), '2026-10-03T16:00:00', fn (int $i): string => 'mobile_money');

        // This week: advances first, so the run deducts them.
        $worked = $db->table('attendance')->where('site_id', $site->id)->whereBetween('day', ['2026-10-05', '2026-10-10'])->where('status', 'present')
            ->whereIn('person_id', $db->table('engagements')->where(['site_id' => $site->id, 'type' => 'casual', 'status' => 'active'])->select('person_id'))
            ->groupBy('person_id')->havingRaw('count(distinct day) >= 5')->pluck('person_id')->map(fn ($id): string => (string) $id)->all();
        $names = $db->table('people')->whereIn('id', $worked)->orderBy('full_name')->orderBy('id')->pluck('id')->map(fn ($id): string => (string) $id)->all();
        foreach ([[0, 20_000, 'School fees'], [6, 30_000, 'Medical'], [13, 50_000, 'Rent']] as [$at, $amount, $reason]) {
            if (isset($names[$at])) {
                $dispatch(new GiveAdvance($ctx($cashier, '2026-10-10T10:00:00', "advance:{$names[$at]}"), [
                    'advance_id' => self::id("advance:{$names[$at]}"), 'person_id' => $names[$at], 'amount' => $amount, 'reason' => $reason, 'given_on' => '2026-10-10',
                ]));
            }
        }
        $b = self::id('run:weekly-2026-10-10');
        $dispatch(new CreateSettlementRun($ctx($manager, '2026-10-10T15:00:00', 'create:'.$b), ['settlement_run_id' => $b, 'kind' => 'weekly_casual', 'from' => '2026-10-05', 'to' => '2026-10-10']));
        $dispatch(new ApproveSettlement($ctx($ceo, '2026-10-10T15:20:00', 'approve:'.$b), ['settlement_run_id' => $b]));
        $people = $lines($b);
        $paid = array_slice($people, 0, intdiv(count($people) * 2, 3));
        $pay('b', $b, $paid, '2026-10-10T15:30:00', fn (int $i): string => $i % 3 === 0 ? 'cash' : 'mobile_money');

        // Contracts ending within a week of today, including the one already ended.
        $c = self::id('run:end-of-term-2026-10-10');
        $dispatch(new CreateSettlementRun($ctx($manager, '2026-10-10T15:05:00', 'create:'.$c), ['settlement_run_id' => $c, 'kind' => 'end_of_term', 'from' => '2026-10-10', 'to' => '2026-10-17']));
        $dispatch(new ApproveSettlement($ctx($ceo, '2026-10-10T15:25:00', 'approve:'.$c), ['settlement_run_id' => $c]));
        $ended = $db->table('settlement_lines')->join('engagements', 'engagements.id', '=', 'settlement_lines.engagement_id')
            ->where('settlement_lines.settlement_run_id', $c)->where('engagements.end_date', '<', '2026-10-10')->pluck('settlement_lines.person_id')->map(fn ($id): string => (string) $id)->all();
        $pay('c', $c, $ended, '2026-10-10T16:30:00', fn (int $i): string => 'mobile_money');
    }
}
