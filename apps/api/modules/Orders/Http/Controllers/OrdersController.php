<?php

declare(strict_types=1);

namespace Tanaad\Orders\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tanaad\Identity\Http\CurrentSite;
use Tanaad\Identity\Models\User;
use Tanaad\Orders\Authorization\OrdersAuthorizer;
use Tanaad\Orders\Commands\ApproveOrder;
use Tanaad\Orders\Commands\ConfirmOrderCash;
use Tanaad\Orders\Commands\CreateOrder;
use Tanaad\Orders\Commands\HoldOrder;
use Tanaad\Orders\Commands\IssueInvoice;
use Tanaad\Orders\Commands\RecordPayment;
use Tanaad\Orders\Commands\RegisterClient;
use Tanaad\Orders\Commands\UpdateClient;
use Tanaad\Orders\Queries\OrdersQuery;
use Tanaad\Orders\Support\Sales;
use Tanaad\Shared\Http\ApiController;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\CommandBus;
use Tanaad\Shared\Ledger\Rejection;

/** Reads take the prototype's camelCase shape; writes accept camelCase or snake_case and map to the event payload. */
final class OrdersController extends ApiController
{
    public function __construct(
        private readonly CommandBus $bus,
        private readonly OrdersQuery $query,
        private readonly OrdersAuthorizer $authorizer,
    ) {}

    public function clients(Request $request): JsonResponse
    {
        $this->readable($request, OrdersAuthorizer::CLIENTS);

        return new JsonResponse(['clients' => $this->query->clients()]);
    }

    public function registerClient(Request $request): JsonResponse
    {
        return $this->client($request, fn (array $p, $ctx): Command => new RegisterClient($ctx, $p), null);
    }

    public function updateClient(Request $request, string $site, string $client): JsonResponse
    {
        return $this->client($request, fn (array $p, $ctx): Command => new UpdateClient($ctx, $p), $client);
    }

    public function statement(Request $request, string $site, string $client): JsonResponse
    {
        [$siteId, $user] = $this->readable($request, OrdersAuthorizer::MONEY);
        $out = $this->query->statement($client, $this->authorizer->seesGroup($user) ? null : $siteId, Sales::businessDay($siteId, CarbonImmutable::now()))
            ?? throw Rejection::notFound('Client', $client);

        return new JsonResponse($out);
    }

    public function receivables(Request $request): JsonResponse
    {
        [$siteId] = $this->readable($request, OrdersAuthorizer::MONEY);

        return new JsonResponse(['receivables' => $this->query->receivables($siteId, Sales::businessDay($siteId, CarbonImmutable::now()))]);
    }

    public function orders(Request $request): JsonResponse
    {
        [$siteId] = $this->readable($request, OrdersAuthorizer::ORDERS);
        $status = $request->query('status');
        if ($status !== null && (! is_string($status) || ! array_key_exists($status, Sales::RANK))) {
            throw Rejection::invalidPayload(['status' => 'must be an order status']);
        }

        return new JsonResponse(['orders' => $this->query->orders($siteId, $status)]);
    }

    public function show(Request $request, string $site, string $order): JsonResponse
    {
        [$siteId] = $this->readable($request, OrdersAuthorizer::ORDERS);

        return new JsonResponse(['order' => OrdersQuery::order($this->orderRow($siteId, $order))]);
    }

    /** The order snapshot Dispatch's StartDispatch takes: {order_ref, client_name, site, lines[{product_id, bundles}]}. */
    public function dispatchSnapshot(Request $request, string $site, string $order): JsonResponse
    {
        [$siteId] = $this->readable($request, OrdersAuthorizer::ORDERS);
        $o = $this->orderRow($siteId, $order);
        if (! in_array((string) $o->status, ['approved', 'picking'], true)) {
            throw Rejection::stateConflict("Order {$o->number} is {$o->status}; only an approved or cash-confirmed order is dispatched.");
        }
        $lines = json_decode((string) $o->lines, true);

        return new JsonResponse([
            'order_ref' => (string) $o->number, 'client_name' => (string) $o->client_name, 'site' => CurrentSite::resolve($request)->code,
            'lines' => array_map(fn (array $l): array => ['product_id' => $l['product_id'], 'bundles' => $l['bundles']], is_array($lines) ? $lines : []),
        ]);
    }

    public function create(Request $request): JsonResponse
    {
        $siteId = CurrentSite::resolve($request)->id;
        $p = self::snake($request->all());
        unset($p['number'], $p['status'], $p['total'], $p['fx_reference']);
        $p['order_id'] ??= (string) Str::uuid7();
        $this->bus->dispatch(new CreateOrder($this->context($request, $siteId), $p));

        return $this->orderAnswer($p['order_id'] ?? null, $request, 201);
    }

    public function confirmCash(Request $request, string $site, string $order): JsonResponse
    {
        return $this->onOrder($request, $order, fn (array $p, $ctx): Command => new ConfirmOrderCash($ctx, $p));
    }

    public function approve(Request $request, string $site, string $order): JsonResponse
    {
        return $this->onOrder($request, $order, fn (array $p, $ctx): Command => new ApproveOrder($ctx, $p));
    }

