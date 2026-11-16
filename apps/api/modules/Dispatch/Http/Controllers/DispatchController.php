<?php

declare(strict_types=1);

namespace Tanaad\Dispatch\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tanaad\Dispatch\Authorization\DispatchAuthorizer;
use Tanaad\Dispatch\Commands\ConfirmDelivery;
use Tanaad\Dispatch\Commands\OverrideGate;
use Tanaad\Dispatch\Commands\RecordGateWeight;
use Tanaad\Dispatch\Commands\ScanBundle;
use Tanaad\Dispatch\Commands\StartDispatch;
use Tanaad\Dispatch\Commands\UnscanBundle;
use Tanaad\Dispatch\Queries\DispatchQuery;
use Tanaad\Identity\Http\CurrentSite;
use Tanaad\Identity\Models\User;
use Tanaad\Shared\Http\ApiController;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\CommandBus;
use Tanaad\Shared\Ledger\CommandContext;
use Tanaad\Shared\Ledger\Rejection;

/** Reads in the prototype's camelCase; writes accept camelCase or snake_case and send the snake_case event payload. */
final class DispatchController extends ApiController
{
    public function __construct(
        private readonly CommandBus $bus,
        private readonly DispatchQuery $query,
        private readonly DispatchAuthorizer $authorizer,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $siteId = $this->readable($request);
        $status = $request->query('status');

        return new JsonResponse(['dispatches' => $this->query->list($siteId, is_string($status) && $status !== '' ? $status : null)]);
    }

    public function show(Request $request, string $site, string $dispatch): JsonResponse
    {
        return new JsonResponse(['dispatch' => $this->query->find($this->readable($request), $dispatch)]);
    }

    public function start(Request $request): JsonResponse
    {
        $order = $request->input('order');
        if (is_array($order)) {
            $order = $this->pick($order, ['order_ref' => 'orderRef', 'client_name' => 'clientName', 'site' => 'site', 'lines' => 'lines']);
            if (is_array($order['lines'] ?? null)) {
                $order['lines'] = array_map(fn ($l) => is_array($l) ? $this->pick($l, ['product_id' => 'productId', 'bundles' => 'bundles']) : $l, $order['lines']);
            }
        }
        $payload = [...$this->pick($request->all(), ['dispatch_id' => 'dispatchId', 'driver' => 'driver', 'vehicle' => 'vehicle']), 'order' => $order];

        return $this->send($request, fn (CommandContext $c) => new StartDispatch($c, $payload), null);
    }

    public function scan(Request $request, string $site, string $dispatch): JsonResponse
    {
        return $this->send($request, fn (CommandContext $c) => new ScanBundle($c, ['dispatch_id' => $dispatch, 'code' => $request->input('code')]), $dispatch);
    }

    public function unscan(Request $request, string $site, string $dispatch): JsonResponse
    {
        return $this->send($request, fn (CommandContext $c) => new UnscanBundle($c, ['dispatch_id' => $dispatch, 'code' => $request->input('code')]), $dispatch);
    }

    public function weigh(Request $request, string $site, string $dispatch): JsonResponse
    {
        return $this->send($request, fn (CommandContext $c) => new RecordGateWeight($c, ['dispatch_id' => $dispatch, 'kg' => $request->input('kg')]), $dispatch);
    }

    public function override(Request $request, string $site, string $dispatch): JsonResponse
    {
        return $this->send($request, fn (CommandContext $c) => new OverrideGate($c, ['dispatch_id' => $dispatch, 'reason' => $request->input('reason')]), $dispatch);
    }

    public function deliver(Request $request, string $site, string $dispatch): JsonResponse
    {
        $payload = $this->pick($request->all(), ['received_by' => 'receivedBy', 'bundles_received' => 'bundlesReceived',
            'signature_hash' => 'signatureHash', 'photo_hash' => 'photoHash']);

        return $this->send($request, fn (CommandContext $c) => new ConfirmDelivery($c, ['dispatch_id' => $dispatch, ...$payload]), $dispatch);
    }

    /** @param \Closure(CommandContext): Command $make */
    private function send(Request $request, \Closure $make, ?string $dispatchId): JsonResponse
    {
        $siteId = CurrentSite::resolve($request)->id;
        $result = $this->bus->dispatch($make($this->context($request, $siteId)));
        $primary = $result->primary();
        $id = $dispatchId ?? $primary?->payload()['dispatch_id'] ?? null;

        return $this->created([
            'dispatch' => $this->query->find($siteId, is_string($id) ? $id : ''),
            'event_id' => $primary?->id(),
            'events' => array_map(fn ($e): string => $e->type(), $result->events),
        ]);
    }

    /**
     * @param  array<mixed>  $in
     * @param  array<string, string>  $map  snake => camel
     * @return array<string, mixed>
     */
    private function pick(array $in, array $map): array
    {
        $out = [];
        foreach ($map as $snake => $camel) {
            if (array_key_exists($camel, $in)) {
                $out[$snake] = $in[$camel];
            } elseif (array_key_exists($snake, $in)) {
                $out[$snake] = $in[$snake];
            }
        }

        return $out;
    }

    private function readable(Request $request): string
    {
        $siteId = CurrentSite::resolve($request)->id;
        $user = $request->user();
        if (! $user instanceof User || ! $this->authorizer->canRead($user->loadMissing('siteRoles'), $siteId)) {
            throw Rejection::forbidden('Only the Gate, Storekeeper, Supervisor, Site Manager of this site or group roles may read its dispatches.');
        }

        return $siteId;
    }
}
