<?php

declare(strict_types=1);

namespace Tanaad\Identity;

/** The ten roles from packages/domain. Group roles see every site. */
enum Role: string
{
    case Ceo = 'ceo';
    case GroupAdmin = 'group_admin';
    case SiteManager = 'site_manager';
    case Supervisor = 'supervisor';
    case Cashier = 'cashier';
    case Storekeeper = 'storekeeper';
    case Gate = 'gate';
    case Mechanic = 'mechanic';
    case Sales = 'sales';
    case Driver = 'driver';

    public function isGroup(): bool
    {
        return $this === self::Ceo || $this === self::GroupAdmin;
    }

    /** Hours a device-bound session stays valid for this role. */
    public function sessionHours(int $default): int
    {
        return match ($this) {
            self::Ceo, self::GroupAdmin, self::SiteManager => 24,
            default => $default,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $r): string => $r->value, self::cases());
    }
}
