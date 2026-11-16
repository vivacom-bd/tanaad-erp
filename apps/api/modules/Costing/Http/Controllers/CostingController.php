<?php

declare(strict_types=1);

namespace Tanaad\Costing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tanaad\Config\Support\Payload;
use Tanaad\Costing\Authorization\CostingAuthorizer;
use Tanaad\Costing\Commands\ClosePeriod;
use Tanaad\Costing\Commands\ReopenPeriod;
use Tanaad\Costing\Commands\SaveCostMapping;
use Tanaad\Costing\Queries\CostingQuery;
use Tanaad\Costing\Support\CostBook;
use Tanaad\Costing\Support\Period;
use Tanaad\Identity\Http\CurrentSite;
use Tanaad\Identity\Models\User;
use Tanaad\Shared\Http\ApiController;
use Tanaad\Shared\Ledger\CommandBus;
use Tanaad\Shared\Ledger\CommandResult;
use Tanaad\Shared\Ledger\Rejection;

final class CostingController extends ApiController
{
    public function __construct(
        private readonly CommandBus $bus,
        private readonly CostingQuery $query,
        private readonly CostingAuthorizer $authorizer,
    ) {}

    /** GET /sites/{site}/costing/pnl?month=YYYY-MM (or from&to) */
    public function pnl(Request $request): JsonResponse
    {
        $siteId = $this->readable($request);

        return new JsonResponse($this->query->pnl($siteId, $this->period($request)));
    }

    /** GET /sites/{site}/costing/unit-cost?from=&to= (or month) */
    public function unitCost(Request $request): JsonResponse
    {
        $siteId = $this->readable($request);

        return new JsonResponse($this->query->unitCost($siteId, $this->period($request)));
    }

    /** GET /costing/pnl?month= : every site and the group, CEO and group admin only. */
    public function group(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User || ! $this->authorizer->canReadGroup($user->loadMissing('siteRoles'))) {
            throw Rejection::forbidden('Only the CEO or the group admin may read the group P&L.');
        }

        return new JsonResponse($this->query->group($this->period($request)));
    }

    public function close(Request $request): JsonResponse
    {
        $siteId = CurrentSite::resolve($request)->id;
        $result = $this->bus->dispatch(new ClosePeriod($this->context($request, $siteId), $this->body($request, [])));

        return $this->answer($result, $siteId);
    }

    public function reopen(Request $request): JsonResponse
    {
        $siteId = CurrentSite::resolve($request)->id;
        $result = $this->bus->dispatch(new ReopenPeriod($this->context($request, $siteId), $this->body($request, ['reason'])));

        return $this->answer($result, $siteId);
    }

    public function mapping(Request $request): JsonResponse
    {
        $siteId = CurrentSite::resolve($request)->id;
        $result = $this->bus->dispatch(new SaveCostMapping($this->context($request, $siteId), [
            'source_key' => $request->input('sourceKey', $request->input('source_key')),
            'cost_category' => $request->input('costCategory', $request->input('cost_category')),
        ]));

        return $this->created(['mapping' => CostBook::mapping($siteId), 'event_id' => $result->primary()?->id()]);
    }

    private function readable(Request $request): string
    {
        $siteId = CurrentSite::resolve($request)->id;
        $user = $request->user();
        if (! $user instanceof User || ! $this->authorizer->canRead($user->loadMissing('siteRoles'), $siteId)) {
            throw Rejection::forbidden('Only the Site Manager or group roles may read costing.');
        }

        return $siteId;
    }

    private function period(Request $request): Period
    {
        $raw = array_filter(['month' => $request->query('month'), 'from' => $request->query('from'), 'to' => $request->query('to')], fn ($v) => $v !== null);
        $p = new Payload($raw);
        $period = Period::read($p, $raw);
        $p->check();
        $period = $period ?? throw new \LogicException('Payload::check passed without a period.');

        return $period;
    }

    /**
     * @param  list<string>  $extra
     * @return array<string, mixed>
     */
    private function body(Request $request, array $extra): array
    {
        $out = [];
        foreach (['month', 'from', 'to', ...$extra] as $key) {
            if ($request->has($key)) {
                $out[$key] = $request->input($key);
            }
        }

        return $out;
    }

    private function answer(CommandResult $result, string $siteId): JsonResponse
    {
        $event = $result->primary() ?? throw new \LogicException('No costing event in the result.');
        $p = $event->payload();
        $period = new Period((string) $p['label'], (string) $p['from'], (string) $p['to']);

        return $this->created(['period' => $this->query->pnl($siteId, $period), 'event_id' => $event->id()]);
    }
}
