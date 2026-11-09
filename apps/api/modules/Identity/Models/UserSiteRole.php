<?php

declare(strict_types=1);

namespace Tanaad\Identity\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Tanaad\Config\Models\Site;

/**
 * @property string $id
 * @property string $user_id
 * @property ?string $site_id
 * @property string $role
 * @property int $seq
 * @property ?Site $site
 */
final class UserSiteRole extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = ['seq' => 'int', 'deleted_at' => 'immutable_datetime'];

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
