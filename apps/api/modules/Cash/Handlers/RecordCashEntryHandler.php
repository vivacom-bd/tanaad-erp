<?php

declare(strict_types=1);

namespace Tanaad\Cash\Handlers;

use Illuminate\Support\Str;
use Tanaad\Cash\Commands\RecordCashEntry;
use Tanaad\Cash\Models\CashDay;
use Tanaad\Cash\Support\CashBook;
use Tanaad\Config\Support\Payload;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\CommandContext;
use Tanaad\Shared\Ledger\DayLocks;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;
use Tanaad\Shared\Ledger\Rejection;

/** Records one cash movement; the first entry of a business day opens it with the previous counted cash. */
final class RecordCashEntryHandler implements Handler
{
    public function __construct(private readonly DayLocks $locks) {}

    public function handle(Command $command): array
    {
        if (! $command instanceof RecordCashEntry) {
            throw new \LogicException('Wrong command for '.self::class);
        }
        $ctx = $command->context();
        $siteId = $ctx->siteId ?? throw Rejection::invalidPayload(['site_id' => 'required']);
        $p = new Payload($command->payload);
        $today = CashBook::today($siteId, $ctx);
        $day = $p->optDate('day') ?? $today;
        CashBook::refuseFuture($p, 'day', $day, $today);
        $entry = self::fields($p);
        $entryId = $p->optUuid('entry_id') ?? (string) Str::uuid7();
        $p->check();

        $this->locks->assertOpen($siteId, CashBook::LEDGER, $day);

        return [...self::openIfNeeded($ctx, $siteId, $day, $entry['currency']), Event::from($ctx, 'cash.entry_recorded', [
            'entry_id' => $entryId,
            'cash_day_id' => CashBook::dayId($siteId, $day),
            'day' => $day,
            ...$entry,
        ])];
    }

    /**
     * The editable fields of an entry, shared with CorrectCashEntryHandler.
     *
     * @return array{direction: string, category: string, subcategory: ?string, amount: float, currency: string, fx_reference: ?float, machine_id: ?string, product_id: ?string, payee: ?string, note: ?string, has_receipt_photo: bool}
     */
    public static function fields(Payload $p): array
    {
        $category = $p->str('category', 64);
        if ($category !== '' && preg_match('/^[a-z0-9_]+$/', $category) !== 1) {
            $p->error('category', 'must be a category code (a-z, 0-9, _)');
        }

        $amount = $p->num('amount', positive: true);
        $currency = $p->has('currency') ? $p->currency('currency') : 'UGX';

        return [
            'direction' => $p->oneOf('direction', ['in', 'out']),
            'category' => $category,
            'subcategory' => $p->optStr('subcategory', 120),
            'amount' => CashBook::money($amount),
            'currency' => $currency,
            'fx_reference' => $p->optNum('fx_reference', positive: true),
            'machine_id' => $p->optUuid('machine_id'),
            'product_id' => $p->optUuid('product_id'),
            'payee' => $p->optStr('payee'),
            'note' => $p->optStr('note', 1000),
            'has_receipt_photo' => $p->bool('has_receipt_photo', false),
        ];
    }

    /** @return list<Event> a cash.day_opened when the site has no cash day for that date yet */
    public static function openIfNeeded(CommandContext $ctx, string $siteId, string $day, string $currency): array
    {
        $existing = CashDay::query()->find(CashBook::dayId($siteId, $day));
        if ($existing !== null) {
            if ($existing->currency !== $currency) {
                throw Rejection::invalidPayload(['currency' => "must be {$existing->currency}, the currency of this cash day"]);
            }

            return [];
        }

        return [Event::from($ctx, 'cash.day_opened', [
            'cash_day_id' => CashBook::dayId($siteId, $day),
            'day' => $day,
            'currency' => $currency,
            'opening' => CashBook::openingFor($siteId, $day),
        ], primary: false)];
    }
}
