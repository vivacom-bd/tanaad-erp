<?php

declare(strict_types=1);

namespace Tanaad\Group\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Read model of the `briefs` table, written only by BriefProjector.
 *
 * @property string $id
 * @property string $day
 * @property string $channel
 * @property list<string> $recipient_ids
 * @property list<string> $lines
 * @property string|null $sent_by
 * @property \Carbon\CarbonImmutable $sent_at
 * @property int $seq
 */
final class Brief extends Model
{
    protected $table = 'briefs';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = ['recipient_ids' => 'array', 'lines' => 'array', 'sent_at' => 'immutable_datetime', 'seq' => 'int'];

    /** @return array<string, mixed> */
    public function shape(): array
    {
        return [
            'id' => $this->id, 'day' => substr((string) $this->getRawOriginal('day'), 0, 10), 'channel' => $this->channel,
            'recipientIds' => $this->recipient_ids, 'lines' => $this->lines, 'sentBy' => $this->sent_by,
            'sentAt' => $this->sent_at->toIso8601ZuluString('microsecond'),
        ];
    }
}
