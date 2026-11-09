<?php

declare(strict_types=1);

namespace Tanaad\Identity\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property string $id
 * @property ?string $code
 * @property string $name
 * @property ?string $email
 * @property ?string $password
 * @property ?string $pin_hash
 * @property int $pin_failed_count
 * @property ?CarbonImmutable $pin_locked_until
 * @property ?string $person_id
 * @property string $avatar_seed
 * @property bool $active
 */
final class User extends Authenticatable
{
    use HasApiTokens;
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['password', 'pin_hash', 'remember_token'];

    protected $casts = ['active' => 'bool', 'pin_failed_count' => 'int', 'pin_locked_until' => 'immutable_datetime', 'password' => 'hashed'];

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /** @return HasMany<UserSiteRole, $this> */
    public function siteRoles(): HasMany
    {
        return $this->hasMany(UserSiteRole::class)->whereNull('deleted_at');
    }

    /** @return array<string, mixed> */
    public function toProfile(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'avatar_seed' => $this->avatar_seed,
            'person_id' => $this->person_id,
            'roles' => $this->siteRoles->map(fn (UserSiteRole $r): array => ['role' => $r->role, 'site_id' => $r->site_id, 'site_code' => $r->site?->code])->values()->all(),
        ];
    }
}
