<?php

declare(strict_types=1);

namespace Tanaad\Shared\Ledger;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Ramsey\Uuid\Uuid;

/**
 * Closed business days per ledger (cash, shift, stock). Handlers call assertOpen();
 * the close handlers call lock(); an authorised reopen calls reopen().
 */
final class DayLocks
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function isLocked(string $siteId, string $ledger, string $day): bool
    {
        return $this->db->table('day_locks')
            ->where(['site_id' => $siteId, 'ledger' => $ledger, 'day' => $day])
            ->whereNull('reopened_by_event')
            ->exists();
    }

    public function assertOpen(string $siteId, string $ledger, string $day): void
    {
        if ($this->isLocked($siteId, $ledger, $day)) {
            throw Rejection::dayLocked($ledger, $day);
        }
    }

    public function lock(string $siteId, string $ledger, string $day, string $eventId): void
    {
        $now = CarbonImmutable::now();
        $this->db->table('day_locks')->upsert(
            [[
                'id' => Uuid::uuid5(Uuid::NAMESPACE_URL, "tanaad:day_locks:{$siteId}|{$ledger}|{$day}")->toString(),
                'site_id' => $siteId,
                'ledger' => $ledger,
                'day' => $day,
                'locked_by_event' => $eventId,
                'reopened_by_event' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['site_id', 'ledger', 'day'],
            ['locked_by_event', 'reopened_by_event', 'updated_at'],
        );
    }

    public function reopen(string $siteId, string $ledger, string $day, string $eventId): void
    {
        $this->db->table('day_locks')
            ->where(['site_id' => $siteId, 'ledger' => $ledger, 'day' => $day])
            ->whereNull('reopened_by_event')
            ->update(['reopened_by_event' => $eventId, 'updated_at' => CarbonImmutable::now()]);
    }

    public function reset(): void
    {
        $this->db->table('day_locks')->truncate();
    }
}
