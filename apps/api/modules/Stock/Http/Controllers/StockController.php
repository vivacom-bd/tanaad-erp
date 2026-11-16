<?php

declare(strict_types=1);

namespace Tanaad\Stock\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tanaad\Identity\Http\CurrentSite;
use Tanaad\Identity\Models\User;
use Tanaad\Shared\Http\ApiController;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\CommandBus;
use Tanaad\Shared\Ledger\CommandContext;
use Tanaad\Shared\Ledger\Rejection;
use Tanaad\Stock\Authorization\StockAuthorizer;
use Tanaad\Stock\Commands\MoveStock;
use Tanaad\Stock\Commands\ReceiveStock;
use Tanaad\Stock\Commands\ReceiveTransfer;
use Tanaad\Stock\Commands\RecordStockCount;
use Tanaad\Stock\Commands\ReopenStockCount;
use Tanaad\Stock\Commands\ScrapLabel;
use Tanaad\Stock\Commands\SendTransfer;
use Tanaad\Stock\Queries\StockQuery;

/** Reads take the prototype's camelCase shape; writes accept camelCase or snake_case and map to the event payload. */
final class StockController extends ApiController
{
    public function __construct(
        private readonly CommandBus $bus,
        private readonly StockQuery $query,
        private readonly StockAuthorizer $authorizer,
    ) {}

    public function onHand(Request $request): JsonResponse
    {
        return new JsonResponse(['stock' => $this->query->onHand($this->readable($request))]);
    }

    public function movements(Request $request): JsonResponse
    {
        $siteId = $this->readable($request);
        $tz = (string) DB::table('sites')->where('id', $siteId)->value('timezone');

        return new JsonResponse($this->query->movements(
            $siteId, $this->opt($request, 'kind'), $this->opt($request, 'day'), $tz,
            max(1, (int) $request->query('page', 1)), min(200, max(1, (int) $request->query('perPage', 50))),
        ));
    }

    public function variance(Request $request): JsonResponse
    {
        $siteId = $this->readable($request);
        $day = $this->opt($request, 'day') ?? throw Rejection::invalidPayload(['day' => 'required']);

        return new JsonResponse($this->query->variance($siteId, $day));
    }

    public function transfers(Request $request): JsonResponse
    {
        return new JsonResponse(['transfers' => $this->query->transfers($this->readable($request))]);
    }

    public function receive(Request $request): JsonResponse
    {
        return $this->run($request, fn (CommandContext $c, array $p): Command => new ReceiveStock($c, $p));
    }

    public function move(Request $request): JsonResponse
    {
        return $this->run($request, fn (CommandContext $c, array $p): Command => new MoveStock($c, $p));
    }

    public function scrap(Request $request, string $site, string $label): JsonResponse
    {
        return $this->run($request, fn (CommandContext $c, array $p): Command => new ScrapLabel($c, [...$p, Str::isUuid($label) ? 'label_id' : 'code' => $label]));
    }

    public function send(Request $request): JsonResponse
    {
        return $this->run($request, fn (CommandContext $c, array $p): Command => new SendTransfer($c, $p));
    }

    public function receiveTransfer(Request $request, string $site, string $transfer): JsonResponse
    {
        return $this->run($request, fn (CommandContext $c, array $p): Command => new ReceiveTransfer($c, [...$p, 'transfer_id' => $transfer]));
    }

    public function count(Request $request): JsonResponse
    {
        return $this->run($request, fn (CommandContext $c, array $p): Command => new RecordStockCount($c, $p));
    }

    public function reopen(Request $request): JsonResponse
    {
        return $this->run($request, fn (CommandContext $c, array $p): Command => new ReopenStockCount($c, $p));
    }

    /** @param \Closure(CommandContext, array<string, mixed>): Command $make */
    private function run(Request $request, \Closure $make): JsonResponse
    {
        $siteId = CurrentSite::resolve($request)->id;
        $payload = [];
        foreach ($request->all() as $k => $v) {
            $payload[Str::snake((string) $k)] = $v;
        }
        $result = $this->bus->dispatch($make($this->context($request, $siteId), $payload));
        $events = array_map(fn ($e): array => ['id' => $e->id(), 'type' => $e->type(), 'payload' => $e->payload()], $result->events);
        $last = $events === [] ? null : $events[count($events) - 1];

        return $this->created(['events' => $events, 'event_id' => $last['id'] ?? null]);
    }

    private function opt(Request $request, string $key): ?string
    {
        $v = $request->query($key);

        return is_string($v) && $v !== '' ? $v : null;
    }

    private function readable(Request $request): string
    {
        $siteId = CurrentSite::resolve($request)->id;
        $user = $request->user();
        if (! $user instanceof User || ! $this->authorizer->canRead($user->loadMissing('siteRoles'), $siteId)) {
            throw Rejection::forbidden('Only the Storekeeper, Supervisor, Site Manager of this site or group roles may read its stock.');
        }

        return $siteId;
    }
}
