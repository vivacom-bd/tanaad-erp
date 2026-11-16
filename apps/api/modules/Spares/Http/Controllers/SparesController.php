<?php

declare(strict_types=1);

namespace Tanaad\Spares\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tanaad\Config\Support\Payload;
use Tanaad\Identity\Http\CurrentSite;
use Tanaad\Identity\Models\User;
use Tanaad\Shared\Http\ApiController;
use Tanaad\Shared\Ledger\CommandBus;
use Tanaad\Shared\Ledger\CommandResult;
use Tanaad\Shared\Ledger\Rejection;
use Tanaad\Spares\Authorization\SparesAuthorizer;
use Tanaad\Spares\Commands\IssuePart;
use Tanaad\Spares\Commands\ReceivePart;
use Tanaad\Spares\Commands\ReportBreakdown;
use Tanaad\Spares\Commands\ResolveBreakdown;
use Tanaad\Spares\Commands\SavePart;
use Tanaad\Spares\Queries\BreakdownQuery;
use Tanaad\Spares\Queries\SparesQuery;

/** Reads take the prototype's camelCase shape; writes accept it and map it to the snake_case event payload. */
final class SparesController extends ApiController
{
    private const array PART = ['id' => 'part_id', 'partId' => 'part_id', 'code' => 'code', 'name' => 'name', 'unit' => 'unit', 'minLevel' => 'min_level',
        'unitCost' => 'unit_cost', 'machineKinds' => 'machine_kinds', 'active' => 'active'];

    private const array RECEIVE = ['receiptId' => 'receipt_id', 'qty' => 'qty', 'supplier' => 'supplier', 'unitCost' => 'unit_cost'];

    private const array ISSUE = ['issueId' => 'issue_id', 'qty' => 'qty', 'machineId' => 'machine_id', 'mechanicId' => 'mechanic_id',
        'breakdownId' => 'breakdown_id', 'reason' => 'reason'];

    private const array REPORT = ['id' => 'breakdown_id', 'breakdownId' => 'breakdown_id', 'machineId' => 'machine_id', 'cause' => 'cause', 'startedAt' => 'started_at'];

    public function __construct(
        private readonly CommandBus $bus,
        private readonly SparesQuery $spares,
        private readonly BreakdownQuery $breakdowns,
        private readonly SparesAuthorizer $authorizer,
    ) {}

    public function store(Request $request): JsonResponse
    {
        return new JsonResponse($this->spares->store($this->readable($request, false)));
    }

    public function breakdowns(Request $request): JsonResponse
    {
        $site = $this->readable($request, true);

        return new JsonResponse(['breakdowns' => $this->breakdowns->breakdowns($site), 'machinesDown' => $this->breakdowns->machinesDown($site)]);
    }

    public function downtimeCost(Request $request): JsonResponse
    {
        $site = $this->readable($request, true);
        $today = CarbonImmutable::now((string) (DB::table('sites')->where('id', $site)->value('timezone') ?: 'UTC'))->format('Y-m-d');
        $p = new Payload([
            'from' => $request->query('from', CarbonImmutable::parse($today)->subDays(29)->format('Y-m-d')),
            'to' => $request->query('to', $today),
        ]);
        $from = $p->date('from');
        $to = $p->date('to');
        $p->check();
        if ($from > $to) {
            throw Rejection::invalidPayload(['from' => 'must not be after to']);
        }
        if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) >= BreakdownQuery::MAX_DAYS) {
            throw Rejection::invalidPayload(['to' => 'the period is at most '.BreakdownQuery::MAX_DAYS.' days']);
        }

        return new JsonResponse($this->breakdowns->downtimeCost($site, $from, $to));
    }

    public function savePart(Request $request): JsonResponse
    {
        $siteId = CurrentSite::resolve($request)->id;
        $result = $this->bus->dispatch(new SavePart($this->context($request, $siteId), $this->map($request, self::PART)));

        return $this->answer($result, 'spares.part_saved', 'part_id', 'parts', 'part', SparesQuery::part(...));
    }

    public function receive(Request $request, string $site, string $part): JsonResponse
    {
        $siteId = CurrentSite::resolve($request)->id;
        $result = $this->bus->dispatch(new ReceivePart($this->context($request, $siteId), ['part_id' => $part] + $this->map($request, self::RECEIVE)));

        return $this->answer($result, 'spares.part_received', 'part_id', 'parts', 'part', SparesQuery::part(...));
    }

    public function issue(Request $request, string $site, string $part): JsonResponse
    {
        $siteId = CurrentSite::resolve($request)->id;
        $result = $this->bus->dispatch(new IssuePart($this->context($request, $siteId), ['part_id' => $part] + $this->map($request, self::ISSUE)));

        return $this->answer($result, 'spares.part_issued', 'issue_id', 'part_issues', 'issue', SparesQuery::issue(...));
    }

    public function report(Request $request): JsonResponse
    {
        $siteId = CurrentSite::resolve($request)->id;
        $result = $this->bus->dispatch(new ReportBreakdown($this->context($request, $siteId), $this->map($request, self::REPORT)));

        return $this->answer($result, 'spares.breakdown_reported', 'breakdown_id', 'breakdowns', 'breakdown', BreakdownQuery::breakdown(...));
    }

    public function resolve(Request $request, string $site, string $breakdown): JsonResponse
    {
        $siteId = CurrentSite::resolve($request)->id;
        $payload = ['breakdown_id' => $breakdown] + $this->map($request, ['resolvedAt' => 'resolved_at']);
        $result = $this->bus->dispatch(new ResolveBreakdown($this->context($request, $siteId), $payload));

        return $this->answer($result, 'spares.breakdown_resolved', 'breakdown_id', 'breakdowns', 'breakdown', BreakdownQuery::breakdown(...));
    }

    /**
     * @param  array<string, string>  $fields
     * @return array<string, mixed>
     */
    private function map(Request $request, array $fields): array
    {
        $payload = [];
        foreach ($fields as $in => $key) {
            if ($request->has($in)) {
                $payload[$key] = $request->input($in);
            }
        }

        return $payload;
    }

    private function readable(Request $request, bool $breakdowns): string
    {
        $siteId = CurrentSite::resolve($request)->id;
        $user = $request->user();
        if (! $user instanceof User || ! $this->authorizer->canRead($user->loadMissing('siteRoles'), $siteId, $breakdowns)) {
            throw Rejection::forbidden('This role may not read this here.');
        }

        return $siteId;
    }

    /** @param \Closure(\stdClass): array<string, mixed> $shape */
    private function answer(CommandResult $result, string $type, string $idKey, string $table, string $name, \Closure $shape): JsonResponse
    {
        foreach ($result->events as $event) {
            if ($event->type() === $type) {
                $id = $event->payload()[$idKey] ?? null;
                $row = DB::table($table)->where('id', is_string($id) ? $id : '')->first() ?? throw new \LogicException('Not projected.');

                return $this->created([$name => $shape($row), 'event_id' => $event->id()]);
            }
        }
        throw new \LogicException("No {$type} event in the result.");
    }
}
