<?php

declare(strict_types=1);

namespace Tanaad\People\Database\Seeders;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Seeder;
use Ramsey\Uuid\Uuid;
use Tanaad\People\Commands\ApproveSettlement;
use Tanaad\People\Commands\CreateSettlementRun;
use Tanaad\People\Commands\GiveAdvance;
use Tanaad\People\Commands\MarkAttendance;
use Tanaad\People\Commands\RecordPayout;
use Tanaad\People\Commands\RenewEngagement;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\CommandBus;
use Tanaad\Shared\Ledger\CommandContext;

/**
 * Carries the people data from the prototype's last day (Saturday 10 October) up to the business day before the
 * demo date (Monday 9 November), deterministically and through the real commands, day by day (Sundays are rest days):
 * - attendance for everyone still engaged, at their home site and usual shift, marked by that site's supervisor
 *   (mostly present, some half days and absences, some overtime; nobody is marked at a second site, so the
 *   ghost-worker cases stay the ones in the prototype data);
 * - contracts renewed on their last day for the same terms, except the ones left to run out: two ended (one is the
 *   contract that already ended on 9 October) and three that end within the site's warning days of the demo date;
 *   every other contract runs past that window, so most are active;
 * - at Mats, the weekly casual settlement of each finished week (created by the site manager, approved by the CEO,
 *   paid by mobile money; the latest week is paid for two thirds of the lines), the previous week's unpaid lines paid,
 *   and the monthly staff settlement for October, paid by bank;
 * - two advances on the demo date, given by the cashier (they are not deducted yet).
 * Run after SettlementSeeder and CashSeeder. Cash is untouched (every payout is mobile money or bank); advances land
 * in the open cash day of the demo date. Ids are uuid5 of names, so a second run adds nothing.
 */
final class ContinuationSeeder extends Seeder
{
    public const string NAMESPACE = 'd0a4e6b2-7c13-5e98-b2f4-3a1c8d5e9f70';

    public const string FROM = '2026-10-11';

    public const string LAST = '2026-11-09';

    private const string TODAY = '2026-11-10';

    /** Contract tails: engagement => the end date its last renewal is cut to (the first one ended on 9 October and is not renewed). */
    private const array ENDS = ['eng_uz' => '2026-10-09', 'eng_st' => '2026-11-06', 'eng_sv' => '2026-11-11', 'eng_sx' => '2026-11-12', 'eng_sz' => '2026-11-13'];

    /** The warning window past the demo date: a contract left to run must end after it. */
    private const string CLEAR_OF_WARNING = '2026-11-14';

    private const array SUPERVISORS = ['mats' => 'u_sup_mats', 'bags' => 'u_sup_bags', 'recycling' => 'u_sup_rec'];

    private \Random\Randomizer $rng;

    /** @var array<string, true> */
    private array $done = [];

    public static function id(string $name): string
    {
        return Uuid::uuid5(self::NAMESPACE, $name)->toString();
    }

