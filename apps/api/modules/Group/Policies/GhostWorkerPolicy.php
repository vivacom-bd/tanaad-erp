<?php

declare(strict_types=1);

namespace Tanaad\Group\Policies;

use Illuminate\Database\ConnectionInterface;
use Tanaad\Group\Commands\RaiseException;
use Tanaad\Shared\Ledger\CommandContext;
use Tanaad\Shared\Ledger\Policy;
use Tanaad\Shared\Ledger\StoredEvent;

/**
 * people.attendance_marked with cross_site, or with the same person worked (present/half)
 * at another site that day, → ghost_worker (subject: "{person_id}:{day}"). Reads People's
 * attendance table by name only.
 */
final class GhostWorkerPolicy implements Policy
{
    private const array WORKED = ['present', 'half'];

    public function __construct(private readonly ConnectionInterface $db) {}

    public function handles(): array
    {
        return ['people.attendance_marked'];
    }

    public function react(StoredEvent $event): array
    {
        $p = $event->payload();
        $site = $event->siteId();
        $person = $p['person_id'] ?? null;
        $day = $p['day'] ?? null;
        if ($site === null || ! is_string($person) || ! is_string($day)) {
            return [];
        }
        $crossSite = ($p['cross_site'] ?? false) === true;
        $otherSites = [];
        if (in_array($p['status'] ?? null, self::WORKED, true)) {
            $otherSites = $this->db->table('attendance')->where(['person_id' => $person, 'day' => $day])
                ->where('site_id', '!=', $site)->whereIn('status', self::WORKED)->whereNull('deleted_at')
                ->distinct()->pluck('site_id')->map(fn ($s): string => (string) $s)->all();
        }
        if (! $crossSite && $otherSites === []) {
            return [];
        }
        $name = $this->db->table('people')->where('id', $person)->value('full_name');
        $who = is_string($name) ? $name : 'A worker';

        return [new RaiseException(new CommandContext($site, null, null, $event->event->occurredAt), [
            'kind' => 'ghost_worker', 'severity' => 'critical', 'owner_role' => 'site_manager',
            'title' => "{$who} marked at two sites on {$day}",
            'detail' => $crossSite ? 'Marked present at two sites in the same shift.' : 'Marked worked at another site on the same day.',
            'subject_ref' => "{$person}:{$day}",
            'facts' => ['person_id' => $person, 'day' => $day, 'shift_kind' => $p['shift_kind'] ?? null,
                'attendance_id' => $p['attendance_id'] ?? null, 'cross_site' => $crossSite, 'other_sites' => $otherSites],
        ])];
    }
}
