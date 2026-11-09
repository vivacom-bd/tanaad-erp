<?php

declare(strict_types=1);

namespace Tanaad\Shared\Ledger;

/**
 * What a dispatch returns: the stored events, whether the command was new or a replay of
 * an id the ledger already had, and any numbers the server assigned (ADR-004).
 */
final readonly class CommandResult
{
    /**
     * @param  list<StoredEvent>  $events
     * @param  array<string, string>  $assigned  provisional ref → assigned number
     */
    public function __construct(
        public string $status,
        public array $events,
        public array $assigned = [],
    ) {}

    /**
     * A retry of an id the ledger already holds. The assigned number is read back from the
     * stored payload so a client that lost the first response still gets it (ADR-004).
     */
    public static function duplicate(StoredEvent $existing, ?string $provisionalRef = null): self
    {
        $number = $existing->payload()['number'] ?? null;
        $assigned = $provisionalRef !== null && is_string($number) ? [$provisionalRef => $number] : [];

        return new self('duplicate', [$existing], $assigned);
    }

    public function primary(): ?StoredEvent
    {
        return $this->events[0] ?? null;
    }

    public function isDuplicate(): bool
    {
        return $this->status === 'duplicate';
    }
}
