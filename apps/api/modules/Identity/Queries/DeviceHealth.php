<?php

declare(strict_types=1);

namespace Tanaad\Identity\Queries;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;

/** Latest heartbeat per active device, with a stale flag (never reported counts as stale). */
final class DeviceHealth
{
    public function __construct(private readonly ConnectionInterface $db) {}

    /**
     * @param  list<string>|null  $siteIds  null = every site
     * @return list<array<string, mixed>>
     */
    public function list(?array $siteIds, int $staleAfterMinutes, bool $onlyStale = false, ?CarbonImmutable $now = null): array
    {
        $cutoff = ($now ?? CarbonImmutable::now())->subMinutes($staleAfterMinutes);
        $rows = $this->db->table('devices as d')
            ->leftJoin('device_heartbeats as h', 'h.device_id', '=', 'd.id')
            ->join('sites as s', 's.id', '=', 'd.site_id')
            ->whereNull('d.deleted_at')->where('d.active', true)
            ->when($siteIds !== null, fn ($q) => $q->whereIn('d.site_id', $siteIds))
            ->orderBy('s.code')->orderBy('d.name')
            ->get(['d.id', 'd.name', 'd.kind', 'd.site_id', 's.code as site_code', 'h.app_version', 'h.online', 'h.outbox_depth', 'h.last_sync_at', 'h.reported_at']);

        $out = [];
        foreach ($rows as $r) {
            $reported = $r->reported_at === null ? null : CarbonImmutable::parse($r->reported_at);
            $stale = $reported === null || $reported->isBefore($cutoff);
            if ($onlyStale && ! $stale) {
                continue;
            }
            $out[] = [
                'device_id' => $r->id,
                'name' => $r->name,
                'kind' => $r->kind,
                'site_id' => $r->site_id,
                'site_code' => $r->site_code,
                'app_version' => $r->app_version,
                'online' => $r->online === null ? null : (bool) $r->online,
                'outbox_depth' => $r->outbox_depth === null ? null : (int) $r->outbox_depth,
                'last_sync_at' => $r->last_sync_at === null ? null : CarbonImmutable::parse($r->last_sync_at)->toIso8601String(),
                'reported_at' => $reported?->toIso8601String(),
                'stale' => $stale,
            ];
        }

        return $out;
    }
}
