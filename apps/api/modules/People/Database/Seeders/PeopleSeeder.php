<?php

declare(strict_types=1);

namespace Tanaad\People\Database\Seeders;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Seeder;
use Ramsey\Uuid\Uuid;
use Tanaad\Config\Commands\DefineNumberSeries;
use Tanaad\Config\Models\Site;
use Tanaad\Identity\Models\User;
use Tanaad\Identity\Models\UserSiteRole;
use Tanaad\People\Commands\ArchivePerson;
use Tanaad\People\Commands\EndEngagement;
use Tanaad\People\Commands\MarkAttendance;
use Tanaad\People\Commands\RegisterPerson;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\CommandBus;
use Tanaad\Shared\Ledger\CommandContext;

/**
 * The prototype's demo people, engagements and attendance (database/seed-data, exported by
 * `pnpm seed:export` from apps/web/src/data/seed/build.ts), replayed as People commands in
 * time order. Event and entity ids are uuid5 of the prototype ids, so a second run reports
 * every command as a duplicate. The site manager registers and archives, the supervisor in
 * the data marks attendance. Run after DemoUsersSeeder and ConfigSeeder.
 *
 * $site and $fromDay take a slice (one home site, attendance from a day) for tests.
 */
final class PeopleSeeder extends Seeder
{
    public const string NAMESPACE = '6f1d3c2a-8a54-5e0b-9a51-7d1f0c2e4b10';

    /** @var array<string, string> site code => id */
    private array $sites = [];

    /** @var array<string, string> user code => id */
    private array $users = [];

    public function __construct(private readonly ?string $site = null, private readonly ?string $fromDay = null) {}

    public static function id(string $name): string
    {
        return Uuid::uuid5(self::NAMESPACE, $name)->toString();
    }

    /** @return array{today: string, rows: list<array<string, mixed>>} */
    public static function data(string $name): array
    {
        $json = file_get_contents(database_path("seed-data/{$name}.json"));
        /** @var array{today: string, rows: list<array<string, mixed>>} $data */
        $data = json_decode((string) $json, true, flags: JSON_THROW_ON_ERROR);

        return $data;
    }

    public function run(CommandBus $bus, ConnectionInterface $db): void
    {
        $this->sites = Site::query()->pluck('id', 'code')->map(fn ($id): string => (string) $id)->all();
        $this->users = User::query()->pluck('id', 'code')->map(fn ($id): string => (string) $id)->all();
        $admin = $this->users['u_admin'];
        // people.number is unique across the group, so each site gets its own prefix (MAT-0001), as the prototype's label codes do.
        foreach ($this->sites as $code => $siteId) {
            if (! $db->table('number_series')->where(['site_id' => $siteId, 'kind' => 'person'])->exists()) {
                $bus->dispatch(new DefineNumberSeries(new CommandContext($siteId, $admin, null, CarbonImmutable::parse('2026-11-01 08:00:00', 'Africa/Kampala')), ['kind' => 'person', 'prefix' => strtoupper(substr($code, 0, 3)), 'pad' => 4, 'next' => 1]));
            }
        }
        foreach ($this->commands() as [, $command]) {
            $bus->dispatch($command);
        }
    }

    /** @return list<array{string, Command}> [sort key, command] in time order */
    private function commands(): array
    {
        $data = self::data('people');
        $today = CarbonImmutable::parse($data['today'], 'Africa/Kampala');
        $people = array_values(array_filter($data['rows'], fn (array $p): bool => $this->site === null || $p['site'] === $this->site));
        $ids = array_flip(array_column($people, 'id'));
        $engagements = [];
        foreach (self::data('engagements')['rows'] as $e) {
            $engagements[(string) $e['person_id']] = $e;
        }
        $managers = UserSiteRole::query()->where('role', 'site_manager')->whereNotNull('site_id')->get()
            ->mapWithKeys(fn (UserSiteRole $r): array => [(string) $r->site_id => (string) $r->user_id])->all();
        $out = [];
        foreach ($people as $p) {
            $e = $engagements[(string) $p['id']];
            $siteId = $this->sites[(string) $p['site']];
            $manager = $managers[$siteId];
            $first = min((string) $p['joined_on'], (string) $e['start_date']);
            $at = CarbonImmutable::parse($first.' 07:00:00', 'Africa/Kampala');
            $out[] = [$at->toIso8601String().'|0|'.$p['id'], new RegisterPerson($this->ctx($siteId, $manager, $at, 'register:'.$p['id']), [
                'person_id' => self::id('person:'.$p['id']), 'full_name' => $p['full_name'], 'id_number' => $p['id_number'], 'phone_momo' => $p['phone_momo'],
                'sector' => $p['sector'], 'shared' => $p['shared'],
                'shared_sites' => array_map(fn (mixed $c): string => $this->sites[(string) $c], (array) $p['shared_sites']), 'joined_on' => $p['joined_on'],
                'engagement' => [
                    'engagement_id' => self::id('engagement:'.$e['id']), 'type' => $e['type'], 'start_date' => $e['start_date'], 'end_date' => $e['end_date'],
                    'term_days' => $e['term_days'], 'rate_basis' => $e['rate_basis'], 'rate' => $e['rate'], 'currency' => $e['currency'], 'settlement_cycle' => $e['settlement_cycle'],
                ],
            ])];
            if ($p['status'] === 'archived') {
                // The prototype gives no archive date; these people never appear in its 14-day attendance window.
                $at = $today->subDays(14)->setTime(9, 0);
                $out[] = [$at->toIso8601String().'|1|'.$p['id'], new EndEngagement($this->ctx($siteId, $manager, $at, 'end:'.$e['id']), ['engagement_id' => self::id('engagement:'.$e['id']), 'end_date' => $at->toDateString()])];
                $out[] = [$at->toIso8601String().'|2|'.$p['id'], new ArchivePerson($this->ctx($siteId, $manager, $at, 'archive:'.$p['id']), ['person_id' => self::id('person:'.$p['id'])])];
            }
        }
        foreach (self::data('attendance')['rows'] as $a) {
            if (! isset($ids[$a['person_id']]) || ($this->fromDay !== null && $a['day'] < $this->fromDay)) {
                continue;
            }
            $at = CarbonImmutable::parse((string) $a['at'])->setTimezone('Africa/Kampala');
            $out[] = [$at->toIso8601String().'|3|'.$a['id'], new MarkAttendance($this->ctx($this->sites[(string) $a['site']], $this->users[(string) $a['marked_by']], $at, 'attendance:'.$a['id']), [
                'person_id' => self::id('person:'.$a['person_id']), 'day' => $a['day'], 'shift_kind' => $a['shift'], 'status' => $a['status'], 'overtime_hours' => $a['overtime_hours'],
            ])];
        }
        usort($out, fn (array $x, array $y): int => strcmp($x[0], $y[0]));

        return $out;
    }

    private function ctx(string $siteId, string $actorId, CarbonImmutable $at, string $source): CommandContext
    {
        return new CommandContext($siteId, $actorId, null, $at, self::id('event:'.$source));
    }
}
