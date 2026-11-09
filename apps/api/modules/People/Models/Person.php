<?php

declare(strict_types=1);

namespace Tanaad\People\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Read model, written only by PeopleProjector.
 *
 * @property string $id
 * @property string $site_id
 * @property ?string $number
 * @property string $full_name
 * @property ?string $id_number
 * @property ?string $phone_momo
 * @property ?string $sector
 * @property bool $shared
 * @property list<string>|null $shared_sites
 * @property ?string $avatar_seed
 * @property string $status
 * @property string $joined_on
 * @property ?string $archived_on
 * @property ?string $archive_reason
 * @property int $seq
 */
final class Person extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'people';

    protected $guarded = [];

    protected $casts = ['shared' => 'bool', 'shared_sites' => 'array', 'seq' => 'int', 'deleted_at' => 'immutable_datetime'];
}
