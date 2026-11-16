<?php

declare(strict_types=1);

namespace Tanaad\Purchases\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tanaad\Identity\Http\CurrentSite;
use Tanaad\Identity\Models\User;
use Tanaad\Purchases\Authorization\PurchasesAuthorizer;
use Tanaad\Purchases\Commands\ChangePurchase;
use Tanaad\Purchases\Commands\ReceivePurchase;
use Tanaad\Purchases\Commands\RecordPurchase;
use Tanaad\Purchases\Queries\PurchaseQuery;
use Tanaad\Shared\Http\ApiController;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\CommandBus;
use Tanaad\Shared\Ledger\Rejection;

/** Reads take the prototype's camelCase shape; writes accept it and map it to the snake_case event payload. */
final class PurchasesController extends ApiController
{
    /** reference and fxReference are deliberately absent: the server allocates and captures them. */
    private const array FIELDS = [
        'id' => 'purchase_id', 'purchaseId' => 'purchase_id', 'supplier' => 'supplier', 'originCountry' => 'origin_country',
        'material' => 'material', 'qtyKg' => 'qty_kg', 'unitPrice' => 'unit_price', 'currency' => 'currency', 'costs' => 'costs',
        'expectedOn' => 'expected_on', 'note' => 'note', 'status' => 'status',
    ];

    public function __construct(
        private readonly CommandBus $bus,
        private readonly PurchaseQuery $query,
        private readonly PurchasesAuthorizer $authorizer,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $siteId = CurrentSite::resolve($request)->id;
        $user = $request->user();
        if (! $user instanceof User || ! $this->authorizer->canRead($user->loadMissing('siteRoles'), $siteId)) {
            throw Rejection::forbidden('This role may not read purchases here.');
        }

        return new JsonResponse(['purchases' => $this->query->forSite($siteId)]);
    }

    public function record(Request $request): JsonResponse
    {
        $siteId = CurrentSite::resolve($request)->id;

        return $this->run($request, new RecordPurchase($this->context($request, $siteId), $this->body($request)), 'purchases.recorded');
    }

    public function change(Request $request, string $site, string $purchase): JsonResponse
    {
        $siteId = CurrentSite::resolve($request)->id;
        $payload = [...$this->body($request), 'purchase_id' => $purchase];

        return $this->run($request, new ChangePurchase($this->context($request, $siteId), $payload), 'purchases.changed');
    }

    public function receive(Request $request, string $site, string $purchase): JsonResponse
    {
        $siteId = CurrentSite::resolve($request)->id;
        $payload = ['purchase_id' => $purchase];
        foreach (['receivedKg' => 'received_kg', 'receivedOn' => 'received_on'] as $in => $key) {
            if ($request->has($in)) {
                $payload[$key] = $request->input($in);
            }
        }

        return $this->run($request, new ReceivePurchase($this->context($request, $siteId), $payload), 'purchases.received');
    }

    /** @return array<string, mixed> */
    private function body(Request $request): array
    {
        $payload = [];
        foreach (self::FIELDS as $in => $key) {
            if ($request->has($in)) {
                $payload[$key] = $request->input($in);
            }
        }

        return $payload;
    }

    private function run(Request $request, Command $command, string $type): JsonResponse
    {
        $result = $this->bus->dispatch($command);
        foreach ($result->events as $event) {
            if ($event->type() === $type) {
                $id = $event->payload()['purchase_id'] ?? null;
                $row = DB::table('purchases')->where('id', is_string($id) ? $id : '')->first()
                    ?? throw new \LogicException('Purchase not projected.');

                return $this->created(['purchase' => PurchaseQuery::purchase($row), 'event_id' => $event->id()]);
            }
        }
        throw new \LogicException("No {$type} event in the result.");
    }
}