    public function run(CommandBus $bus, ConnectionInterface $db): void
    {
        $this->rng = new \Random\Randomizer(new \Random\Engine\Mt19937(20261111));
        $this->done = array_fill_keys($db->table('events')->where('type', 'like', 'people.%')->pluck('id')->all(), true);
        CarbonImmutable::setTestNow(CarbonImmutable::parse(self::TODAY.' 12:00:00', 'Africa/Kampala'));

        try {
            $this->seed($bus, $db);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    private function seed(CommandBus $bus, ConnectionInterface $db): void
    {
        $sites = $db->table('sites')->pluck('id', 'code')->map(fn ($id): string => (string) $id)->all();
        $user = fn (string $code): string => (string) $db->table('users')->where('code', $code)->value('id');
        $managers = $db->table('user_site_roles')->where('role', 'site_manager')->whereNotNull('site_id')->pluck('user_id', 'site_id')->map(fn ($id): string => (string) $id)->all();
        $supervisors = array_map(fn (string $code): string => $user($code), self::SUPERVISORS);
        [$ceo, $cashier] = [$user('u_ceo1'), $user('u_cash_mats')];
        $at = fn (string $day, string $time): CarbonImmutable => CarbonImmutable::parse("{$day}T{$time}+03:00");
        $send = function (string $source, Command $command) use ($bus): void {
            if (! isset($this->done[self::id("event:{$source}")])) {
                $bus->dispatch($command);
            }
        };
        $ctx = fn (string $siteId, string $actor, string $day, string $time, string $source): CommandContext => new CommandContext($siteId, $actor, null, $at($day, $time), self::id("event:{$source}"));

        // Who works where, on which shift (their usual one at the home site), and the engagement ranges that cover them.
        $people = [];
        foreach (PeopleSeeder::data('people')['rows'] as $p) {
            if ($p['status'] === 'active') {
                $people[(string) $p['id']] = $p;
            }
        }
        $shifts = [];
        foreach (PeopleSeeder::data('attendance')['rows'] as $a) {
            if (isset($people[$a['person_id']]) && $a['site'] === $people[$a['person_id']]['site']) {
                $shifts[$a['person_id']][$a['shift']] = ($shifts[$a['person_id']][$a['shift']] ?? 0) + 1;
            }
        }
        $ranges = [];
        $renewals = [];
        foreach (PeopleSeeder::data('engagements')['rows'] as $e) {
            if (! isset($people[$e['person_id']])) {
                continue;
            }
            $ranges[$e['person_id']] = [[(string) $e['start_date'], $e['end_date']]];
            if ($e['type'] === 'contract' && $e['status'] === 'active') {
                $renewals = [...$renewals, ...$this->chain($e)];
            }
        }
        usort($renewals, fn (array $x, array $y): int => [$x['on'], $x['new_id']] <=> [$y['on'], $y['new_id']]);
        foreach ($renewals as $r) {
            $ranges[$r['person_id']][] = [$r['start'], $r['end']];
        }
        ksort($people);

        $weeks = [];
        for ($day = CarbonImmutable::parse(self::FROM); $day->toDateString() <= self::LAST; $day = $day->addDay()) {
            $d = $day->toDateString();
            foreach ($renewals as $r) {
                if ($r['on'] === $d) {
                    $siteId = $sites[$r['site']];
                    $send("renew:{$r['new_id']}", new RenewEngagement($ctx($siteId, $managers[$siteId], $d, '06:30:00', "renew:{$r['new_id']}"), [
                        'engagement_id' => $r['old_id'], 'new_engagement_id' => $r['new_id'], 'start_date' => $r['start'], 'term_days' => $r['term'],
                    ]));
                }
            }
            if ($day->dayOfWeek === 0) {
                continue;
            }
            $n = 0;
            foreach ($people as $pid => $p) {
                $covered = false;
                foreach ($ranges[$pid] ?? [] as [$start, $end]) {
                    $covered = $covered || ($start <= $d && ($end === null || $d <= $end));
                }
                $roll = $this->rng->getInt(0, 99);
                $overtime = $this->rng->getInt(0, 9) === 0 ? $this->rng->getInt(1, 2) : 0;
                if (! $covered || $p['joined_on'] > $d || ! isset($shifts[$pid])) {
                    continue;
                }
                $status = $roll < 87 ? 'present' : ($roll < 92 ? 'half' : 'absent');
                $mix = $shifts[$pid];
                arsort($mix);
                $shift = (string) array_key_first($mix);
                $time = $shift === 'night' ? sprintf('19:%02d:00', 5 + $n++ % 40) : sprintf('%02d:%02d:00', 7 + intdiv($n, 50), $n++ % 50 + 5);
                $siteId = $sites[(string) $p['site']];
                $send("attendance:{$pid}:{$d}", new MarkAttendance($ctx($siteId, $supervisors[(string) $p['site']], $d, $time, "attendance:{$pid}:{$d}"), [
                    'person_id' => PeopleSeeder::id('person:'.$pid), 'day' => $d, 'shift_kind' => $shift, 'status' => $status,
                    'overtime_hours' => $status === 'present' ? $overtime : 0,
                ]));
            }
            if ($day->dayOfWeek === 6) {
                $weeks[] = $d;
                $this->weeklyRun($bus, $db, $sites['mats'], $managers[$sites['mats']], $ceo, $cashier, $d, $day->subDays(5)->toDateString(), $d === self::LAST || $d === '2026-11-07');
            }
            if ($d === '2026-10-12') {
                $this->payLeftovers($db, $ctx, $send, $sites['mats'], $cashier);
            }
            if ($d === '2026-11-02') {
                $this->monthlyRun($db, $ctx, $send, $sites['mats'], $managers[$sites['mats']], $ceo, $cashier);
            }
        }
        $this->advances($db, $ctx, $send, $sites['mats'], $cashier);
    }

    /**
     * The renewals of one contract, each on the previous one's last day, until it is past the warning window (or its
     * designated end date, where the last term is cut to land on it).
     *
     * @param  array<string, mixed>  $e
     * @return list<array{on: string, site: string, person_id: string, old_id: string, new_id: string, start: string, end: string, term: int}>
     */
    private function chain(array $e): array
    {
        $key = (string) $e['id'];
        $target = self::ENDS[$key] ?? null;
        $out = [];
        $end = (string) $e['end_date'];
        $old = PeopleSeeder::id('engagement:'.$key);
        $term = max(1, (int) $e['term_days']);
        for ($n = 1; $end <= self::LAST && $end < ($target ?? self::CLEAR_OF_WARNING); $n++) {
            $length = $target === null ? $term : min($term, (int) CarbonImmutable::parse($end)->diffInDays(CarbonImmutable::parse($target)));
            $new = self::id("engagement:{$key}:{$n}");
            $next = CarbonImmutable::parse($end)->addDays($length)->toDateString();
            if ($target === null && $next >= self::TODAY && $next < self::CLEAR_OF_WARNING) {
                // A contract left to run must not end inside the warning window of the demo date.
                $length = (int) CarbonImmutable::parse($end)->diffInDays(CarbonImmutable::parse(self::CLEAR_OF_WARNING));
                $next = self::CLEAR_OF_WARNING;
            }
            $out[] = ['on' => $end, 'site' => (string) $e['site'], 'person_id' => (string) $e['person_id'], 'old_id' => $old, 'new_id' => $new, 'start' => $end, 'end' => $next, 'term' => $length];
            [$old, $end] = [$new, $next];
        }

        return $out;
    }

    /** One finished week of casual wages at Mats. */
    private function weeklyRun(CommandBus $bus, ConnectionInterface $db, string $siteId, string $manager, string $ceo, string $cashier, string $to, string $from, bool $partly): void
    {
        $runId = self::id("run:weekly-{$to}");
        $send = function (string $source, Command $command) use ($bus): void {
            if (! isset($this->done[self::id("event:{$source}")])) {
                $bus->dispatch($command);
            }
        };
        $ctx = fn (string $actor, string $time, string $source): CommandContext => new CommandContext($siteId, $actor, null, CarbonImmutable::parse("{$to}T{$time}+03:00"), self::id("event:{$source}"));
        $send("create:{$runId}", new CreateSettlementRun($ctx($manager, '15:00:00', "create:{$runId}"), ['settlement_run_id' => $runId, 'kind' => 'weekly_casual', 'from' => $from, 'to' => $to]));
        $send("approve:{$runId}", new ApproveSettlement($ctx($ceo, '15:30:00', "approve:{$runId}"), ['settlement_run_id' => $runId]));
        $lines = $db->table('settlement_lines')->join('people', 'people.id', '=', 'settlement_lines.person_id')->where('settlement_lines.settlement_run_id', $runId)
            ->where('settlement_lines.net', '>', 0)->orderBy('people.full_name')->orderBy('people.id')->pluck('settlement_lines.person_id')->map(fn ($id): string => (string) $id)->all();
        $pay = $partly ? array_slice($lines, 0, intdiv(count($lines) * 2, 3)) : $lines;
        foreach ($pay as $i => $personId) {
            $this->payout($send, $ctx, $cashier, "{$runId}", $runId, $personId, 'mobile_money', CarbonImmutable::parse("{$to}T16:00:00+03:00")->addSeconds($i * 40));
        }
    }

    /** The lines left unpaid in the 10 October wage run are paid on the Monday after. */
    private function payLeftovers(ConnectionInterface $db, callable $ctx, callable $send, string $siteId, string $cashier): void
    {
        $runId = SettlementSeeder::id('run:weekly-2026-10-10');
        $lines = $db->table('settlement_lines')->join('people', 'people.id', '=', 'settlement_lines.person_id')->where('settlement_lines.settlement_run_id', $runId)
            ->where('settlement_lines.status', 'unpaid')->where('settlement_lines.net', '>', 0)->orderBy('people.full_name')->orderBy('people.id')->pluck('settlement_lines.person_id')->map(fn ($id): string => (string) $id)->all();
        $ctxFor = fn (string $actor, string $time, string $source): CommandContext => $ctx($siteId, $actor, '2026-10-12', $time, $source);
        foreach ($lines as $i => $personId) {
            $this->payout($send, $ctxFor, $cashier, 'late-b', $runId, $personId, 'mobile_money', CarbonImmutable::parse('2026-10-12T09:00:00+03:00')->addSeconds($i * 40));
        }
    }

    /** October's monthly staff settlement, paid by bank. */
    private function monthlyRun(ConnectionInterface $db, callable $ctx, callable $send, string $siteId, string $manager, string $ceo, string $cashier): void
    {
        $runId = self::id('run:monthly-2026-10');
        $day = '2026-11-02';
        $c = fn (string $actor, string $time, string $source): CommandContext => $ctx($siteId, $actor, $day, $time, $source);
        $send("create:{$runId}", new CreateSettlementRun($c($manager, '10:00:00', "create:{$runId}"), ['settlement_run_id' => $runId, 'kind' => 'monthly', 'from' => '2026-10-01', 'to' => '2026-10-31']));
        $send("approve:{$runId}", new ApproveSettlement($c($ceo, '10:30:00', "approve:{$runId}"), ['settlement_run_id' => $runId]));
        $lines = $db->table('settlement_lines')->join('people', 'people.id', '=', 'settlement_lines.person_id')->where('settlement_lines.settlement_run_id', $runId)
            ->where('settlement_lines.net', '>', 0)->orderBy('people.full_name')->orderBy('people.id')->pluck('settlement_lines.person_id')->map(fn ($id): string => (string) $id)->all();
        foreach ($lines as $i => $personId) {
            $this->payout($send, $c, $cashier, 'monthly', $runId, $personId, 'bank', CarbonImmutable::parse("{$day}T11:00:00+03:00")->addSeconds($i * 40));
        }
    }

    /** Two advances on the demo date, to casual workers who worked most of the last full week. */
    private function advances(ConnectionInterface $db, callable $ctx, callable $send, string $siteId, string $cashier): void
    {
        $worked = $db->table('attendance')->where('site_id', $siteId)->whereBetween('day', ['2026-11-02', '2026-11-07'])->where('status', 'present')
            ->whereIn('person_id', $db->table('engagements')->where(['site_id' => $siteId, 'type' => 'casual', 'status' => 'active'])->select('person_id'))
            ->groupBy('person_id')->havingRaw('count(distinct day) >= 5')->pluck('person_id')->map(fn ($id): string => (string) $id)->all();
        $names = $db->table('people')->whereIn('id', $worked)->orderBy('full_name')->orderBy('id')->pluck('id')->map(fn ($id): string => (string) $id)->all();
        foreach ([[2, 40_000, 'School fees'], [9, 25_000, 'Medical']] as [$at, $amount, $reason]) {
            if (isset($names[$at])) {
                $send("advance:{$names[$at]}", new GiveAdvance($ctx($siteId, $cashier, self::TODAY, '10:00:00', "advance:{$names[$at]}"), [
                    'advance_id' => self::id("advance:{$names[$at]}"), 'person_id' => $names[$at], 'amount' => $amount, 'reason' => $reason, 'given_on' => self::TODAY,
                ]));
            }
        }
    }

    private function payout(callable $send, callable $ctx, string $cashier, string $key, string $runId, string $personId, string $method, CarbonImmutable $when): void
    {
        $source = "payout:{$key}:{$personId}";
        $send($source, new RecordPayout($ctx($cashier, $when->format('H:i:s'), $source), [
            'payout_id' => self::id($source), 'settlement_run_id' => $runId, 'person_id' => $personId, 'method' => $method, 'paid_at' => $when->toIso8601String(),
            'reference' => $method === 'mobile_money' ? sprintf('MM%09d', (crc32($source) % 900_000_000) + 100_000_000) : sprintf('BK%09d', (crc32($source) % 900_000_000) + 100_000_000),
        ]));
    }
}
