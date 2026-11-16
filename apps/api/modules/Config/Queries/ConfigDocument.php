<?php

declare(strict_types=1);

namespace Tanaad\Config\Queries;

use Illuminate\Database\ConnectionInterface;
use Tanaad\Config\Models\Benchmark;
use Tanaad\Config\Models\CompanyProfile;
use Tanaad\Config\Models\FxRate;
use Tanaad\Config\Models\Machine;
use Tanaad\Config\Models\Price;
use Tanaad\Config\Models\Product;
use Tanaad\Config\Models\RefItem;
use Tanaad\Config\Models\Site;
use Tanaad\Config\Models\SiteSetting;
use Tanaad\Config\Models\Stage;
use Tanaad\Config\Models\Zone;

/** A site's configuration in the prototype's camelCase shape (packages/domain/src/types.ts). */
final class ConfigDocument
{
    public function __construct(private readonly ConnectionInterface $db) {}

    /** @return array<string, mixed> */
    public function forSite(Site $site): array
    {
        $stages = Stage::query()->where('site_id', $site->id)->whereNull('deleted_at')->orderBy('sequence')->get();
        $refs = RefItem::query()->where(fn ($q) => $q->where('site_id', $site->id)->orWhereNull('site_id'))
            ->whereNull('deleted_at')->orderBy('list')->orderBy('sort_order')->get();
        $products = Product::query()->with('prices')->where('site_id', $site->id)->whereNull('deleted_at')->orderBy('internal_name')->get();
        $machines = Machine::query()->where('site_id', $site->id)->whereNull('deleted_at')->orderBy('code')->get();
        $benchmarks = Benchmark::query()->where('site_id', $site->id)->whereNull('deleted_at')->get();
        $zones = Zone::query()->where('site_id', $site->id)->whereNull('deleted_at')->orderBy('name')->get();
        $settings = SiteSetting::query()->where('site_id', $site->id)->first();
        $profile = CompanyProfile::query()->where('site_id', $site->id)->first();

        $seq = max(
            (int) Stage::query()->where('site_id', $site->id)->max('seq'),
            (int) RefItem::query()->where(fn ($q) => $q->where('site_id', $site->id)->orWhereNull('site_id'))->max('seq'),
            (int) Product::query()->where('site_id', $site->id)->max('seq'),
            (int) Price::query()->where('site_id', $site->id)->max('seq'),
            (int) Machine::query()->where('site_id', $site->id)->max('seq'),
            (int) Benchmark::query()->where('site_id', $site->id)->max('seq'),
            (int) Zone::query()->where('site_id', $site->id)->max('seq'),
            (int) SiteSetting::query()->where('site_id', $site->id)->max('seq'),
            (int) CompanyProfile::query()->where('site_id', $site->id)->max('seq'),
            (int) FxRate::query()->max('seq'),
        );

        return [
            'site' => [
                'id' => $site->id, 'code' => $site->code, 'name' => $site->name, 'legalName' => $site->legal_name,
                'shortName' => $site->short_name, 'tagline' => $site->tagline, 'currencyPrimary' => $site->currency_primary,
                'shifts' => $site->shifts, 'closedDays' => $site->closed_days, 'machineWallTitle' => $site->machine_wall_title,
                'stages' => $stages->map(fn (Stage $s): array => self::stage($s))->values()->all(),
                'toBeConfirmed' => $site->to_be_confirmed,
            ],
            'settings' => $settings === null ? null : self::settings($settings),
            'refs' => $refs->map(fn (RefItem $r): array => self::ref($r))->values()->all(),
            'products' => $products->map(fn (Product $p): array => self::product($p))->values()->all(),
            'machines' => $machines->map(fn (Machine $m): array => self::machine($m))->values()->all(),
            'benchmarks' => $benchmarks->map(fn (Benchmark $b): array => self::benchmark($b))->values()->all(),
            'zones' => $zones->map(fn (Zone $z): array => self::zone($z))->values()->all(),
            'companyProfile' => $profile === null ? null : self::companyProfile($profile),
            'fx' => $this->latestFx('USD', 'UGX'),
            'numberSeries' => $this->numberSeries($site->id),
            'snapshot_seq' => $seq,
        ];
    }

