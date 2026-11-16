<?php

declare(strict_types=1);

namespace Tanaad\Group\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Tanaad\Group\Support\ExceptionRules;

/**
 * Read model of the `exceptions` table, written only by ExceptionProjector.
 *
 * @property string $id
 * @property string $site_id
 * @property string $kind
 * @property string $severity
 * @property string $title
 * @property string $detail
 * @property string|null $subject_ref
 * @property array<string, mixed>|null $facts
 * @property string $owner_role
 * @property string $status
 * @property string|null $raised_by
 * @property \Carbon\CarbonImmutable $raised_at
 * @property string $raised_event_id
 * @property string|null $decision
 * @property string|null $decided_by
 * @property \Carbon\CarbonImmutable|null $decided_at
 * @property string|null $decision_note
 * @property int $seq
 */
final class BusinessException extends Model
{
    protected $table = 'exceptions';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = ['facts' => 'array', 'raised_at' => 'immutable_datetime', 'decided_at' => 'immutable_datetime', 'seq' => 'int'];

    /** The open exception already raised for this subject, if any (idempotency per subject). */
    public static function openFor(string $siteId, string $kind, string $subjectRef): ?self
    {
        /** @var Builder<self> $q */
        $q = self::query();

        // Active means open or held: a held exception must still suppress a re-raise.
        return $q->where(['site_id' => $siteId, 'kind' => $kind, 'subject_ref' => $subjectRef])
            ->whereIn('status', ExceptionRules::ACTIVE_STATUSES)->first();
    }
}
