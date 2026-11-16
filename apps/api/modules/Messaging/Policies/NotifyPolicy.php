<?php

declare(strict_types=1);

namespace Tanaad\Messaging\Policies;

use Illuminate\Database\ConnectionInterface;
use Tanaad\Messaging\Commands\QueueMessage;
use Tanaad\Messaging\Support\MessageBuilder;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Policy;
use Tanaad\Shared\Ledger\StoredEvent;

/**
 * Business facts that people must hear about, by event type string (no module imports):
 * a sent brief (to its recipients), a critical exception, a held gate, an approved or held order.
 * Each recipient gets one messaging.queued event, so the history lives in the ledger.
 */
final class NotifyPolicy implements Policy
{
    public function __construct(private readonly MessageBuilder $builder, private readonly ConnectionInterface $db) {}

    public function handles(): array
    {
        return ['group.brief_sent', 'group.exception_raised', 'dispatch.gate_held', 'orders.approved', 'orders.held'];
    }

    /** @return list<Command> */
    public function react(StoredEvent $event): array
    {
        $p = $event->payload();

        return match ($event->type()) {
            'group.brief_sent' => $this->brief($event, $p),
            'group.exception_raised' => $this->exception($event, $p),
            'dispatch.gate_held' => $this->gate($event, $p),
            default => $this->order($event, $p),
        };
    }

    /**
     * @param  array<string, mixed>  $p
     * @return list<QueueMessage>
     */
    private function brief(StoredEvent $event, array $p): array
    {
        if (! in_array($p['channel'] ?? null, ['whatsapp', 'log'], true)) {
            return [];   // "copy" means the CEO sends it by hand
        }
        $ids = array_values(array_filter((array) ($p['recipient_ids'] ?? []), 'is_string'));
        $lines = array_map(fn ($l): string => trim((string) $l), array_values(array_filter((array) ($p['lines'] ?? []), fn ($l): bool => trim((string) $l) !== '')));
        $day = (string) ($p['day'] ?? '');

        return $this->builder->build($event, "Morning brief {$day}", implode("\n", $lines), $ids === [] ? ['ceo'] : [], $ids);
    }

    /**
     * @param  array<string, mixed>  $p
     * @return list<QueueMessage>
     */
    private function exception(StoredEvent $event, array $p): array
    {
        // A held gate is announced from dispatch.gate_held itself; do not tell people twice.
        if (($p['severity'] ?? null) !== 'critical' || ($p['kind'] ?? null) === 'gate_variance') {
            return [];
        }
        $site = $this->siteName($event->siteId());

        return $this->builder->build($event, 'Critical: '.(string) ($p['title'] ?? 'exception').($site !== '' ? " ({$site})" : ''), (string) ($p['detail'] ?? ''), ['ceo', 'site_manager']);
    }

    /**
     * @param  array<string, mixed>  $p
     * @return list<QueueMessage>
     */
    private function gate(StoredEvent $event, array $p): array
    {
        $site = (string) ($p['site_name'] ?? $this->siteName($event->siteId()));
        $reasons = is_array($p['reasons'] ?? null) ? implode('. ', array_map('strval', $p['reasons'])) : '';

        return $this->builder->build($event, 'Gate locked'.($site !== '' ? " at {$site}" : '').': order '.(string) ($p['order_ref'] ?? ''), $reasons, ['ceo']);
    }

    /**
     * Contract with Orders (OrderDecision): {order_id, number, client_name, status, reason?, over_limit}.
     * status is awaiting_cash (local, cash still to come), approved, or held.
     *
     * @param  array<string, mixed>  $p
     * @return list<QueueMessage>
     */
    private function order(StoredEvent $event, array $p): array
    {
        $ref = (string) ($p['number'] ?? $p['order_id'] ?? '');
        $client = (string) ($p['client_name'] ?? '');
        $held = $event->type() === 'orders.held';
        $verb = $held ? 'held' : (($p['status'] ?? null) === 'awaiting_cash' ? 'approved, awaiting cash' : 'approved');
        $body = array_values(array_filter([
            ($p['over_limit'] ?? false) === true ? 'Over the client\'s credit limit.' : '',
            is_string($p['reason'] ?? null) ? ($held ? 'Reason: ' : 'Note: ').$p['reason'] : '',
        ], fn (string $l): bool => $l !== ''));

        return $this->builder->build($event, "Order {$ref} {$verb}".($client !== '' ? " for {$client}" : ''), implode("\n", $body), ['ceo', 'site_manager']);
    }

    private function siteName(?string $siteId): string
    {
        return $siteId === null ? '' : (string) $this->db->table('sites')->where('id', $siteId)->value('name');
    }
}
