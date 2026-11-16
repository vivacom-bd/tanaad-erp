<?php

declare(strict_types=1);

namespace Tanaad\Production\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tanaad\Config\Support\Payload;
use Tanaad\Identity\Http\CurrentSite;
use Tanaad\Identity\Models\User;
use Tanaad\Production\Authorization\ProductionAuthorizer;
use Tanaad\Production\Commands\CloseShift;
use Tanaad\Production\Commands\RecordBatch;
use Tanaad\Production\Commands\RecordProductionEntry;
use Tanaad\Production\Commands\WeighWaste;
use Tanaad\Production\Queries\ProductionQuery;
use Tanaad\Production\Support\ProductionBook;
use Tanaad\Shared\Http\ApiController;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\CommandBus;
use Tanaad\Shared\Ledger\Rejection;

/** Reads take the prototype's camelCase shape; writes accept camelCase and map it to the snake_case event payload. */
final class ProductionController extends ApiController
{
    private const array ENTRY = [
        'entryId' => 'entry_id', 'machineId' => 'machine_id', 'shift' => 'shift', 'day' => 'day', 'kind' => 'kind', 'stageCode' => 'stage_code',
        'productId' => 'product_id', 'operatorId' => 'operator_id', 'goodUnits' => 'good_units', 'rejectUnits' => 'reject_units',
        'inputKg' => 'input_kg', 'outputKg' => 'output_kg', 'downtimeMin' => 'downtime_min', 'downtimeReason' => 'downtime_reason', 'labelCodes' => 'label_codes',
    ];

    private const array BATCH = [
        'batchId' => 'batch_id', 'day' => 'day', 'kind' => 'kind', 'shift' => 'shift', 'machineId' => 'machine_id', 'productId' => 'product_id',
        'lines' => 'lines', 'outputKg' => 'output_kg', 'labelCode' => 'label_code', 'note' => 'note',
    ];

    private const array WASTE = [
        'wasteId' => 'waste_id', 'day' => 'day', 'stageCode' => 'stage_code', 'wasteKind' => 'waste_kind', 'kg' => 'kg', 'reason' => 'reason', 'machineId' => 'machine_id',
    ];

    public function __construct(
        private readonly CommandBus $bus,
        private readonly ProductionQuery $query,
        private readonly ProductionAuthorizer $authorizer,
        private readonly ProductionBook $book,
    ) {}

    public function wall(Request $request): JsonResponse
    {
        $siteId = $this->readable($request);
        $day = $this->day($request, $siteId);
        $kinds = $this->book->shiftKinds($siteId);
        $kind = $request->query('shift') ?? $this->book->calendar($siteId)->shiftKindAt(now()) ?? $kinds[0];
        if (! is_string($kind) || ! in_array($kind, $kinds, true)) {
            throw Rejection::invalidPayload(['shift' => 'must be one of: '.implode(', ', $kinds)]);
        }
        $stage = $request->query('stage');

        return new JsonResponse(['day' => $day, 'shift' => $kind, 'shiftId' => $this->book->shiftId($siteId, $day, $kind),
            'machines' => $this->query->machineWall($siteId, $day, $kind, is_string($stage) ? $stage : null)]);
    }

    public function massBalance(Request $request): JsonResponse
    {
        $siteId = $this->readable($request);

        return new JsonResponse($this->query->massBalance($siteId, $this->day($request, $siteId)));
    }

    public function output(Request $request): JsonResponse
    {
        $siteId = $this->readable($request);
        $day = $this->day($request, $siteId);

        return new JsonResponse(['day' => $day, 'byProduct' => $this->query->output($siteId, $day), 'yieldByLine' => $this->query->yieldByLine($siteId, $day)]);
    }

    public function entry(Request $request): JsonResponse
    {
        return $this->send($request, fn ($ctx) => new RecordProductionEntry($ctx, $this->pick($request, self::ENTRY)));
    }

    public function batch(Request $request): JsonResponse
    {
        return $this->send($request, fn ($ctx) => new RecordBatch($ctx, $this->pick($request, self::BATCH)));
    }

    public function closeShift(Request $request): JsonResponse
    {
        return $this->send($request, fn ($ctx) => new CloseShift($ctx, $this->pick($request, ['day' => 'day', 'kind' => 'kind', 'shift' => 'shift'])));
    }

    public function waste(Request $request): JsonResponse
    {
        return $this->send($request, fn ($ctx) => new WeighWaste($ctx, $this->pick($request, self::WASTE)));
    }

    /** @param \Closure(\Tanaad\Shared\Ledger\CommandContext): Command $make */
    private function send(Request $request, \Closure $make): JsonResponse
    {
        $siteId = CurrentSite::resolve($request)->id;
        $result = $this->bus->dispatch($make($this->context($request, $siteId)));
        $event = $result->events[0] ?? throw new \LogicException('No event in the result.');

        return $this->created(['type' => $event->type(), 'payload' => $event->payload(), 'event_id' => $event->id()]);
    }

    private function readable(Request $request): string
    {
        $siteId = CurrentSite::resolve($request)->id;
        $user = $request->user();
        if (! $user instanceof User || ! $this->authorizer->canRead($user->loadMissing('siteRoles'), $siteId)) {
            throw Rejection::forbidden('Only the Supervisor, the Site Manager or group roles may read production.');
        }

        return $siteId;
    }

    private function day(Request $request, string $siteId): string
    {
        $day = $request->query('day') ?? $this->book->today($siteId);
        if (! is_string($day)) {
            throw Rejection::invalidPayload(['day' => 'must be a date (YYYY-MM-DD)']);
        }
        $check = new Payload(['day' => $day]);
        $check->optDate('day');
        $check->check();

        return $day;
    }

    /**
     * @param  array<string, string>  $fields
     * @return array<string, mixed>
     */
    private function pick(Request $request, array $fields): array
    {
        $out = [];
        foreach ($fields as $in => $key) {
            if ($request->has($in)) {
                $out[$key] = $request->input($in);
            } elseif ($request->has($key)) {
                $out[$key] = $request->input($key);
            }
        }

        return $out;
    }
}
