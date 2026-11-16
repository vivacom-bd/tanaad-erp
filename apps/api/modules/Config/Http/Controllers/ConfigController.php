<?php

declare(strict_types=1);

namespace Tanaad\Config\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Tanaad\Config\Commands\ChangeRefList;
use Tanaad\Config\Commands\DefineNumberSeries;
use Tanaad\Config\Commands\SaveMachine;
use Tanaad\Config\Commands\SaveProduct;
use Tanaad\Config\Commands\SaveZone;
use Tanaad\Config\Commands\SetBenchmark;
use Tanaad\Config\Commands\SetFxRate;
use Tanaad\Config\Commands\UpdateCompanyProfile;
use Tanaad\Config\Commands\UpdateSiteSettings;
use Tanaad\Config\Handlers\SaveMachineHandler;
use Tanaad\Config\Handlers\SaveProductHandler;
use Tanaad\Config\Models\Benchmark;
use Tanaad\Config\Models\CompanyProfile;
use Tanaad\Config\Models\FxRate;
use Tanaad\Config\Models\Machine;
use Tanaad\Config\Models\Product;
use Tanaad\Config\Models\RefItem;
use Tanaad\Config\Models\SiteSetting;
use Tanaad\Config\Models\Zone;
use Tanaad\Config\Queries\ConfigDocument;
use Tanaad\Config\Support\RefLists;
use Tanaad\Identity\Http\CurrentSite;
use Tanaad\Shared\Http\ApiController;
use Tanaad\Shared\Ledger\CommandBus;
use Tanaad\Shared\Ledger\CommandResult;

/**
 * Reads take the prototype's camelCase shape; writes accept it, map it to the snake_case
 * event payload and answer with the affected row in the read shape plus the event id.
 */
final class ConfigController extends ApiController
{
    public function __construct(private readonly CommandBus $bus, private readonly ConfigDocument $doc) {}

    public function show(Request $request): JsonResponse
    {
        return new JsonResponse($this->doc->forSite(CurrentSite::resolve($request)));
    }

    public function settings(Request $request): JsonResponse
    {
        $site = CurrentSite::resolve($request);
        $rules = [];
        foreach (array_keys(SiteSetting::FIELDS) as $key) {
            $rules[$key] = ['required', 'numeric'];
        }
        $data = $request->validate($rules);
        $payload = [];
        foreach (SiteSetting::FIELDS as $key => $column) {
            $payload[$column] = $data[$key];
        }
        $result = $this->bus->dispatch(new UpdateSiteSettings($this->context($request, $site->id), $payload));

        return $this->answer($result, ['settings' => ConfigDocument::settings(SiteSetting::query()->where('site_id', $site->id)->firstOrFail())]);
    }

    public function ref(Request $request): JsonResponse
    {
        $site = CurrentSite::resolve($request);
        $data = $request->validate([
            'id' => ['nullable', 'uuid'],
            'list' => ['required', Rule::in(RefLists::ALL)],
            'siteId' => ['nullable', 'uuid'],
            'label' => ['required', 'string', 'max:120'],
            'value' => ['nullable', 'numeric'],
            'unit' => ['nullable', 'string', 'max:32'],
            'color' => ['nullable', 'string', 'max:64'],
            'parentId' => ['nullable', 'string', 'max:64'],
            'active' => ['sometimes', 'boolean'],
            'order' => ['sometimes', 'integer', 'min:0'],
            'example' => ['sometimes', 'boolean'],
        ]);
        $payload = [
            'item_id' => $data['id'] ?? (string) Str::uuid7(),
            'list' => $data['list'],
            'label' => $data['label'],
            'value' => $data['value'] ?? null,
            'unit' => $data['unit'] ?? null,
            'color' => $data['color'] ?? null,
            'parent_id' => $data['parentId'] ?? null,
            'active' => (bool) ($data['active'] ?? true),
            'sort_order' => (int) ($data['order'] ?? 0),
            'example' => (bool) ($data['example'] ?? false),
        ];
        if ($request->has('siteId')) {
            $payload['site_id'] = $data['siteId'] ?? null;
        }
        $result = $this->bus->dispatch(new ChangeRefList($this->context($request, $site->id), $payload));

        return $this->answer($result, ['ref' => ConfigDocument::ref(RefItem::query()->findOrFail($this->id($result, 'item_id')))]);
    }

