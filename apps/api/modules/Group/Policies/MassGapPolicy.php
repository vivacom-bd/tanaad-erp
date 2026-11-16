<?php

declare(strict_types=1);

namespace Tanaad\Group\Policies;

use Tanaad\Group\Commands\RaiseException;
use Tanaad\Shared\Ledger\CommandContext;
use Tanaad\Shared\Ledger\Policy;
use Tanaad\Shared\Ledger\StoredEvent;

/**
 * production.shift_closed / production.waste_weighed whose mass_balance (computed by Production with
 * Arithmetic::massBalance) is "missing" → mass_gap for the site manager, one per site and day
 * (subject: mass:{day}). Critical when the gap is more than twice the allowed loss, else warning.
 */
final class MassGapPolicy implements Policy
{
    public function handles(): array
    {
        return ['production.shift_closed', 'production.waste_weighed'];
    }

    public function react(StoredEvent $event): array
    {
        $p = $event->payload();
        $site = $event->siteId();
        $mb = $p['mass_balance'] ?? null;
        if ($site === null || ! is_array($mb) || ($mb['state'] ?? null) !== 'missing') {
            return [];
        }
        $day = (string) ($p['day'] ?? '');
        $gapPct = (float) ($mb['gapPct'] ?? 0);
        $allowed = (float) ($mb['allowedLossPct'] ?? 0);

        return [new RaiseException(new CommandContext($site, null, null, $event->event->occurredAt), [
            'kind' => 'mass_gap', 'severity' => $gapPct > 2 * $allowed ? 'critical' : 'warning', 'owner_role' => 'site_manager',
            'title' => 'Mass balance gap of '.((string) ($mb['gapKg'] ?? 0))." kg on {$day}",
            'detail' => "Material in is not accounted for by output, waste and work in progress: {$gapPct}% against an allowed {$allowed}%.",
            'subject_ref' => "mass:{$day}",
            'facts' => ['day' => $day, ...$mb],
        ])];
    }
}
