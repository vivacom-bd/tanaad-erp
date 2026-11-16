<?php

declare(strict_types=1);

namespace Tanaad\Shared\Ledger;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * The server's receipt time for events (events.received_at). In normal operation it is simply now().
 *
 * Seeding pins the business clock (Carbon::setTestNow) per module so that "today", day locks and
 * future-day checks behave as of the demo date; that pinned time is a business fact and belongs in
 * occurred_at only. While seeding, receipt time is the real wall clock (ignoring the pinned clock),
 * never earlier than the ledger's high-water mark, so seq order and received_at stay monotonic and
 * ledger:verify holds on seeded data exactly as in production.
 */
final class ReceiptClock
{
    private bool $seeding = false;

    private ?CarbonImmutable $last = null;

    public function __construct(private readonly ConnectionInterface $db) {}

    public function now(): CarbonImmutable
    {
        if (! $this->seeding) {
            return CarbonImmutable::now();
        }
        $wall = CarbonImmutable::createFromInterface(new DateTimeImmutable('now'));
        $this->last = $this->last !== null && ! $wall->isAfter($this->last) ? $this->last->addMicrosecond() : $wall;

        return $this->last;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $seed
     * @return T
     */
    public function whileSeeding(callable $seed): mixed
    {
        $high = $this->db->table('events')->max('received_at');
        $this->last = is_string($high) ? CarbonImmutable::parse($high) : null;
        $this->seeding = true;

        try {
            return $seed();
        } finally {
            $this->seeding = false;
            $this->last = null;
        }
    }
}
