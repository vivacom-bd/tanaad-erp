<?php

declare(strict_types=1);

namespace Tanaad\Labels\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tanaad\Identity\Http\CurrentSite;
use Tanaad\Identity\Models\User;
use Tanaad\Labels\Authorization\LabelsAuthorizer;
use Tanaad\Labels\Commands\ConsumeLabel;
use Tanaad\Labels\Commands\CreateLabel;
use Tanaad\Labels\Models\Label;
use Tanaad\Labels\Queries\LabelsQuery;
use Tanaad\Shared\Http\ApiController;
use Tanaad\Shared\Ledger\CommandBus;
use Tanaad\Shared\Ledger\CommandResult;
use Tanaad\Shared\Ledger\Rejection;

/** Reads take the prototype's camelCase shape; writes accept it and map it to the snake_case event payload. */
final class LabelsController extends ApiController
{
    private const array CREATE_FIELDS = [
        'labelId' => 'label_id', 'kind' => 'kind', 'productId' => 'product_id', 'stageCode' => 'stage_code', 'machineId' => 'machine_id',
        'operatorId' => 'operator_id', 'shiftKind' => 'shift_kind', 'day' => 'day', 'qtyUnits' => 'qty_units', 'qtyKg' => 'qty_kg',
        'zoneId' => 'zone_id', 'parentIds' => 'parent_ids', 'parentCodes' => 'parent_codes', 'colour' => 'colour', 'grade' => 'grade', 'status' => 'status',
    ];

    public function __construct(
        private readonly CommandBus $bus,
        private readonly LabelsQuery $query,
        private readonly LabelsAuthorizer $authorizer,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $siteId = $this->readable($request, CurrentSite::resolve($request)->id);
        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(200, max(1, (int) $request->query('perPage', 50)));

        return new JsonResponse($this->query->list(
            $siteId, $this->opt($request, 'status'), $this->opt($request, 'kind'), $this->opt($request, 'day'), $page, $perPage,
        ));
    }

    public function stock(Request $request): JsonResponse
    {
        $siteId = $this->readable($request, CurrentSite::resolve($request)->id);

        return new JsonResponse(['stock' => $this->query->stock($siteId)]);
    }

    public function history(Request $request, string $code): JsonResponse
    {
        // Codes are unique per site: with ?site= take that site's label, otherwise the code must be unambiguous.
        $site = $this->opt($request, 'site');
        $siteId = $site === null ? null : DB::table('sites')->where('code', $site)->orWhere('id', $site)->value('id');
        $matches = Label::query()->where('code', $code)->when($site !== null, fn ($q) => $q->where('site_id', $siteId ?? ''))->limit(2)->get();
        if ($matches->count() > 1) {
            throw Rejection::invalidPayload(['site' => 'required: this code exists at more than one site']);
        }
        $label = $matches->first() ?? throw Rejection::notFound('Label', $code);
        $this->readable($request, $label->site_id);

        return new JsonResponse($this->query->history($label));
    }

    public function create(Request $request): JsonResponse
    {
        $siteId = CurrentSite::resolve($request)->id;
        $payload = [];
        foreach (self::CREATE_FIELDS as $in => $key) {
            if ($request->has($in)) {
                $payload[$key] = $request->input($in);
            } elseif ($request->has($key)) {
                $payload[$key] = $request->input($key);
            }
        }
        $result = $this->bus->dispatch(new CreateLabel($this->context($request, $siteId), $payload));

        return $this->answer($result, 'labels.created');
    }

    public function consume(Request $request, string $site, string $label): JsonResponse
    {
        $siteId = CurrentSite::resolve($request)->id;
        $key = \Illuminate\Support\Str::isUuid($label) ? 'label_id' : 'code';
        $result = $this->bus->dispatch(new ConsumeLabel($this->context($request, $siteId), [
            $key => $label,
            'consumed_by_stage' => $request->input('consumedByStage', $request->input('consumed_by_stage')),
            'consumed_ref' => $request->input('consumedRef', $request->input('consumed_ref')),
        ]));

        return $this->answer($result, 'labels.consumed');
    }

    private function opt(Request $request, string $key): ?string
    {
        $v = $request->query($key);

        return is_string($v) && $v !== '' ? $v : null;
    }

    private function readable(Request $request, string $siteId): string
    {
        $user = $request->user();
        if (! $user instanceof User || ! $this->authorizer->canRead($user->loadMissing('siteRoles'), $siteId)) {
            throw Rejection::forbidden('Only the Supervisor, Storekeeper, Site Manager of this site or group roles may read its labels.');
        }

        return $siteId;
    }

    private function answer(CommandResult $result, string $type): JsonResponse
    {
        foreach ($result->events as $event) {
            if ($event->type() === $type) {
                $id = $event->payload()['label_id'] ?? null;
                $label = Label::query()->whereKey(is_string($id) ? $id : '')->firstOrFail();

                return $this->created(['label' => LabelsQuery::shape($label), 'event_id' => $event->id()]);
            }
        }
        throw new \LogicException("No {$type} event in the result.");
    }
}
