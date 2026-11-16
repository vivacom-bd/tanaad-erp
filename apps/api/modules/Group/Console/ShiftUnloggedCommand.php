<?php

declare(strict_types=1);

namespace Tanaad\Group\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\ConnectionInterface;
use Tanaad\Group\Support\NotLogged;
use Tanaad\Shared\Calendar\Calendar;
use Tanaad\Shared\Ledger\CommandBus;
use Tanaad\Shared\Ledger\Rejection;

/**
 * Hourly: once a site's current shift is N hours in (tanaad.production.unlogged_after_hours, default 6;
 * there is no site setting for it yet), each active machine at the wall stage with no production entry
 * for the shift raises not_logged through the CommandBus as the system. Idempotent per machine and
 * shift (RaiseException skips an open exception for the same subject). The wall stage is the stage
 * with the most non-retired machines, as in Production. Reads sites, stages, machines and
 * production_entries by table name only.
 */
final class ShiftUnloggedCommand extends Command
{
    protected $signature = 'production:shift-unlogged {--at= : Evaluate as of this time (ISO 8601), for testing}';

    protected $description = 'Raise not_logged exceptions for machines with no entry once a shift is N hours in';

    public function handle(CommandBus $bus, ConnectionInterface $db, Config $config): int
    {
        $at = $this->option('at');
        $now = is_string($at) && $at !== '' ? CarbonImmutable::parse($at) : CarbonImmutable::now();
        $hours = (float) $config->get('tanaad.production.unlogged_after_hours', 6);
        $raised = 0;
        foreach ($db->table('sites')->whereNull('deleted_at')->get(['id', 'code', 'timezone', 'shifts']) as $site) {
            $rows = json_decode((string) $site->shifts, true);
            $cal = Calendar::forSite((string) $site->code, is_array($rows) ? array_values($rows) : [], (string) $site->timezone);
            $shift = $cal->shiftAt($now);
            if ($shift === null) {
                continue;
            }
            $local = $cal->local($now);
            $elapsed = (($local->hour * 60 + $local->minute) - $shift->startMinute() + 1440) % 1440;
            if ($elapsed < $hours * 60) {
                continue;
            }
            $day = $cal->businessDay($now);
            $shiftId = $cal->shiftId($day, $shift->kind);
            $stage = self::wallStage($db, (string) $site->id);
            if ($stage === null) {
                continue;
            }
            $logged = $db->table('production_entries')->where('shift_id', $shiftId)->pluck('machine_id')->all();
            $machines = $db->table('machines')->where(['site_id' => $site->id, 'stage_code' => $stage, 'status' => 'active'])->whereNull('deleted_at')
                ->whereNotIn('id', $logged)->orderBy('code')->pluck('id');
            foreach ($machines as $machineId) {
                try {
                    $raised += $bus->dispatch(NotLogged::raise((string) $site->id, (string) $machineId, $shiftId, $day, $now, 'running'))->events === [] ? 0 : 1;
                } catch (Rejection $r) {
                    $this->warn("{$machineId}: {$r->getMessage()}");
                }
            }
        }
        $this->info("Raised {$raised}.");

        return self::SUCCESS;
    }

    private static function wallStage(ConnectionInterface $db, string $siteId): ?string
    {
        $counts = $db->table('machines')->where('site_id', $siteId)->whereNull('deleted_at')->where('status', '!=', 'retired')
            ->groupBy('stage_code')->selectRaw('stage_code, count(*) as n')->pluck('n', 'stage_code')->all();
        $best = null;
        $bestN = 0;
        foreach ($db->table('stages')->where('site_id', $siteId)->whereNull('deleted_at')->orderBy('sequence')->pluck('code') as $code) {
            $n = (int) ($counts[$code] ?? 0);
            if ($n > 0 && $n >= $bestN) {
                [$best, $bestN] = [(string) $code, $n];
            }
        }

        return $best;
    }
}
