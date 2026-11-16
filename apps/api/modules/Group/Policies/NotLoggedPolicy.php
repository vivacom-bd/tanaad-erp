<?php

declare(strict_types=1);

namespace Tanaad\Group\Policies;

use Tanaad\Group\Support\NotLogged;
use Tanaad\Shared\Ledger\Policy;
use Tanaad\Shared\Ledger\StoredEvent;

/** production.shift_closed → not_logged for each active wall machine with no entry (Production lists them in unlogged_machine_ids). */
final class NotLoggedPolicy implements Policy
{
    public function handles(): array
    {
        return ['production.shift_closed'];
    }

    public function react(StoredEvent $event): array
    {
        $p = $event->payload();
        $site = $event->siteId();
        $ids = $p['unlogged_machine_ids'] ?? [];
        if ($site === null || ! is_array($ids)) {
            return [];
        }
        $out = [];
        foreach ($ids as $machineId) {
            if (is_string($machineId)) {
                $out[] = NotLogged::raise($site, $machineId, (string) ($p['shift_id'] ?? ''), (string) ($p['day'] ?? ''), $event->event->occurredAt, 'closed');
            }
        }

        return $out;
    }
}
