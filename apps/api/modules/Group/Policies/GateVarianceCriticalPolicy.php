<?php

declare(strict_types=1);

namespace Tanaad\Group\Policies;

use Tanaad\Group\Commands\RaiseException;
use Tanaad\Shared\Ledger\CommandContext;
use Tanaad\Shared\Ledger\Policy;
use Tanaad\Shared\Ledger\StoredEvent;

/**
 * dispatch.gate_held (the server's gateDecision held the lorry) → gate_variance, critical, owned by the
 * site manager who decides (overrides); group roles see every critical exception. One per dispatch
 * (subject: dispatch id). The facts carry an `alert` for the CEO/site manager feed until Messaging
 * (Phase 4) sends it. dispatch.gate_overridden / dispatch.gate_passed resolve it in ExceptionProjector.
 */
final class GateVarianceCriticalPolicy implements Policy
{
    public function handles(): array
    {
        return ['dispatch.gate_held'];
    }

    public function react(StoredEvent $event): array
    {
        $p = $event->payload();
        $site = $event->siteId();
        if ($site === null || ! is_string($p['dispatch_id'] ?? null)) {
            return [];
        }
        /** @var list<string> $reasons */
        $reasons = is_array($p['reasons'] ?? null) ? array_values(array_map('strval', $p['reasons'])) : [];
        $order = (string) ($p['order_ref'] ?? '');
        $client = (string) ($p['client_name'] ?? '');
        $siteName = (string) ($p['site_name'] ?? '');

        return [new RaiseException(new CommandContext($site, null, null, $event->event->occurredAt), [
            'kind' => 'gate_variance', 'severity' => 'critical', 'owner_role' => 'site_manager',
            'title' => "Gate held: order {$order} to {$client}",
            'detail' => implode('. ', $reasons).". Expected {$p['expected_kg']} kg, scale read {$p['kg']} kg.",
            'subject_ref' => $p['dispatch_id'],
            'facts' => ['dispatch_id' => $p['dispatch_id'], 'order_ref' => $order, 'client_name' => $client,
                'expected_kg' => $p['expected_kg'] ?? null, 'kg' => $p['kg'] ?? null, 'variance_pct' => $p['variance_pct'] ?? null,
                'tolerance_pct' => $p['tolerance_pct'] ?? null, 'scanned_bundles' => $p['scanned_bundles'] ?? null,
                'ordered_bundles' => $p['ordered_bundles'] ?? null, 'reasons' => $reasons, 'held_event_id' => $event->id(),
                'alert' => ['title' => 'Gate locked'.($siteName !== '' ? " at {$siteName}" : ''), 'detail' => "{$order}: ".($reasons[0] ?? 'held'),
                    'audience' => ['ceo', 'site_manager']]],
        ])];
    }
}
