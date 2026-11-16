<?php

declare(strict_types=1);

namespace Tanaad\People\Projectors;

use Illuminate\Database\ConnectionInterface;
use Tanaad\Shared\Ledger\Projector;
use Tanaad\Shared\Ledger\StoredEvent;

/** The only writer of people, engagements and attendance. */
final class PeopleProjector implements Projector
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function handles(): array
    {
        return [
            'people.person_registered', 'people.engagement_started', 'people.engagement_renewed', 'people.engagement_ended',
            'people.attendance_marked', 'people.attendance_corrected', 'people.person_archived', 'people.person_reactivated',
        ];
    }

    public function apply(StoredEvent $event): void
    {
        $p = $event->payload();
        $touch = ['seq' => $event->seq, 'updated_at' => $event->receivedAt];
        $new = ['site_id' => $event->siteId(), 'deleted_at' => null, 'created_at' => $event->receivedAt, ...$touch];

        match ($event->type()) {
            'people.person_registered' => $this->db->table('people')->insert([
                'id' => $p['person_id'], 'number' => $p['number'], 'full_name' => $p['full_name'], 'id_number' => $p['id_number'],
                'phone_momo' => $p['phone_momo'], 'sector' => $p['sector'], 'shared' => $p['shared'],
                'shared_sites' => json_encode($p['shared_sites'], JSON_THROW_ON_ERROR), 'avatar_seed' => $p['avatar_seed'],
                'status' => 'active', 'joined_on' => $p['joined_on'], ...$new,
            ]),
            'people.engagement_started' => $this->engagement($p, $new),
            'people.engagement_renewed' => $this->renew($p, $new, $touch),
            'people.engagement_ended' => $this->db->table('engagements')->where('id', $p['engagement_id'])
                ->update(['status' => 'ended', 'end_date' => $p['end_date'], 'end_reason' => $p['reason'], ...$touch]),
            'people.attendance_marked' => $this->db->table('attendance')->insert([
                'id' => $p['attendance_id'], ...$this->mark($event), ...$new,
            ]),
            'people.attendance_corrected' => $this->db->table('attendance')->where('id', $p['attendance_id'])
                ->update([...$this->mark($event), ...$touch]),
            'people.person_archived' => $this->db->table('people')->where('id', $p['person_id'])
                ->update(['status' => 'archived', 'archived_on' => $p['day'], 'archive_reason' => $p['reason'], ...$touch]),
            'people.person_reactivated' => $this->db->table('people')->where('id', $p['person_id'])
                ->update(['status' => 'active', 'archived_on' => null, 'archive_reason' => null, ...$touch]),
            default => null,
        };
    }

    public function reset(): void
    {
        foreach (['attendance', 'engagements', 'people'] as $table) {
            $this->db->table($table)->delete();
        }
    }

    /**
     * @param  array<string, mixed>  $p
     * @param  array<string, mixed>  $new
     */
    private function engagement(array $p, array $new, ?string $renews = null): void
    {
        $this->db->table('engagements')->insert([
            'id' => $p['engagement_id'], 'person_id' => $p['person_id'], 'type' => $p['type'], 'start_date' => $p['start_date'],
            'end_date' => $p['end_date'], 'term_days' => $p['term_days'], 'rate_basis' => $p['rate_basis'], 'rate' => $p['rate'],
            'currency' => $p['currency'], 'settlement_cycle' => $p['settlement_cycle'], 'status' => 'active',
            'renews_engagement_id' => $renews, ...$new,
        ]);
    }

    /**
     * @param  array<string, mixed>  $p
     * @param  array<string, mixed>  $new
     * @param  array<string, mixed>  $touch
     */
    private function renew(array $p, array $new, array $touch): void
    {
        $this->db->table('engagements')->where('id', $p['renews_engagement_id'])->update(['status' => 'renewed', ...$touch]);
        $this->engagement($p, $new, (string) $p['renews_engagement_id']);
    }

    /** @return array<string, mixed> */
    private function mark(StoredEvent $event): array
    {
        $p = $event->payload();

        return [
            'person_id' => $p['person_id'], 'day' => $p['day'], 'shift_kind' => $p['shift_kind'], 'shift_id' => $p['shift_id'],
            'status' => $p['status'], 'overtime_hours' => $p['overtime_hours'], 'cross_site' => $p['cross_site'],
            'marked_by' => $event->event->actorId, 'marked_at' => $event->event->occurredAt, 'event_id' => $event->id(),
        ];
    }
}
