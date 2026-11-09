<?php

declare(strict_types=1);

namespace Tanaad\Identity\Models;

use Carbon\CarbonImmutable;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Tanaad\Config\Models\Site;

/**
 * A shared floor tablet. Holds a long-lived device token; users unlock it with a PIN.
 *
 * @property string $id
 * @property string $site_id
 * @property string $name
 * @property string $kind
 * @property ?string $registered_by
 * @property ?CarbonImmutable $last_seen_at
 * @property bool $active
 * @property int $seq
 * @property Site $site
 */
final class Device extends Model implements AuthenticatableContract
{
    use Authenticatable;
    use HasApiTokens;
    use HasUuids;

    protected $guarded = [];

    protected $casts = ['active' => 'bool', 'seq' => 'int', 'last_seen_at' => 'immutable_datetime', 'deleted_at' => 'immutable_datetime'];

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