    public function hold(Request $request, string $site, string $order): JsonResponse
    {
        return $this->onOrder($request, $order, fn (array $p, $ctx): Command => new HoldOrder($ctx, $p));
    }

    public function invoice(Request $request, string $site, string $order): JsonResponse
    {
        $siteId = CurrentSite::resolve($request)->id;
        $p = [...self::snake($request->all()), 'order_id' => $order];
        $p['invoice_id'] ??= (string) Str::uuid7();
        $this->bus->dispatch(new IssueInvoice($this->context($request, $siteId), $p));
        $row = DB::table('invoices')->where('id', $p['invoice_id'])->first() ?? throw new \LogicException('Invoice not projected.');

        return $this->created(['invoice' => OrdersQuery::invoice($row), 'order' => OrdersQuery::order($this->orderRow($siteId, $order))]);
    }

    public function invoices(Request $request): JsonResponse
    {
        [$siteId] = $this->readable($request, OrdersAuthorizer::MONEY);

        return new JsonResponse(['invoices' => $this->query->invoices($siteId)]);
    }

    public function payments(Request $request): JsonResponse
    {
        [$siteId] = $this->readable($request, OrdersAuthorizer::MONEY);

        return new JsonResponse(['payments' => $this->query->payments($siteId)]);
    }

    public function pay(Request $request, string $site, string $invoice): JsonResponse
    {
        $siteId = CurrentSite::resolve($request)->id;
        $p = [...self::snake($request->all()), 'invoice_id' => $invoice];
        $p['payment_id'] ??= (string) Str::uuid7();
        $this->bus->dispatch(new RecordPayment($this->context($request, $siteId), $p));
        $row = DB::table('payments')->where('id', $p['payment_id'])->first() ?? throw new \LogicException('Payment not projected.');
        $inv = DB::table('invoices')->where('id', $invoice)->first() ?? throw new \LogicException('Invoice not projected.');

        return $this->created(['payment' => OrdersQuery::payment($row), 'invoice' => OrdersQuery::invoice($inv)]);
    }

    /** @param \Closure(array<string, mixed>, \Tanaad\Shared\Ledger\CommandContext): Command $make */
    private function client(Request $request, \Closure $make, ?string $id): JsonResponse
    {
        $siteId = CurrentSite::resolve($request)->id;
        $p = self::snake($request->all());
        if ($id !== null) {
            $p['client_id'] = $id;
        }
        $p['client_id'] ??= (string) Str::uuid7();
        $this->bus->dispatch($make($p, $this->context($request, $siteId)));
        $row = DB::table('clients')->where('id', $p['client_id'])->first() ?? throw new \LogicException('Client not projected.');

        return $id === null ? $this->created(['client' => OrdersQuery::client($row)]) : new JsonResponse(['client' => OrdersQuery::client($row)]);
    }

    /** @param \Closure(array<string, mixed>, \Tanaad\Shared\Ledger\CommandContext): Command $make */
    private function onOrder(Request $request, string $order, \Closure $make): JsonResponse
    {
        $siteId = CurrentSite::resolve($request)->id;
        $this->bus->dispatch($make([...self::snake($request->all()), 'order_id' => $order], $this->context($request, $siteId)));

        return $this->orderAnswer($order, $request, 200);
    }

    private function orderAnswer(mixed $id, Request $request, int $status): JsonResponse
    {
        $siteId = CurrentSite::resolve($request)->id;
        $row = is_string($id) ? $this->orderRow($siteId, $id) : null;
        if ($row === null) {
            throw new \LogicException('Order not projected.');
        }

        return new JsonResponse(['order' => OrdersQuery::order($row)], $status);
    }

    private function orderRow(string $siteId, string $id): \stdClass
    {
        $row = Str::isUuid($id) ? DB::table('orders')->where(['id' => $id, 'site_id' => $siteId])->whereNull('deleted_at')->first() : null;

        return $row ?? throw Rejection::notFound('Order', $id);
    }

    /**
     * @param  OrdersAuthorizer::CLIENTS|OrdersAuthorizer::ORDERS|OrdersAuthorizer::MONEY  $what
     * @return array{0: string, 1: User}
     */
    private function readable(Request $request, string $what): array
    {
        $siteId = CurrentSite::resolve($request)->id;
        $user = $request->user();
        if (! $user instanceof User || ! $this->authorizer->canRead($user->loadMissing('siteRoles'), $siteId, $what)) {
            throw Rejection::forbidden("This role may not read {$what} here.");
        }

        return [$siteId, $user];
    }

    /**
     * camelCase keys (and those of lines) to the snake_case payload; unitPrice etc. are dropped by the handler.
     *
     * @param  array<mixed>  $in
     * @return array<string, mixed>
     */
    private static function snake(array $in): array
    {
        $out = [];
        foreach ($in as $k => $v) {
            $key = Str::snake((string) $k);
            $out[$key] = is_array($v) ? (array_is_list($v) ? array_map(fn ($x) => is_array($x) ? self::snake($x) : $x, $v) : self::snake($v)) : $v;
        }

        return $out;
    }
}