    public function product(Request $request): JsonResponse
    {
        $site = CurrentSite::resolve($request);
        $data = $request->validate([
            'id' => ['nullable', 'uuid'],
            'code' => ['nullable', 'string', 'max:64'],
            'internalName' => ['required', 'string', 'max:255'],
            'commercialName' => ['nullable', 'string', 'max:255'],
            'kind' => ['required', Rule::in(SaveProductHandler::KINDS)],
            'bundlePieces' => ['required', 'integer', 'min:1'],
            'stdBundleWeightKg' => ['required', 'numeric', 'min:0'],
            'weightTolerancePct' => ['required', 'numeric', 'min:0'],
            'weightStatus' => ['required', Rule::in(['measured', 'to_be_measured'])],
            'colour' => ['nullable', 'string', 'max:64'],
            'unit' => ['nullable', 'string', 'max:32'],
            'thicknessMicron' => ['nullable', 'integer', 'min:0'],
            'active' => ['sometimes', 'boolean'],
            'prices' => ['sometimes', 'array'],
            'prices.*.channel' => ['required', Rule::in(SaveProductHandler::CHANNELS)],
            'prices.*.currency' => ['required', 'string', 'size:3'],
            'prices.*.amount' => ['required', 'numeric', 'min:0'],
            'prices.*.effectiveFrom' => ['required', 'date_format:Y-m-d'],
        ]);
        /** @var list<array{channel: string, currency: string, amount: int|float|string, effectiveFrom: string}> $prices */
        $prices = $data['prices'] ?? [];
        $payload = [
            'product_id' => $data['id'] ?? (string) Str::uuid7(),
            'code' => $data['code'] ?? null,
            'internal_name' => $data['internalName'],
            'commercial_name' => $data['commercialName'] ?? null,
            'kind' => $data['kind'],
            'bundle_pieces' => $data['bundlePieces'],
            'std_bundle_weight_kg' => $data['stdBundleWeightKg'],
            'weight_tolerance_pct' => $data['weightTolerancePct'],
            'weight_status' => $data['weightStatus'],
            'colour' => $data['colour'] ?? null,
            'unit' => $data['unit'] ?? null,
            'thickness_micron' => $data['thicknessMicron'] ?? null,
            'active' => (bool) ($data['active'] ?? true),
            'prices' => array_map(fn (array $x): array => [
                'channel' => $x['channel'], 'currency' => $x['currency'], 'amount' => $x['amount'], 'effective_from' => $x['effectiveFrom'],
            ], $prices),
        ];
        $result = $this->bus->dispatch(new SaveProduct($this->context($request, $site->id), $payload));

        return $this->answer($result, ['product' => ConfigDocument::product(Product::query()->with('prices')->findOrFail($this->id($result, 'product_id')))]);
    }

    public function machine(Request $request): JsonResponse
    {
        $site = CurrentSite::resolve($request);
        $data = $request->validate([
            'id' => ['nullable', 'uuid'],
            'codeKey' => ['nullable', 'string', 'max:64'],
            'code' => ['required', 'string', 'max:64'],
            'kind' => ['required', 'string', 'max:32'],
            'stageCode' => ['required', 'string', 'max:32'],
            'status' => ['required', Rule::in(SaveMachineHandler::STATUSES)],
            'make' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'commissioned' => ['nullable', 'date_format:Y-m-d'],
            'capacityKgPerDay' => ['nullable', 'numeric', 'min:0'],
        ]);
        $result = $this->bus->dispatch(new SaveMachine($this->context($request, $site->id), [
            'machine_id' => $data['id'] ?? (string) Str::uuid7(),
            'code_key' => $data['codeKey'] ?? null,
            'code' => $data['code'],
            'kind' => $data['kind'],
            'stage_code' => $data['stageCode'],
            'status' => $data['status'],
            'make' => $data['make'] ?? null,
            'model' => $data['model'] ?? null,
            'commissioned' => $data['commissioned'] ?? null,
            'capacity_kg_per_day' => $data['capacityKgPerDay'] ?? null,
        ]));

        return $this->answer($result, ['machine' => ConfigDocument::machine(Machine::query()->findOrFail($this->id($result, 'machine_id')))]);
    }

