<?php

declare(strict_types=1);

namespace Tanaad\Cash\Handlers;

use Tanaad\Cash\Commands\CloseCashDay;
use Tanaad\Cash\Models\CashDay;
use Tanaad\Cash\Support\CashBook;
use Tanaad\Config\Support\Payload;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\DayLocks;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;
use Tanaad\Shared\Ledger\Rejection;

/**
 * The cashier's count. Expected closing and variance come from Arithmetic::cashClose and
 * travel in the event so a variance policy can react without reading Cash tables.
 * CashProjector writes the day lock.
 */
final class CloseCashDayHandler implements Handler
{
    public function __construct(private readonly DayLocks $locks) {}

    public function handle(Command $command): array
    {
        if (! $command instanceof CloseCashDay) {
            throw new \LogicException('Wrong command for '.self::class);
        }
        $ctx = $command->context();
        $siteId = $ctx->siteId ?? throw Rejection::invalidPayload(['site_id' => 'required']);
        $p = new Payload($command->payload);
        $day = $p->date('day');
        CashBook::refuseFuture($p, 'day', $day, CashBook::today($siteId, $ctx));
        $counted = CashBook::money($p->num('counted'));
        $denominations = self::denominations($p, $command->payload['denominations'] ?? []);
        $p->check();

        $this->locks->assertOpen($siteId, CashBook::LEDGER, $day);
        $cashDay = CashDay::query()->find(CashBook::dayId($siteId, $day));
        if ($cashDay?->status === 'closed') {
            throw Rejection::stateConflict("The cash day {$day} is already closed.");
        }
        $events = $cashDay === null ? RecordCashEntryHandler::openIfNeeded($ctx, $siteId, $day, 'UGX') : [];
        $opening = $cashDay === null ? CashBook::openingFor($siteId, $day) : CashBook::money((string) $cashDay->opening);
        $buckets = CashBook::buckets(CashBook::dayId($siteId, $day));
        $r = CashBook::close($opening, CashBook::dayId($siteId, $day), $counted);

        $events[] = Event::from($ctx, 'cash.day_closed', [
            'cash_day_id' => CashBook::dayId($siteId, $day),
            'day' => $day,
            'currency' => $cashDay === null ? 'UGX' : $cashDay->currency,
            'opening' => $opening,
            ...$buckets,
            'expected_closing' => $r['expectedClosing'],
            'counted' => $counted,
            'variance' => $r['variance'],
            'denominations' => $denominations,
        ]);

        return $events;
    }

    /** @return array<int|string, int> note/coin value → count (PHP turns numeric keys into ints) */
    private static function denominations(Payload $p, mixed $raw): array
    {
        if (! is_array($raw)) {
            $p->error('denominations', 'must be an object of value → count');

            return [];
        }
        $out = [];
        foreach ($raw as $value => $count) {
            if (! is_numeric((string) $value) || ! is_int($count) || $count < 0) {
                $p->error("denominations.{$value}", 'must be a whole count of at least 0');

                continue;
            }
            $out[(string) $value] = $count;
        }

        return $out;
    }
}
