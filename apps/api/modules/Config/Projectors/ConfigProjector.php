<?php

declare(strict_types=1);

namespace Tanaad\Config\Projectors;

use Illuminate\Database\ConnectionInterface;
use Ramsey\Uuid\Uuid;
use Tanaad\Shared\Ledger\NumberSeries;
use Tanaad\Shared\Ledger\Projector;
use Tanaad\Shared\Ledger\StoredEvent;

/** The only writer of the Config read tables. */
final class ConfigProjector implements Projector
{
    private const array TABLES = ['benchmarks', 'prices', 'machines', 'products', 'stages', 'zones', 'ref_items', 'fx_rates', 'company_profiles', 'site_settings'];

    public function __construct(private readonly ConnectionInterface $db, private readonly NumberSeries $series) {}

    public function handles(): array
    {
        return [
            'config.ref_list_changed', 'config.site_settings_changed', 'config.product_saved', 'config.machine_saved',
            'config.benchmark_set', 'config.zone_saved', 'config.fx_rate_set', 'config.company_profile_changed',
            'config.price_set', 'config.number_series_defined', 'config.stage_saved',
        ];
    }

    public function apply(StoredEvent $event): void
    {
        $p = $event->payload();
        $site = $event->siteId();
        $meta = ['seq' => $event->seq, 'deleted_at' => null, 'created_at' => $event->receivedAt, 'updated_at' => $event->receivedAt];

        match ($event->type()) {
            'config.ref_list_changed' => $this->upsert('ref_items', ['id'], [
                'id' => $p['item_id'], 'site_id' => $p['site_id'], 'list' => $p['list'], 'label' => $p['label'], 'value' => $p['value'],
                'unit' => $p['unit'], 'color' => $p['color'], 'parent_id' => $p['parent_id'], 'active' => $p['active'],
                'sort_order' => $p['sort_order'], 'system' => $p['system'], 'example' => $p['example'], 'code' => $p['code'], ...$meta,
            ]),
            'config.site_settings_changed' => $this->upsert('site_settings', ['site_id'], ['id' => Uuid::uuid5(Uuid::NAMESPACE_URL, "tanaad:site_settings:{$site}")->toString(), 'site_id' => $site, ...$p, ...$meta]),
            'config.company_profile_changed' => $this->upsert('company_profiles', ['site_id'], ['id' => Uuid::uuid5(Uuid::NAMESPACE_URL, "tanaad:company_profiles:{$site}")->toString(), 'site_id' => $site, ...$p, ...$meta]),
            'config.product_saved' => $this->saveProduct($p, $site, $meta),
            'config.price_set' => $this->db->table('prices')->insert($this->price($p, (string) $p['product_id'], $site, $meta)),
            'config.machine_saved' => $this->upsert('machines', ['id'], [
                'id' => $p['machine_id'], 'code_key' => $p['code_key'], 'site_id' => $site, 'code' => $p['code'], 'kind' => $p['kind'],
                'stage_code' => $p['stage_code'], 'status' => $p['status'], 'make' => $p['make'], 'model' => $p['model'],
                'commissioned' => $p['commissioned'], 'capacity_kg_per_day' => $p['capacity_kg_per_day'], ...$meta,
            ]),
            'config.benchmark_set' => $this->upsert('benchmarks', ['machine_id', 'product_id'], [
                'id' => $p['benchmark_id'], 'site_id' => $site, 'machine_id' => $p['machine_id'], 'product_id' => $p['product_id'],
                'units_per_shift' => $p['units_per_shift'], ...$meta,
            ]),
            'config.stage_saved' => $this->upsert('stages', ['site_id', 'code'], [
                'id' => $p['stage_id'], 'site_id' => $site, 'code' => $p['code'], 'name' => $p['name'], 'sequence' => $p['sequence'],
                'input_unit' => $p['input_unit'], 'output_unit' => $p['output_unit'], 'produces_label' => $p['produces_label'], ...$meta,
            ]),
            'config.zone_saved' => $this->upsert('zones', ['id'], ['id' => $p['zone_id'], 'site_id' => $site, 'name' => $p['name'], ...$meta]),
            'config.fx_rate_set' => $this->upsert('fx_rates', ['day', 'base', 'quote'], [
                'id' => $p['fx_id'], 'site_id' => null, 'day' => $p['day'], 'base' => $p['base'], 'quote' => $p['quote'], 'rate' => $p['rate'], ...$meta,
            ]),
            'config.number_series_defined' => $site === null ? null
                : $this->series->define($site, (string) $p['kind'], (string) $p['prefix'], (int) $p['pad'], (int) $p['next']),
            default => null,
        };
    }

    public function reset(): void
    {
        foreach (self::TABLES as $table) {
            $this->db->table($table)->delete();
        }
    }

    /**
     * @param  non-empty-list<non-empty-string>  $uniqueBy
     * @param  array<string, mixed>  $row
     */
    private function upsert(string $table, array $uniqueBy, array $row): void
    {
        $update = array_values(array_diff(array_keys($row), ['id', 'created_at', ...$uniqueBy]));
        $this->db->table($table)->upsert([$row], $uniqueBy, $update);
    }

    /**
     * @param  array<string, mixed>  $p
     * @param  array<string, mixed>  $meta
     */
    private function saveProduct(array $p, ?string $site, array $meta): void
    {
        $id = (string) $p['product_id'];
        $this->upsert('products', ['id'], [
            'id' => $id, 'code' => $p['code'], 'site_id' => $site, 'internal_name' => $p['internal_name'],
            'commercial_name' => $p['commercial_name'], 'kind' => $p['kind'], 'bundle_pieces' => $p['bundle_pieces'],
            'std_bundle_weight_kg' => $p['std_bundle_weight_kg'], 'weight_tolerance_pct' => $p['weight_tolerance_pct'],
            'weight_status' => $p['weight_status'], 'colour' => $p['colour'], 'unit' => $p['unit'],
            'thickness_micron' => $p['thickness_micron'], 'active' => $p['active'], ...$meta,
        ]);
        $this->db->table('prices')->where('product_id', $id)->delete();
        /** @var list<array<string, mixed>> $prices */
        $prices = is_array($p['prices'] ?? null) ? $p['prices'] : [];
        if ($prices !== []) {
            $this->db->table('prices')->insert(array_map(fn (array $x): array => $this->price($x, $id, $site, $meta), $prices));
        }
    }

    /**
     * @param  array<string, mixed>  $p
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function price(array $p, string $productId, ?string $site, array $meta): array
    {
        return [
            'id' => $p['price_id'], 'site_id' => $site, 'product_id' => $productId, 'channel' => $p['channel'],
            'currency' => $p['currency'], 'amount' => $p['amount'], 'effective_from' => $p['effective_from'], ...$meta,
        ];
    }
}
