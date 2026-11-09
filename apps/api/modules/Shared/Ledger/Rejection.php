<?php

declare(strict_types=1);

namespace Tanaad\Shared\Ledger;

use RuntimeException;

final class Rejection extends RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(
        public readonly RejectionCode $rejectionCode,
        string $reason,
        public readonly array $details = [],
    ) {
        parent::__construct($reason);
    }

    /** @param array<string, mixed> $details */
    public static function of(RejectionCode $code, string $reason, array $details = []): self
    {
        return new self($code, $reason, $details);
    }

    public static function unknownType(string $type): self
    {
        return new self(RejectionCode::UnknownType, "No command is registered for event type \"{$type}\".", ['type' => $type]);
    }

    /** @param array<string, mixed> $errors */
    public static function invalidPayload(array $errors): self
    {
        return new self(RejectionCode::InvalidPayload, 'The payload failed validation.', ['errors' => $errors]);
    }

    public static function forbidden(string $reason = 'This role may not perform this action here.'): self
    {
        return new self(RejectionCode::Forbidden, $reason);
    }

    public static function dayLocked(string $ledger, string $day): self
    {
        return new self(RejectionCode::DayLocked, "The {$ledger} day {$day} is closed.", ['ledger' => $ledger, 'day' => $day]);
    }

    public static function notFound(string $what, string $id): self
    {
        return new self(RejectionCode::NotFound, "{$what} {$id} was not found.", ['id' => $id]);
    }

    public static function stateConflict(string $reason): self
    {
        return new self(RejectionCode::StateConflict, $reason);
    }

    public static function duplicateFact(string $reason): self
    {
        return new self(RejectionCode::DuplicateFact, $reason);
    }

    public static function staleConfig(string $reason): self
    {
        return new self(RejectionCode::StaleConfig, $reason);
    }

    /** @return array{code: string, reason: string, details: array<string, mixed>} */
    public function toArray(): array
    {
        return ['code' => $this->rejectionCode->value, 'reason' => $this->getMessage(), 'details' => $this->details];
    }
}
