<?php

declare(strict_types=1);

namespace Tanaad\Identity;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Hashing\Hasher;
use Tanaad\Identity\Models\User;
use Tanaad\Shared\Ledger\Rejection;

/**
 * Argon2id PINs with lockout after N wrong attempts (ADR-006 §4).
 */
final class PinLockout
{
    public function __construct(private readonly Hasher $hasher, private readonly Config $config) {}

    public function hash(string $pin): string
    {
        return $this->hasher->make($pin);
    }

    /** @throws Rejection forbidden when locked or wrong */
    public function verify(User $user, string $pin): void
    {
        $now = CarbonImmutable::now();
        if ($user->pin_locked_until !== null && $user->pin_locked_until->isAfter($now)) {
            throw Rejection::forbidden('Too many wrong PINs. Try again at '.$user->pin_locked_until->setTimezone((string) $this->config->get('tanaad.timezone'))->format('H:i').'.');
        }
        if ($user->pin_hash === null || ! $this->hasher->check($pin, $user->pin_hash)) {
            $max = (int) $this->config->get('tanaad.pin.max_attempts', 5);
            $count = $user->pin_failed_count + 1;
            $locked = $count >= $max;
            $user->forceFill([
                'pin_failed_count' => $locked ? 0 : $count,
                'pin_locked_until' => $locked ? $now->addMinutes((int) $this->config->get('tanaad.pin.lockout_minutes', 15)) : null,
            ])->save();
            throw Rejection::forbidden($locked ? 'Too many wrong PINs. The PIN is locked for a while.' : 'Wrong PIN.');
        }
        if ($user->pin_failed_count !== 0 || $user->pin_locked_until !== null) {
            $user->forceFill(['pin_failed_count' => 0, 'pin_locked_until' => null])->save();
        }
    }
}
