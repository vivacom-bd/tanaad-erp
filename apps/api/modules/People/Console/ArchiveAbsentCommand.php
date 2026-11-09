<?php

declare(strict_types=1);

namespace Tanaad\People\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;
use Tanaad\Config\Models\Site;
use Tanaad\Config\Models\SiteSetting;
use Tanaad\People\Commands\ArchivePerson;
use Tanaad\People\Handlers\MarkAttendanceHandler;
use Tanaad\People\Models\Attendance;
use Tanaad\People\Models\Engagement;
use Tanaad\People\Models\Person;
use Tanaad\Shared\Ledger\CommandBus;
use Tanaad\Shared\Ledger\CommandContext;
use Tanaad\Shared\Ledger\Rejection;

/**
 * Archives active casuals with no attendance (present or half) at any site for N days,
 * N being the site's archive_after_absent_days setting, or tanaad.people.archive_after_absent_days
 * when the site has none. N ≤ 0 turns the job off for that site. Goes through the CommandBus.
 */
final class ArchiveAbsentCommand extends Command
{
    protected $signature = 'people:archive-absent {--site= : site code; every site when omitted}';

    protected $description = 'Archive casuals absent for the site\'s configured number of days';

    public function handle(CommandBus $bus, Config $config): int
    {
        $sites = Site::query()->when(is_string($this->option('site')), fn ($q) => $q->where('code', $this->option('site')))->get();
        $fallback = (int) $config->get('tanaad.people.archive_after_absent_days', 0);
        $total = 0;
        foreach ($sites as $site) {
            $setting = SiteSetting::query()->where('site_id', $site->id)->value('archive_after_absent_days');
            $days = $setting !== null && (int) $setting > 0 ? (int) $setting : $fallback;
            if ($days <= 0) {
                continue;
            }
            $now = CarbonImmutable::now();
            $cutoff = CarbonImmutable::parse($site->calendar()->businessDay($now))->subDays($days)->toDateString();
            $casuals = Engagement::query()->where(['site_id' => $site->id, 'status' => 'active', 'type' => 'casual'])->pluck('person_id');
            $people = Person::query()->where(['site_id' => $site->id, 'status' => 'active'])->whereIn('id', $casuals)
                ->whereNotIn('id', Engagement::query()->where('status', 'active')->where('type', '!=', 'casual')->select('person_id'))
                ->get();
            foreach ($people as $person) {
                $last = Attendance::query()->where('person_id', $person->id)->whereIn('status', MarkAttendanceHandler::WORKED)->max('day');
                $seen = substr((string) ($last ?? $person->joined_on), 0, 10);
                if ($seen > $cutoff) {
                    continue;
                }
                try {
                    $bus->dispatch(new ArchivePerson(new CommandContext($site->id, null, null, $now), [
                        'person_id' => $person->id, 'reason' => "No attendance for {$days} days",
                    ]));
                    $total++;
                } catch (Rejection $r) {
                    $this->warn("{$person->full_name}: {$r->getMessage()}");
                }
            }
        }
        $this->info("Archived {$total}.");

        return self::SUCCESS;
    }
}
