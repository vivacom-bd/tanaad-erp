<?php

declare(strict_types=1);

namespace Tanaad\Group\Projectors;

use Illuminate\Database\ConnectionInterface;
use Tanaad\Group\Policies\BelowBenchmarkPolicy;
use Tanaad\Group\Support\NotLogged;
use Tanaad\Shared\Ledger\Projector;
use Tanaad\Shared\Ledger\StoredEvent;

/**
 * The only writer of `exceptions`. Also listens to people.engagement_ended and
 * people.engagement_renewed (by type string only): an ended or renewed engagement resolves its
 * open contract_expiring exception. production.entry_recorded resolves the machine+shift not_logged and below_benchmark.
 */
final class ExceptionProjector implements Projector
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function handles(): array
    {
        return ['group.exception_raised', 'group.exception_decided', 'people.engagement_ended', 'people.engagement_renewed', 'production.entry_recorded', 'spares.part_received', 'spares.part_saved', 'dispatch.gate_overridden', 'dispatch.gate_passed', 'orders.approved', 'orders.held'];
    }

    public function apply(StoredEvent $event): void
    {
        $site = $event->siteId();
        if ($site === null) {
            return;
        }
        $p = $event->payload();

        match ($event->type()) {
            'group.exception_raised' => $this->raise($site, $p, $event),
            'group.exception_decided' => $this->db->table('exceptions')->where('id', $p['exception_id'])->update([
                'status' => $p['status'], 'decision' => $p['decision'], 'decided_by' => $event->event->actorId,
                'decided_at' => $event->event->occurredAt, 'decision_note' => $p['note'] ?? null,
                'seq' => $event->seq, 'updated_at' => $event->receivedAt,
            ]),
            'people.engagement_ended' => $this->db->table('exceptions')
                ->where(['site_id' => $site, 'kind' => 'contract_expiring', 'subject_ref' => $p['engagement_id'] ?? ''])
                ->whereIn('status', ['open', 'held'])
                ->update(['status' => 'resolved', 'decided_at' => $event->event->occurredAt, 'decision_note' => 'Engagement ended',
                    'seq' => $event->seq, 'updated_at' => $event->receivedAt]),
            'people.engagement_renewed' => isset($p['renews_engagement_id']) ? $this->db->table('exceptions')
                ->where(['site_id' => $site, 'kind' => 'contract_expiring', 'subject_ref' => $p['renews_engagement_id']])
                ->whereIn('status', ['open', 'held'])
                ->update(['status' => 'resolved', 'decided_at' => $event->event->occurredAt, 'decision_note' => 'Contract renewed',
                    'seq' => $event->seq, 'updated_at' => $event->receivedAt]) : null,
            'production.entry_recorded' => $this->entryRecorded($site, $p, $event),
            // A held gate's gate_variance is approved by the override (decided by the overrider, with the reason)
            // or resolved by a later in-tolerance re-weigh. Both read only the event, so Replay rebuilds them.
            'dispatch.gate_overridden' => $this->db->table('exceptions')
                ->where(['site_id' => $site, 'kind' => 'gate_variance', 'subject_ref' => $p['dispatch_id'] ?? ''])
                ->whereIn('status', ['open', 'held'])
                ->update(['status' => 'approved', 'decision' => 'approve', 'decided_by' => $event->event->actorId, 'decided_at' => $event->event->occurredAt,
                    'decision_note' => 'Gate overridden: '.((string) ($p['reason'] ?? '')), 'seq' => $event->seq, 'updated_at' => $event->receivedAt]),
            'dispatch.gate_passed' => $this->db->table('exceptions')
                ->where(['site_id' => $site, 'kind' => 'gate_variance', 'subject_ref' => $p['dispatch_id'] ?? ''])
                ->whereIn('status', ['open', 'held'])
                ->update(['status' => 'resolved', 'decided_at' => $event->event->occurredAt, 'decision_note' => 'Gate re-weighed within tolerance',
                    'seq' => $event->seq, 'updated_at' => $event->receivedAt]),
            // The credit desk's decision on an over-limit order settles its credit_over_limit (from the event only: replay-safe).
            'orders.approved', 'orders.held' => $this->db->table('exceptions')
                ->where(['site_id' => $site, 'kind' => 'credit_over_limit', 'subject_ref' => $p['order_id'] ?? ''])
                ->whereIn('status', ['open', 'held'])
                ->update(['status' => $event->type() === 'orders.approved' ? 'approved' : 'held', 'decision' => $event->type() === 'orders.approved' ? 'approve' : 'hold',
                    'decided_by' => $event->event->actorId, 'decided_at' => $event->event->occurredAt, 'decision_note' => $p['reason'] ?? null,
                    'seq' => $event->seq, 'updated_at' => $event->receivedAt]),
            'spares.part_received' => $this->stockLevel($site, $p['part_id'] ?? null, $p['on_hand_after'] ?? null, $p['min_level'] ?? null, $event),
            'spares.part_saved' => $this->stockLevel($site, $p['part_id'] ?? null, $p['on_hand'] ?? null, $p['min_level'] ?? null, $event),
            default => null,
        };
    }

    public function reset(): void
    {
        $this->db->table('exceptions')->delete();
    }

    /**
     * A production entry resolves the machine's not_logged for that shift, and its below_benchmark
     * for that shift unless the entry is itself below benchmark with no downtime reason.
     *
     * @param  array<string, mixed>  $p
     */
    private function entryRecorded(string $site, array $p, StoredEvent $event): void
    {
        $machine = (string) ($p['machine_id'] ?? '');
        $shift = (string) ($p['shift_id'] ?? '');
        $done = ['status' => 'resolved', 'decided_at' => $event->event->occurredAt, 'decision_note' => 'Entry recorded',
            'seq' => $event->seq, 'updated_at' => $event->receivedAt];
        $this->db->table('exceptions')->where(['site_id' => $site, 'kind' => 'not_logged', 'subject_ref' => NotLogged::subject($machine, $shift)])
            ->whereIn('status', ['open', 'held'])->update($done);
        if (! BelowBenchmarkPolicy::isBelow($p)) {
            $this->db->table('exceptions')->where(['site_id' => $site, 'kind' => 'below_benchmark', 'subject_ref' => NotLogged::subject($machine, $shift)])
                ->whereIn('status', ['open', 'held'])->update($done);
        }
    }

    /** Stock back at (or above) its minimum settles the part's low_stock exception. */
    private function stockLevel(string $site, mixed $partId, mixed $onHand, mixed $min, StoredEvent $event): void
    {
        if (! is_string($partId) || ! is_numeric($onHand) || ! is_numeric($min) || (float) $onHand < (float) $min) {
            return;
        }
        $this->db->table('exceptions')->where(['site_id' => $site, 'kind' => 'low_stock', 'subject_ref' => $partId])
            ->whereIn('status', ['open', 'held'])
            ->update(['status' => 'resolved', 'decided_at' => $event->event->occurredAt, 'decision_note' => 'Stock back at the minimum',
                'seq' => $event->seq, 'updated_at' => $event->receivedAt]);
    }

    /** @param array<string, mixed> $p */
    private function raise(string $site, array $p, StoredEvent $event): void
    {
        $this->db->table('exceptions')->insert([
            'id' => $p['exception_id'], 'site_id' => $site, 'kind' => $p['kind'], 'severity' => $p['severity'],
            'title' => $p['title'], 'detail' => $p['detail'], 'subject_ref' => $p['subject_ref'] ?? null,
            'facts' => json_encode((object) ($p['facts'] ?? []), JSON_THROW_ON_ERROR), 'owner_role' => $p['owner_role'],
            'status' => 'open', 'raised_by' => $event->event->actorId, 'raised_at' => $event->event->occurredAt,
            'raised_event_id' => $event->id(), 'seq' => $event->seq, 'created_at' => $event->receivedAt, 'updated_at' => $event->receivedAt,
        ]);
    }
}
