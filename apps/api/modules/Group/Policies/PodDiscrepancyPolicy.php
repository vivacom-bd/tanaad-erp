<?php

declare(strict_types=1);

namespace Tanaad\Group\Policies;

use Tanaad\Group\Commands\RaiseException;
use Tanaad\Shared\Ledger\CommandContext;
use Tanaad\Shared\Ledger\Policy;
use Tanaad\Shared\Ledger\StoredEvent;

/**
 * dispatch.delivered whose receiver counted a different number of bundles than were scanned at the
 * gate (payload discrepancy ≠ 0) → pod_discrepancy, critical, owned by the CEO as in the prototype.
 * One per dispatch (subject: dispatch id).
 */
final class PodDiscrepancyPolicy implements Policy
{
    public function handles(): array
    {
        return ['dispatch.delivered'];
    }

    public function react(StoredEvent $event): array
    {
        $p = $event->payload();
        $site = $event->siteId();
        $gap = is_int($p['discrepancy'] ?? null) ? $p['discrepancy'] : 0;
        if ($site === null || $gap === 0 || ! is_string($p['dispatch_id'] ?? null)) {
            return [];
        }
        $note = (string) ($p['delivery_note_no'] ?? $p['order_ref'] ?? '');

        return [new RaiseException(new CommandContext($site, null, null, $event->event->occurredAt), [
            'kind' => 'pod_discrepancy', 'severity' => 'critical', 'owner_role' => 'ceo',
            'title' => "Delivery {$note}: receiver counted {$p['bundles_received']} of {$p['bundles_expected']}",
            'detail' => 'Receiver '.((string) ($p['received_by'] ?? '')).'.',
            'subject_ref' => $p['dispatch_id'],
            'facts' => ['dispatch_id' => $p['dispatch_id'], 'delivery_note_no' => $p['delivery_note_no'] ?? null, 'order_ref' => $p['order_ref'] ?? null,
                'bundles_received' => $p['bundles_received'] ?? null, 'bundles_expected' => $p['bundles_expected'] ?? null, 'discrepancy' => $gap,
                'received_by' => $p['received_by'] ?? null,
                'alert' => ['title' => "Delivery short/over: {$note}", 'detail' => "Counted {$p['bundles_received']} of {$p['bundles_expected']}", 'audience' => ['ceo', 'site_manager']]],
        ])];
    }
}
