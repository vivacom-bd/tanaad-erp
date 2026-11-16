<?php

declare(strict_types=1);

namespace Tanaad\Group\Policies;

use Tanaad\Group\Commands\RaiseException;
use Tanaad\Group\Support\NotLogged;
use Tanaad\Shared\Ledger\CommandContext;
use Tanaad\Shared\Ledger\Policy;
use Tanaad\Shared\Ledger\StoredEvent;

/**
 * production.entry_recorded flagged below_benchmark (good units under the site's benchmark_pct of the
 * machine+product benchmark, computed by Production at record time) with no downtime reason
 * → below_benchmark for the supervisor (subject: machine id and shift, one per machine and shift). A later re-log with a reason or
 * enough units resolves it: ExceptionProjector listens to the same event.
 */
final class BelowBenchmarkPolicy implements Policy
{
    public function handles(): array
    {
        return ['production.entry_recorded'];
    }

    public function react(StoredEvent $event): array
    {
        $p = $event->payload();
        $site = $event->siteId();
        if ($site === null || ! self::isBelow($p)) {
            return [];
        }
        $bench = (float) $p['benchmark'];
        $good = (int) ($p['good_units'] ?? 0);
        $target = is_numeric($p['expected_units'] ?? null) ? (float) $p['expected_units'] : $bench;

        return [new RaiseException(new CommandContext($site, null, null, $event->event->occurredAt), [
            'kind' => 'below_benchmark', 'severity' => 'warning', 'owner_role' => 'supervisor',
            'title' => "Machine below benchmark: {$good} of ".rtrim(rtrim(number_format($target, 3, '.', ''), '0'), '.').' units',
            'detail' => 'Logged output for shift '.((string) ($p['shift_id'] ?? '')).' is under '.((string) ($p['benchmark_pct'] ?? '')).'% of the benchmark with no downtime reason.',
            'subject_ref' => NotLogged::subject((string) $p['machine_id'], (string) ($p['shift_id'] ?? '')),
            'facts' => ['shift_id' => $p['shift_id'] ?? null, 'day' => $p['day'] ?? null, 'entry_id' => $p['entry_id'] ?? null, 'product_id' => $p['product_id'] ?? null,
                'good_units' => $good, 'benchmark' => $bench, 'benchmark_pct' => $p['benchmark_pct'] ?? null,
                'progress' => $p['progress'] ?? null, 'expected_units' => $p['expected_units'] ?? null],
        ])];
    }

    /** @param array<string, mixed> $p */
    public static function isBelow(array $p): bool
    {
        $reason = $p['downtime_reason'] ?? null;

        return ($p['below_benchmark'] ?? false) === true && (! is_string($reason) || trim($reason) === '') && is_numeric($p['benchmark'] ?? null);
    }
}