    public function benchmark(Request $request): JsonResponse
    {
        $site = CurrentSite::resolve($request);
        $data = $request->validate([
            'machineId' => ['required', 'uuid'],
            'productId' => ['required', 'uuid'],
            'unitsPerShift' => ['required', 'numeric', 'min:0'],
        ]);
        $result = $this->bus->dispatch(new SetBenchmark($this->context($request, $site->id), [
            'machine_id' => $data['machineId'], 'product_id' => $data['productId'], 'units_per_shift' => $data['unitsPerShift'],
        ]));

        return $this->answer($result, ['benchmark' => ConfigDocument::benchmark(Benchmark::query()->findOrFail($this->id($result, 'benchmark_id')))]);
    }

    public function zone(Request $request): JsonResponse
    {
        $site = CurrentSite::resolve($request);
        $data = $request->validate(['id' => ['nullable', 'uuid'], 'name' => ['required', 'string', 'max:120']]);
        $result = $this->bus->dispatch(new SaveZone($this->context($request, $site->id), [
            'zone_id' => $data['id'] ?? (string) Str::uuid7(), 'name' => $data['name'],
        ]));

        return $this->answer($result, ['zone' => ConfigDocument::zone(Zone::query()->findOrFail($this->id($result, 'zone_id')))]);
    }

    public function companyProfile(Request $request): JsonResponse
    {
        $site = CurrentSite::resolve($request);
        $data = $request->validate([
            'address' => ['nullable', 'string', 'max:2000'],
            'phone' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'tin' => ['nullable', 'string', 'max:255'],
            'bankDetails' => ['nullable', 'string', 'max:2000'],
            'mobileMoney' => ['nullable', 'string', 'max:255'],
            'vatRatePct' => ['required', 'numeric', 'min:0', 'max:100'],
            'efrisConnected' => ['sometimes', 'boolean'],
        ]);
        $result = $this->bus->dispatch(new UpdateCompanyProfile($this->context($request, $site->id), [
            'address' => $data['address'] ?? '', 'phone' => $data['phone'] ?? '', 'email' => $data['email'] ?? '',
            'tin' => $data['tin'] ?? '', 'bank_details' => $data['bankDetails'] ?? '', 'mobile_money' => $data['mobileMoney'] ?? '',
            'vat_rate_pct' => $data['vatRatePct'], 'efris_connected' => (bool) ($data['efrisConnected'] ?? false),
        ]));

        return $this->answer($result, ['companyProfile' => ConfigDocument::companyProfile(CompanyProfile::query()->where('site_id', $site->id)->firstOrFail())]);
    }

    public function numberSeries(Request $request): JsonResponse
    {
        $site = CurrentSite::resolve($request);
        $data = $request->validate([
            'kind' => ['required', 'string', 'max:32', 'regex:/^[a-z_]+$/'],
            'prefix' => ['required', 'string', 'max:16'],
            'pad' => ['sometimes', 'integer', 'min:1', 'max:12'],
            'next' => ['sometimes', 'integer', 'min:1'],
        ]);
        $result = $this->bus->dispatch(new DefineNumberSeries($this->context($request, $site->id), [
            'kind' => $data['kind'], 'prefix' => $data['prefix'], 'pad' => (int) ($data['pad'] ?? 5), 'next' => (int) ($data['next'] ?? 1),
        ]));

        return $this->answer($result, ['numberSeries' => $this->doc->numberSeries($site->id, $data['kind'])[0] ?? null]);
    }

    public function fx(Request $request): JsonResponse
    {
        $data = $request->validate([
            'day' => ['required', 'date_format:Y-m-d'],
            'base' => ['sometimes', 'string', 'size:3'],
            'quote' => ['sometimes', 'string', 'size:3'],
            'rate' => ['required', 'numeric', 'gt:0'],
        ]);
        $payload = ['day' => $data['day'], 'base' => $data['base'] ?? 'USD', 'quote' => $data['quote'] ?? 'UGX', 'rate' => $data['rate']];
        $result = $this->bus->dispatch(new SetFxRate($this->context($request, null), $payload));

        return $this->answer($result, ['fx' => ConfigDocument::fxRate(FxRate::query()->findOrFail($this->id($result, 'fx_id')))]);
    }

    /** @param array<string, mixed> $body */
    private function answer(CommandResult $result, array $body): JsonResponse
    {
        return $this->created([...$body, 'event_id' => $result->primary()?->id()]);
    }

    private function id(CommandResult $result, string $key): string
    {
        $id = $result->primary()?->payload()[$key] ?? null;

        return is_string($id) ? $id : '';
    }
}