    public function latestFx(string $base, string $quote): ?float
    {
        $rate = FxRate::query()->where(['base' => $base, 'quote' => $quote])->whereNull('deleted_at')
            ->orderByDesc('day')->orderByDesc('seq')->first();

        return $rate?->rate;
    }

    /** @return list<array{kind: string, prefix: string, pad: int, next: int}> */
    public function numberSeries(string $siteId, ?string $kind = null): array
    {
        $rows = $this->db->table('number_series')->where('site_id', $siteId)
            ->when($kind !== null, fn ($q) => $q->where('kind', $kind))->orderBy('kind')->get();
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['kind' => (string) $r->kind, 'prefix' => (string) $r->prefix, 'pad' => (int) $r->pad, 'next' => (int) $r->next];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public static function stage(Stage $s): array
    {
        return ['code' => $s->code, 'name' => $s->name, 'sequence' => $s->sequence, 'inputUnit' => $s->input_unit, 'outputUnit' => $s->output_unit, 'producesLabel' => $s->produces_label];
    }

    /** @return array<string, float> */
    public static function settings(SiteSetting $s): array
    {
        $out = [];
        foreach (SiteSetting::FIELDS as $key => $column) {
            $out[$key] = (float) $s->getAttribute($column);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public static function ref(RefItem $r): array
    {
        return [
            'id' => $r->id, 'code' => $r->code, 'list' => $r->list, 'siteId' => $r->site_id, 'label' => $r->label,
            'value' => $r->value, 'unit' => $r->unit, 'color' => $r->color, 'parentId' => $r->parent_id,
            'active' => $r->active, 'order' => $r->sort_order, 'system' => $r->system, 'example' => $r->example,
        ];
    }

    /** @return array<string, mixed> */
    public static function product(Product $p): array
    {
        return [
            'id' => $p->id, 'code' => $p->code, 'siteId' => $p->site_id, 'internalName' => $p->internal_name,
            'commercialName' => $p->commercial_name, 'kind' => $p->kind, 'bundlePieces' => $p->bundle_pieces,
            'stdBundleWeightKg' => $p->std_bundle_weight_kg, 'weightTolerancePct' => $p->weight_tolerance_pct,
            'weightStatus' => $p->weight_status, 'colour' => $p->colour, 'unit' => $p->unit,
            'thicknessMicron' => $p->thickness_micron, 'active' => $p->active,
            'prices' => $p->prices->map(fn (Price $x): array => self::price($x))->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public static function price(Price $x): array
    {
        return ['id' => $x->id, 'channel' => $x->channel, 'currency' => $x->currency, 'amount' => $x->amount, 'effectiveFrom' => $x->effective_from->toDateString()];
    }

    /** @return array<string, mixed> */
    public static function machine(Machine $m): array
    {
        return [
            'id' => $m->id, 'code' => $m->code, 'codeKey' => $m->code_key, 'siteId' => $m->site_id, 'kind' => $m->kind,
            'stageCode' => $m->stage_code, 'status' => $m->status, 'make' => $m->make, 'model' => $m->model,
            'commissioned' => $m->commissioned?->toDateString(), 'capacityKgPerDay' => $m->capacity_kg_per_day,
        ];
    }

    /** @return array<string, mixed> */
    public static function benchmark(Benchmark $b): array
    {
        return ['id' => $b->id, 'machineId' => $b->machine_id, 'productId' => $b->product_id, 'unitsPerShift' => $b->units_per_shift];
    }

    /** @return array<string, mixed> */
    public static function zone(Zone $z): array
    {
        return ['id' => $z->id, 'siteId' => $z->site_id, 'name' => $z->name];
    }

    /** @return array<string, mixed> */
    public static function companyProfile(CompanyProfile $c): array
    {
        return [
            'address' => $c->address, 'phone' => $c->phone, 'email' => $c->email, 'tin' => $c->tin,
            'bankDetails' => $c->bank_details, 'mobileMoney' => $c->mobile_money, 'vatRatePct' => $c->vat_rate_pct,
            'efrisConnected' => $c->efris_connected,
        ];
    }

    /** @return array<string, mixed> */
    public static function fxRate(FxRate $f): array
    {
        return ['id' => $f->id, 'day' => $f->day->toDateString(), 'base' => $f->base, 'quote' => $f->quote, 'rate' => $f->rate];
    }
}
