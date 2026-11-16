<?php

declare(strict_types=1);

namespace Tanaad\People\Handlers;

use Tanaad\Config\Models\Site;
use Tanaad\Config\Support\Payload;
use Tanaad\People\Commands\MarkAttendance;
use Tanaad\People\Models\Attendance;
use Tanaad\People\Models\Engagement;
use Tanaad\People\Models\Person;
use Tanaad\People\Support\Sites;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\DayLocks;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;
use Tanaad\Shared\Ledger\Rejection;

/**
 * One mark per person, site, business day and shift (§7.3). A second mark is
 * duplicate_fact unless the command is a correction with a reason, which becomes
 * people.attendance_corrected pointing at the event that set the current mark.
 *
 * Cross-site: a person may be marked only where they are engaged (home site, an active
 * engagement there, or listed in shared_sites). Being present at two sites in one shift is
 * accepted and flagged cross_site for the Group policy to raise for review.
 */
final class MarkAttendanceHandler implements Handler
{
    public const array STATUSES = ['present', 'half', 'absent'];

    public const array WORKED = ['present', 'half'];

    public function __construct(private readonly DayLocks $dayLocks) {}

    public function handle(Command $command): array
    {
        if (! $command instanceof MarkAttendance) {
            throw new \LogicException('Wrong command for '.self::class);
        }
        $site = Sites::of($command);
        $calendar = $site->calendar();
        $at = $command->context()->occurredAt;
        $p = new Payload($command->payload);
        $personId = $p->uuid('person_id');
        $status = $p->oneOf('status', self::STATUSES);
        $overtime = $p->optNum('overtime_hours') ?? 0.0;
        $day = $p->optDate('day') ?? $calendar->businessDay($at);
        $shift = $p->optStr('shift_kind', 16) ?? $calendar->shiftKindAt($at);
        $kinds = array_column($site->shifts, 'kind');
        if ($shift === null || ! in_array($shift, $kinds, true)) {
            $p->error('shift_kind', 'must be one of: '.implode(', ', $kinds));
        }
        $reason = $command->reason === null ? null : trim($command->reason);
        if ($command->correction && ($reason === null || $reason === '')) {
            $p->error('reason', 'required for a correction');
        }
        $p->check();
        /** @var string $shift */
        $this->dayLocks->assertOpen($site->id, 'shift', $day);

        $person = Sites::person($personId);
        if ($person->status !== 'active') {
            throw Rejection::stateConflict("{$person->full_name} is archived; reactivate them before marking attendance.");
        }
        if (! Sites::engagedAt($person, $site)) {
            throw Rejection::stateConflict("{$person->full_name} is not engaged at this site.");
        }

        $payload = [
            'person_id' => $personId,
            'day' => $day,
            'shift_kind' => $shift,
            'shift_id' => $calendar->shiftId($day, $shift),
            'status' => $status,
            'overtime_hours' => $overtime,
            'cross_site' => in_array($status, self::WORKED, true) && Attendance::query()
                ->where(['person_id' => $personId, 'day' => $day, 'shift_kind' => $shift])
                ->where('site_id', '!=', $site->id)->whereIn('status', self::WORKED)->exists(),
        ];
        $existing = Attendance::query()->where(['person_id' => $personId, 'site_id' => $site->id, 'day' => $day, 'shift_kind' => $shift])->first();

        if ($existing === null) {
            if ($command->correction) {
                throw Rejection::notFound('Attendance', "{$personId} {$day} {$shift}");
            }

            return [Event::from($command->context(), 'people.attendance_marked', ['attendance_id' => $command->context()->eventIdOrNew(), ...$payload])];
        }
        if (! $command->correction) {
            throw Rejection::duplicateFact("{$person->full_name} is already marked {$existing->status} for the {$shift} shift on {$day}.");
        }
        if ($command->correctsEventId !== null && $command->correctsEventId !== $existing->event_id) {
            throw Rejection::stateConflict('The mark was changed since; correct the latest one.');
        }

        return [Event::from($command->context(), 'people.attendance_corrected', ['attendance_id' => $existing->id, ...$payload], correctsEventId: $existing->event_id, reason: $reason)];
    }
}
