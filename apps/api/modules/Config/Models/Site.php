<?php

declare(strict_types=1);

namespace Tanaad\Config\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Tanaad\Shared\Calendar\Calendar;

/**
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string $legal_name
 * @property string $short_name
 * @property string $tagline
 * @property string $currency_primary
 * @property string $timezone
 * @property list<array{kind: string, starts: string, ends: string}> $shifts
 * @property list<int> $closed_days
 * @property string $machine_wall_title
 * @property ?string $to_be_confirmed
 * @property int $seq
 */
final class Site extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = ['shifts' => 'array', 'closed_days' => 'array', 'seq' => 'int', 'deleted_at' => 'immutable_datetime'];

    public function newUniqueId(): string
    {
        return (string) \Illuminate\Support\Str::uuid7();
    }

    public function calendar(): Calendar
    {
        return Calendar::forSite($this->code, $this->shifts, $this->timezone);
    }
}
