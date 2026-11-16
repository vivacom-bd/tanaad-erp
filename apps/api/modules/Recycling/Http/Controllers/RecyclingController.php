<?php

declare(strict_types=1);

namespace Tanaad\Recycling\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tanaad\Config\Support\Payload;
use Tanaad\Identity\Http\CurrentSite;
use Tanaad\Identity\Models\User;
use Tanaad\Recycling\Authorization\RecyclingAuthorizer;
use Tanaad\Recycling\Commands\PayCollector;
use Tanaad\Recycling\Commands\RecordCollectorIntake;
use Tanaad\Recycling\Queries\CollectorQuery;
use Tanaad\Recycling\Support\Intakes;
use Tanaad\Shared\Http\ApiController;
use Tanaad\Shared\Ledger\CommandBus;
use Tanaad\Shared\Ledger\CommandResult;
use Tanaad\Shared\Ledger\Rejection;

/** Reads take the prototype's camelCase shape; writes accept it and map it to the snake_case event payload. */
final class RecyclingController extends ApiController
{
    /** netKg/amount are deliberately absent: the server computes them. */
    private const array INTAKE_FIELDS = [
        'id' => 'delivery_id', 'deliveryId' => 'delivery_id', 'day' => 'day', 'collector' => 'collector', 'grossKg' => 'gross_kg',
        'tareKg' => 'tare_kg', 'deductionPct' => 'deduction_pct', 'deductionPresetId' => 'deduction_preset_id',
        'pricePerKg' => 'price_per_kg', 'hasScalePhoto' => 'has_scale_photo', 'scalePhotoHash' => 'scale_photo_hash',
        'provisionalNo' => 'provisional_no',
    ];

    public function __construct(
        private readonly CommandBus $bus,
        private readonly CollectorQuery $query,
        private readonly RecyclingAuthorizer $authorizer,
    ) {}

    public function deliveries(Request $request): JsonResponse
    {
        $siteId = $this->readable($request, false);
        $day = $request->query('day');
        if (! is_string($day) || $day === '') {
            $day = Intakes::businessDay($siteId, \Carbon\CarbonImmutable::now());
        }
        $check = new Payload(['day' => $day]);
        $check->optDate('day');
        $check->check();

        return new JsonResponse($this->query->day($siteId, $day));
    }

    public function ledger(Request $request): JsonResponse
    {
        return new JsonResponse(['collectors' => $this->query->ledger($this->readable($request, true))]);
    }

    public function record(Request $request): JsonResponse
    {
        $siteId = CurrentSite::resolve($request)->id;
        $payload = [];
        foreach (self::INTAKE_FIELDS as $in => $key) {
            if ($request->has($in)) {
                $payload[$key] = $request->input($in);
            }
        }
        $result = $this->bus->dispatch(new RecordCollectorIntake($this->context($request, $siteId), $payload));

        return $this->answer($result, 'recycling.collector_intake_recorded');
    }

    public function pay(Request $request, string $site, string $delivery): JsonResponse
    {
        $siteId = CurrentSite::resolve($request)->id;
        $payload = ['delivery_id' => $delivery];
        if ($request->has('paymentId')) {
            $payload['payment_id'] = $request->input('paymentId');
        }
        $result = $this->bus->dispatch(new PayCollector($this->context($request, $siteId), $payload));

        return $this->answer($result, 'recycling.collector_paid');
    }

    private function readable(Request $request, bool $ledger): string
    {
        $siteId = CurrentSite::resolve($request)->id;
        $user = $request->user();
        if (! $user instanceof User || ! $this->authorizer->canRead($user->loadMissing('siteRoles'), $siteId, $ledger)) {
            throw Rejection::forbidden('This role may not read collector deliveries here.');
        }

        return $siteId;
    }

    private function answer(CommandResult $result, string $type): JsonResponse
    {
        foreach ($result->events as $event) {
            if ($event->type() === $type) {
                $id = $event->payload()['delivery_id'] ?? null;
                $row = DB::table('collector_deliveries')->where('id', is_string($id) ? $id : '')->first()
                    ?? throw new \LogicException('Delivery not projected.');

                return $this->created(['delivery' => CollectorQuery::delivery($row), 'event_id' => $event->id()]);
            }
        }
        throw new \LogicException("No {$type} event in the result.");
    }
}
