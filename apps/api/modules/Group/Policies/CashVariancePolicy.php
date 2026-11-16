<?php

declare(strict_types=1);

namespace Tanaad\Group\Policies;

use Tanaad\Group\Commands\RaiseException;
use Tanaad\Group\Support\ExceptionRules;
use Tanaad\Shared\Ledger\CommandContext;
use Tanaad\Shared\Ledger\Policy;
use Tanaad\Shared\Ledger\StoredEvent;

/** cash.day_closed with |variance| above the site's cash_variance tolerance → cash_variance (subject: cash_day_id). */
final class CashVariancePolicy implements Policy
{
    public function __construct(private readonly ExceptionRules $rules) {}

    public function handles(): array
    {
        return ['cash.day_closed'];
    }

    public function react(StoredEvent $event): array
    {
        $p = $event->payload();
        $site = $event->siteId();
        $variance = is_numeric($p['variance'] ?? null) ? (float) $p['variance'] : 0.0;
        $tolerance = $this->rules->cashTolerance($site);
        if ($site === null || abs($variance) <= $tolerance) {
            return [];
        }
        $currency = is_string($p['currency'] ?? null) ? $p['currency'] : 'UGX';
        $amount = number_format(abs($variance), abs($variance - round($variance)) > 0 ? 2 : 0);
        $day = (string) ($p['day'] ?? '');

        return [new RaiseException(new CommandContext($site, null, null, $event->event->occurredAt), [
            'kind' => 'cash_variance', 'severity' => 'warning', 'owner_role' => 'site_manager',
            'title' => 'Cash close '.($variance < 0 ? 'short' : 'over')." by {$currency} {$amount}",
            'detail' => "Counted closing on {$day} differs from expected beyond the site tolerance of {$currency} ".number_format($tolerance).'.',
            'subject_ref' => (string) ($p['cash_day_id'] ?? $day),
            'facts' => ['day' => $day, 'expected_closing' => $p['expected_closing'] ?? null, 'counted' => $p['counted'] ?? null,
                'variance' => $p['variance'] ?? null, 'tolerance' => $tolerance, 'currency' => $currency],
        ])];
    }
}
