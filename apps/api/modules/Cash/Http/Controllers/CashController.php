<?php

declare(strict_types=1);

namespace Tanaad\Cash\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tanaad\Cash\Authorization\CashAuthorizer;
use Tanaad\Cash\Commands\CloseCashDay;
use Tanaad\Cash\Commands\CorrectCashEntry;
use Tanaad\Cash\Commands\RecordCashEntry;
use Tanaad\Cash\Commands\ReopenCashDay;
use Tanaad\Cash\Commands\SetCashOpening;
use Tanaad\Cash\Models\CashDay;
use Tanaad\Cash\Models\CashEntry;
use Tanaad\Cash\Queries\CashSummaryQuery;
use Tanaad\Cash\Support\CashBook;
use Tanaad\Identity\Http\CurrentSite;
use Tanaad\Identity\Models\User;
use Tanaad\Shared\Http\ApiController;
use Tanaad\Shared\Ledger\CommandBus;
use Tanaad\Shared\Ledger\CommandResult;
use Tanaad\Shared\Ledger\Rejection;

/** Reads take the prototype's camelCase shape; writes accept it and map it to the snake_case event payload. */
final class CashController extends ApiController
{
    private const array ENTRY_FIELDS = [
        'entryId' => 'entry_id', 'day' => 'day', 'direction' => 'direction', 'category' => 'category', 'subcategory' => 'subcategory',
        'amount' => 'amount', 'currency' => 'currency', 'fxReference' => 'fx_reference', 'machineId' => 'machine_id',
        'productId' => 'product_id', 'payee' => 'payee', 'note' => 'note', 'hasReceiptPhoto' => 'has_receipt_photo', 'reason' => 'reason',
    ];

    public function __construct(
        private readonly CommandBus $bus,
        private readonly CashSummaryQuery $query,
        private readonly CashAuthorizer $authorizer,
    ) {}

    public function day(Request $request, string $site, string $day): JsonResponse
    {
        $siteId = $this->readable($request);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1) {
            throw Rejection::invalidPayload(['day' => 'must be a date (YYYY-MM-DD)']);
        }

        return new JsonResponse($this->query->day($siteId, $day));
    }

    public function month(Request $request, string $site, string $month): JsonResponse
    {
        $siteId = $this->readable($request);
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            throw Rejection::invalidPayload(['month' => 'must be a month (YYYY-MM)']);
        }

        return new JsonResponse($this->query->month($siteId, $month));
    }

    public function record(Request $request): JsonResponse
    {
        $siteId = CurrentSite::resolve($request)->id;
        $result = $this->bus->dispatch(new RecordCashEntry($this->context($request, $siteId), $this->payload($request)));

        return $this->entryAnswer($result, 'cash.entry_recorded');
    }

    public function correct(Request $request, string $site, string $entry): JsonResponse
    {
        $siteId = CurrentSite::resolve($request)->id;
        $payload = [...$this->payload($request), 'corrects_entry_id' => $entry];
        $result = $this->bus->dispatch(new CorrectCashEntry($this->context($request, $siteId), $payload));

        return $this->entryAnswer($result, 'cash.entry_corrected');
    }

    public function close(Request $request, string $site, string $day): JsonResponse
    {
        $siteId = CurrentSite::resolve($request)->id;
        $result = $this->bus->dispatch(new CloseCashDay($this->context($request, $siteId), [
            'day' => $day, 'counted' => $request->input('counted'), 'denominations' => $request->input('denominations', []),
        ]));

        return $this->dayAnswer($result, $siteId, $day);
    }

    public function reopen(Request $request, string $site, string $day): JsonResponse
    {
        $siteId = CurrentSite::resolve($request)->id;
        $result = $this->bus->dispatch(new ReopenCashDay($this->context($request, $siteId), ['day' => $day, 'reason' => $request->input('reason')]));

        return $this->dayAnswer($result, $siteId, $day);
    }

    public function opening(Request $request, string $site, string $day): JsonResponse
    {
        $siteId = CurrentSite::resolve($request)->id;
        $result = $this->bus->dispatch(new SetCashOpening($this->context($request, $siteId), ['day' => $day, 'amount' => $request->input('amount')]));

        return $this->dayAnswer($result, $siteId, $day);
    }

    private function readable(Request $request): string
    {
        $siteId = CurrentSite::resolve($request)->id;
        $user = $request->user();
        if (! $user instanceof User || ! $this->authorizer->canRead($user->loadMissing('siteRoles'), $siteId)) {
            throw Rejection::forbidden('Only the Cashier, the Site Manager or group roles may read the cash book.');
        }

        return $siteId;
    }

    /** @return array<string, mixed> */
    private function payload(Request $request): array
    {
        $out = [];
        foreach (self::ENTRY_FIELDS as $in => $key) {
            if ($request->has($in)) {
                $out[$key] = $request->input($in);
            }
        }

        return $out;
    }

    private function entryAnswer(CommandResult $result, string $type): JsonResponse
    {
        foreach ($result->events as $event) {
            if ($event->type() === $type) {
                $entryId = $event->payload()['entry_id'] ?? null;
                $entry = CashEntry::query()->where('id', is_string($entryId) ? $entryId : '')->firstOrFail();

                return $this->created(['entry' => CashSummaryQuery::entry($entry), 'event_id' => $event->id()]);
            }
        }
        throw new \LogicException("No {$type} event in the result.");
    }

    private function dayAnswer(CommandResult $result, string $siteId, string $day): JsonResponse
    {
        $cashDay = CashDay::query()->findOrFail(CashBook::dayId($siteId, $day));
        $last = $result->events === [] ? null : $result->events[count($result->events) - 1];

        return $this->created(['day' => CashSummaryQuery::dayShape($cashDay), 'event_id' => $last?->id()]);
    }
}
