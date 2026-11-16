<?php

declare(strict_types=1);

namespace Tanaad\Group\Policies;

use Tanaad\Group\Commands\RaiseException;
use Tanaad\Shared\Ledger\CommandContext;
use Tanaad\Shared\Ledger\Policy;
use Tanaad\Shared\Ledger\StoredEvent;

/**
 * orders.created flagged over_limit (the client's open balance plus the order exceeds its credit limit,
 * computed by Orders at create time) → credit_over_limit for the CEO (subject: the order id, one per order).
 * ApproveOrder / HoldOrder settle it: ExceptionProjector listens to orders.approved and orders.held.
 */
final class CreditLimitPolicy implements Policy
{
    public function handles(): array
    {
        return ['orders.created'];
    }

    public function react(StoredEvent $event): array
    {
        $p = $event->payload();
        $site = $event->siteId();
        if ($site === null || ($p['over_limit'] ?? false) !== true || ! is_numeric($p['credit_limit'] ?? null)) {
            return [];
        }
        $limit = (float) $p['credit_limit'];
        $after = (float) ($p['client_balance'] ?? 0) + (float) ($p['total'] ?? 0);
        $currency = (string) ($p['currency'] ?? '');
        $pct = $limit > 0 ? (int) round($after / $limit * 100) : null;
        $money = fn (float $n): string => $currency.' '.number_format($n, $currency === 'UGX' ? 0 : 2);

        return [new RaiseException(new CommandContext($site, null, null, $event->event->occurredAt), [
            'kind' => 'credit_over_limit', 'severity' => 'critical', 'owner_role' => 'ceo',
            'title' => ((string) ($p['client_name'] ?? 'Client')).' order exceeds credit limit',
            'detail' => 'Order '.((string) ($p['number'] ?? '')).' would take the client to '
                .($pct === null ? $money($after) : "{$pct}%").' of the '.$money($limit).' limit.',
            'subject_ref' => (string) $p['order_id'],
            'facts' => ['order_id' => $p['order_id'], 'number' => $p['number'] ?? null, 'client_id' => $p['client_id'] ?? null,
                'client_balance' => $p['client_balance'] ?? null, 'order_total' => $p['total'] ?? null, 'credit_limit' => $limit, 'currency' => $currency],
        ])];
    }
}
