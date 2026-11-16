<?php

declare(strict_types=1);

namespace Tanaad\Group\Policies;

use Tanaad\Group\Commands\RaiseException;
use Tanaad\Shared\Ledger\CommandContext;
use Tanaad\Shared\Ledger\Policy;
use Tanaad\Shared\Ledger\StoredEvent;

/**
 * spares.part_issued leaving the part below its minimum level (both carried in the event) → low_stock for the
 * storekeeper (subject: the part id, so one open exception per part). ExceptionProjector settles it when a
 * receipt, or a lowered minimum, brings the stock back to the minimum.
 */
final class LowStockPolicy implements Policy
{
    public function handles(): array
    {
        return ['spares.part_issued'];
    }

    public function react(StoredEvent $event): array
    {
        $p = $event->payload();
        $site = $event->siteId();
        if ($site === null || ! is_numeric($p['on_hand_after'] ?? null) || ! is_numeric($p['min_level'] ?? null)) {
            return [];
        }
        $after = (float) $p['on_hand_after'];
        $min = (float) $p['min_level'];
        if ($after >= $min) {
            return [];
        }
        $fmt = fn (float $n): string => rtrim(rtrim(number_format($n, 3, '.', ''), '0'), '.');
        $name = (string) ($p['part_name'] ?? 'Part');

        return [new RaiseException(new CommandContext($site, null, null, $event->event->occurredAt), [
            'kind' => 'low_stock', 'severity' => 'warning', 'owner_role' => 'storekeeper',
            'title' => "{$name} below minimum ({$fmt($after)} of {$fmt($min)})",
            'detail' => 'Reorder before stock reaches zero.',
            'subject_ref' => (string) $p['part_id'],
            'facts' => ['part_id' => $p['part_id'], 'part_code' => $p['part_code'] ?? null, 'on_hand' => $after, 'min_level' => $min, 'unit' => $p['unit'] ?? null],
        ])];
    }
}
