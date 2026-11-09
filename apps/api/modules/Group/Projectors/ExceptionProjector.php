<?php

declare(strict_types=1);

namespace Tanaad\Group\Projectors;

use Illuminate\Database\ConnectionInterface;
use Tanaad\Shared\Ledger\Projector;
use Tanaad\Shared\Ledger\StoredEvent;

/**
 * The only writer of `exceptions`. Also listens to people.engagement_ended and
 * people.engagement_renewed (by type string only): an ended or renewed engagement resolves its
 * open contract_expiring exception.
 */
final class ExceptionProjector implements Projector
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function handles(): array
    {
        return ['group.exception_raised', 'group.exception_decided', 'people.engagement_ended', 'people.engagement_renewed'];
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
            default => null,
        };
    }

    public function reset(): void
    {
        $this->db->table('exceptions')->delete();
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
