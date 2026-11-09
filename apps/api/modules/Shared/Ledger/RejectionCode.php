<?php

declare(strict_types=1);

namespace Tanaad\Shared\Ledger;

/**
 * Closed list of reasons a command can be rejected (ADR-004). Clients branch on these.
 */
enum RejectionCode: string
{
    case UnknownType = 'unknown_type';
    case InvalidPayload = 'invalid_payload';
    case Forbidden = 'forbidden';
    case DayLocked = 'day_locked';
    case NotFound = 'not_found';
    case StateConflict = 'state_conflict';
    case DuplicateFact = 'duplicate_fact';
    case StaleConfig = 'stale_config';

    public function httpStatus(): int
    {
        return match ($this) {
            self::UnknownType, self::InvalidPayload => 422,
            self::Forbidden => 403,
            self::NotFound => 404,
            self::DayLocked, self::StateConflict, self::DuplicateFact, self::StaleConfig => 409,
        };
    }
}
