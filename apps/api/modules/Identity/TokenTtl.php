<?php

declare(strict_types=1);

namespace Tanaad\Identity;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use Laravel\Sanctum\PersonalAccessToken;
use Tanaad\Identity\Models\Device;
use Tanaad\Identity\Models\User;
use Tanaad\Identity\Models\UserSiteRole;

/**
 * Token lifetime by role, from config tanaad.auth.token_ttl_minutes. A user with several
 * roles gets the longest; a device token uses the `device` entry. 0 means no expiry.
 */
final class TokenTtl
{
    public function __construct(private readonly Config $config) {}

    public function minutesFor(User $user): int
    {
        $user->loadMissing('siteRoles');
        $roles = $user->siteRoles->map(fn (UserSiteRole $r): string => $r->role)->unique()->values()->all();
        if ($roles === []) {
            return $this->entry('default');
        }
        $minutes = array_map(fn (string $role): int => $this->entry($role), $roles);

        // 0 = never expires, so it outranks every finite value.
        return in_array(0, $minutes, true) ? 0 : max($minutes);
    }

    public function minutesForToken(PersonalAccessToken $token): int
    {
        $owner = $token->tokenable;
        if ($owner instanceof Device) {
            return $this->entry('device');
        }

        return $owner instanceof User ? $this->minutesFor($owner) : $this->entry('default');
    }

    /** Expiry for a token issued now; null when it never expires. */
    public function expiryFor(User $user): ?CarbonImmutable
    {
        $minutes = $this->minutesFor($user);

        return $minutes > 0 ? CarbonImmutable::now()->addMinutes($minutes) : null;
    }

    /** A PIN (shared-tablet, device-bound) session: the role lifetime capped at tanaad.pin.session_hours. */
    public function expiryForPin(User $user): ?CarbonImmutable
    {
        $role = $this->minutesFor($user);
        $cap = max(0, (int) $this->config->get('tanaad.pin.session_hours', 14)) * 60;
        $minutes = match (true) {
            $role === 0 => $cap,
            $cap === 0 => $role,
            default => min($role, $cap),
        };

        return $minutes > 0 ? CarbonImmutable::now()->addMinutes($minutes) : null;
    }

    public function expiryForDevice(): ?CarbonImmutable
    {
        $minutes = $this->entry('device');

        return $minutes > 0 ? CarbonImmutable::now()->addMinutes($minutes) : null;
    }

    private function entry(string $key): int
    {
        $value = $this->config->get("tanaad.auth.token_ttl_minutes.{$key}");
        if ($value === null) {
            $value = $this->config->get('tanaad.auth.token_ttl_minutes.default', 720);
        }

        return max(0, (int) $value);
    }
}
