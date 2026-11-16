<?php

declare(strict_types=1);

namespace Tanaad\Group\Policies;

use Illuminate\Database\ConnectionInterface;
use Tanaad\Group\Support\ContractExpiry;
use Tanaad\Group\Support\ExceptionRules;
use Tanaad\Shared\Ledger\Policy;
use Tanaad\Shared\Ledger\StoredEvent;

/**
 * people.engagement_started / engagement_renewed whose end_date falls within the site's
 * contract_warning_days of the event's business day → contract_expiring (subject: engagement_id).
 */
final class ContractExpiringPolicy implements Policy
{
    public function __construct(private readonly ExceptionRules $rules, private readonly ConnectionInterface $db) {}

    public function handles(): array
    {
        return ['people.engagement_started', 'people.engagement_renewed'];
    }

    public function react(StoredEvent $event): array
    {
        $p = $event->payload();
        $site = $event->siteId();
        $end = $p['end_date'] ?? null;
        $engagement = $p['engagement_id'] ?? null;
        if ($site === null || ! is_string($end) || ! is_string($engagement)) {
            return [];
        }
        $today = $event->event->occurredAt->setTimezone($this->rules->timezone())->toDateString();
        $command = ContractExpiry::raise($site, $engagement, (string) ($p['person_id'] ?? ''), $end, $today,
            $this->rules->contractWarningDays($site), $this->db, $event->event->occurredAt);

        return $command === null ? [] : [$command];
    }
}
