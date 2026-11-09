<?php

declare(strict_types=1);

namespace Tanaad\Config\Database\Seeders;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Tanaad\Config\Commands\ChangeRefList;
use Tanaad\Config\Commands\DefineNumberSeries;
use Tanaad\Config\Commands\SaveMachine;
use Tanaad\Config\Commands\SaveProduct;
use Tanaad\Config\Commands\SaveStage;
use Tanaad\Config\Commands\SetBenchmark;
use Tanaad\Config\Commands\SetFxRate;
use Tanaad\Config\Commands\UpdateCompanyProfile;
use Tanaad\Config\Commands\UpdateSiteSettings;
use Tanaad\Config\Models\Benchmark;
use Tanaad\Config\Models\CompanyProfile;
use Tanaad\Config\Models\FxRate;
use Tanaad\Config\Models\Machine;
use Tanaad\Config\Models\Product;
use Tanaad\Config\Models\RefItem;
use Tanaad\Config\Models\Site;
use Tanaad\Config\Models\SiteSetting;
use Tanaad\Config\Models\Stage;
use Tanaad\Identity\Models\User;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\CommandBus;
use Tanaad\Shared\Ledger\CommandContext;

/**
 * Starting master data, mirroring apps/web/src/data/seed/masterData.ts, build.ts and
 * config/sites/*.ts. Every row is a config.* event dispatched as u_admin, so a replay
 * rebuilds it and snapshot deltas include it. Idempotent: a command is skipped when
 * its row already exists. Run after SitesSeeder and DemoUsersSeeder.
 */
final class ConfigSeeder extends Seeder
{
    /** @var array<string, string> site code => id */
    private array $sites = [];

    private int $n = 0;

    /** @var array<string, int> "list|site" => next order */
    private array $order = [];

    private CommandBus $bus;

    private string $adminId;

    public function run(CommandBus $bus, ConnectionInterface $db): void
    {
        $this->bus = $bus;
        $this->adminId = (string) User::query()->where('code', 'u_admin')->valueOrFail('id');
        $this->sites = Site::query()->pluck('id', 'code')->map(fn ($id): string => (string) $id)->all();
        $this->stages();
        $this->refs();
        $this->settingsAndProfiles();
        $products = $this->products();
        $this->machinesAndBenchmarks($products);
        $day = CarbonImmutable::now()->toDateString();
        if (! FxRate::query()->where(['day' => $day, 'base' => 'USD', 'quote' => 'UGX'])->exists()) {
            $this->send(new SetFxRate($this->ctx(null), ['day' => $day, 'base' => 'USD', 'quote' => 'UGX', 'rate' => 3700]));
        }
        foreach (['mats' => 1, 'bags' => 4817, 'recycling' => 1] as $code => $dnStart) {
            foreach ([['delivery_note', 'DN', $dnStart], ['invoice', 'INV', 1], ['voucher', 'PV', 1], ['receipt', 'RC', 1]] as [$kind, $prefix, $next]) {
                if ($db->table('number_series')->where(['site_id' => $this->sites[$code], 'kind' => $kind])->exists()) {
                    continue;
                }
                $this->send(new DefineNumberSeries($this->ctx($code), ['kind' => $kind, 'prefix' => $prefix, 'pad' => 5, 'next' => $next]));
            }
        }
    }

    private function ctx(?string $site): CommandContext
    {
        return new CommandContext($site === null ? null : $this->sites[$site], $this->adminId, null, CarbonImmutable::parse('2026-11-01 08:00:00', 'Africa/Kampala'));
    }

    private function send(Command $command): void
    {
        $this->bus->dispatch($command);
    }

    private function stages(): void
    {
        $stages = [
            'mats' => [['raw', 'Raw materials', 'bags', 'kg', null], ['mixer', 'Mixer', 'kg', 'kg', 'batch'], ['extrude', 'Extruders', 'kg', 'kg', 'rack'], ['dry', 'Drying racks', 'kg', 'kg', null], ['weave', 'Weaving', 'kg', 'units', null], ['finish', 'Hand finishing', 'units', 'bundles', 'bundle'], ['store', 'Store & dispatch', 'units', 'bundles', null]],
            'bags' => [['raw', 'Raw material issue', 'bags', 'kg', null], ['extrude', 'Film extrusion', 'kg', 'rolls', 'roll'], ['convert', 'Cutting, sealing & packing', 'kg', 'packs', 'pack'], ['store', 'Store & dispatch', 'units', 'packs', null]],
            'recycling' => [['intake', 'Collector intake', 'kg', 'kg', null], ['wash', 'Sorting & washing', 'kg', 'kg', null], ['pelletise', 'Pelletising', 'kg', 'sacks', 'sack'], ['store', 'Pellet store', 'kg', 'sacks', null]],
        ];
        foreach ($stages as $site => $list) {
            foreach ($list as $i => [$code, $name, $in, $out, $label]) {
                if (Stage::query()->where(['site_id' => $this->sites[$site], 'code' => $code])->exists()) {
                    continue;
                }
                $this->send(new SaveStage($this->ctx($site), ['code' => $code, 'name' => $name, 'sequence' => $i + 1, 'input_unit' => $in, 'output_unit' => $out, 'produces_label' => $label]));
            }
        }
    }

    /** @param array<string, mixed> $o */
    private function add(string $list, string $label, array $o = []): void
    {
        $site = $o['site'] ?? null;
        $site = is_string($site) ? $site : null;
        $key = $list.'|'.($site ?? '');
        $order = $this->order[$key] ?? 0;
        $this->order[$key] = $order + 1;
        $code = is_string($o['id'] ?? null) ? $o['id'] : 'ref_'.$list.'_'.base_convert((string) $this->n++, 10, 36);
        if (RefItem::query()->where('code', $code)->exists()) {
            return;
        }
        $this->send(new ChangeRefList($this->ctx($site), [
            'item_id' => (string) Str::uuid7(), 'list' => $list, 'site_id' => $site === null ? null : $this->sites[$site], 'label' => $label,
            'value' => $o['value'] ?? null, 'unit' => $o['unit'] ?? null, 'color' => $o['color'] ?? null, 'parent_id' => $o['parent'] ?? null,
            'active' => true, 'sort_order' => $order, 'system' => (bool) ($o['system'] ?? false), 'example' => (bool) ($o['example'] ?? false), 'code' => $code,
        ]));
    }

    private function refs(): void
    {
        foreach ([['kg', false], ['tonne', false], ['piece', false], ['bundle', false], ['roll', false], ['pack', true], ['sack', true], ['bag', false], ['batch', false]] as [$label, $ex]) {
            $this->add('unit', $label, ['example' => $ex]);
        }
        foreach (['wages' => 'Wages', 'materials' => 'Materials', 'spares' => 'Spares', 'utilities' => 'Utilities', 'transport' => 'Transport', 'food' => 'Food', 'other' => 'Other'] as $id => $label) {
            $this->add('expense_category', $label, ['id' => $id, 'system' => true, 'color' => "var(--cat-{$id})"]);
        }
        $perSite = [
            'mats' => [
                'sectors' => ['Mixer', 'Extrusion', 'Weaving', 'Finishing', 'Loading', 'Store', 'Maintenance', 'Office'],
                'downtime' => ['Umeme Grid Outage / Gen Switch', 'Warp Bobbin Creel Change', 'Warp / Weft Thread Snap', 'Heater Band Element Burnout', 'Mechanical Loom Jam', 'Operator Lunch / Roll-Call', 'Other'],
                'subs' => ['spares' => ['Belts', 'Bearings', 'Heater bands', 'Needles', 'Compressor oil', 'Electrical'], 'transport' => ['Boda', 'Lorry hire', 'Fuel'], 'utilities' => ['Electricity', 'Water', 'Generator fuel']],
            ],
            'bags' => [
                'sectors' => ['Extrusion', 'Conversion', 'Packing', 'Store', 'Maintenance', 'Office'],
                'downtime' => ['Umeme Grid Outage / Gen Switch', 'Extruder Screen Pack Clog', 'Die Bubble Collapse / Gauge Drift', 'Sealing Wire Element Break', 'Virgin Resin Feed Interruption', 'Changeover & Roll Doffing', 'Other'],
                'subs' => ['spares' => ['Heater bands', 'Screens', 'Blades', 'Sealing wire', 'Electrical'], 'transport' => ['Boda', 'Lorry hire', 'Fuel']],
            ],
            'recycling' => [
                'sectors' => ['Intake', 'Sorting', 'Washing', 'Pelletising', 'Bagging', 'Maintenance', 'Office'],
                'downtime' => ['Umeme Grid Outage / Gen Switch', 'Granulator Blade Dullness / Jam', 'Wash Line Water Pump Clog', 'Screen Pack Mesh Contamination', 'Melt Pelletizer Strand Break', 'Collector Feedstock Shortage', 'Other'],
                'subs' => ['spares' => ['Screens', 'Blades', 'Bearings', 'Heater bands', 'Pump parts'], 'utilities' => ['Electricity', 'Water', 'Generator fuel']],
            ],
        ];
        foreach ($perSite as $site => $c) {
            foreach ($c['sectors'] as $x) {
                $this->add('sector', $x, ['site' => $site]);
            }
            foreach ($c['downtime'] as $x) {
                $this->add('downtime_reason', $x, ['site' => $site, 'example' => true]);
            }
            foreach ($c['subs'] as $cat => $subs) {
                foreach ($subs as $x) {
                    $this->add('expense_subcategory', $x, ['site' => $site, 'parent' => $cat, 'example' => true]);
                }
            }
            foreach (['Belt snapped', 'Bearing seized', 'Heater band failed', 'Power surge', 'Blockage'] as $x) {
                $this->add('breakdown_cause', $x, ['site' => $site, 'example' => true]);
            }
            $this->add('quick_expense', 'Boda', ['site' => $site, 'parent' => 'transport']);
            $this->add('quick_expense', 'Staff lunch', ['site' => $site, 'parent' => 'food']);
            $this->add('quick_expense', 'Generator fuel', ['site' => $site, 'parent' => 'utilities', 'example' => true]);
            foreach ([7, 15, 30] as $d) {
                $this->add('contract_term', "{$d} days", ['site' => $site, 'value' => $d, 'unit' => 'days']);
            }
            foreach ([12000, 13000, 15000] as $r) {
                $this->add('daily_rate', 'UGX '.number_format($r), ['site' => $site, 'value' => $r, 'unit' => 'UGX/day', 'example' => true]);
            }
            foreach (['UBK 442X', 'UAX 918L', 'UBE 207T'] as $v) {
                $this->add('vehicle', $v, ['site' => $site, 'example' => true]);
            }
        }
        foreach ([50000, 20000, 10000, 5000, 2000, 1000, 500] as $d) {
            $this->add('denomination', 'UGX '.number_format($d), ['value' => $d, 'unit' => 'UGX']);
        }
        foreach (['Uganda', 'Rwanda', 'South Sudan'] as $m) {
            $this->add('market', $m);
        }
        $this->add('payment_method', 'Cash');
        $this->add('payment_method', 'MTN Mobile Money');
        $this->add('payment_method', 'Airtel Money');
        $this->add('payment_method', 'Bank transfer', ['example' => true]);
        foreach ([30, 45] as $d) {
            $this->add('credit_term', "{$d} days", ['value' => $d, 'unit' => 'days', 'example' => true]);
        }

        $this->add('material', 'Pellets, local', ['site' => 'mats', 'value' => 40, 'unit' => 'kg/bag']);
        $this->add('material', 'Yarn (Egypt)', ['site' => 'mats', 'value' => 25, 'unit' => 'kg/bag', 'example' => true]);
        $this->add('material', 'Colour masterbatch', ['site' => 'mats', 'value' => 5, 'unit' => 'kg/batch']);
        $this->add('pellet_source', 'Local pellets, 40 kg bags', ['site' => 'mats']);
        $this->add('pellet_source', 'Recycled black pellet', ['site' => 'mats', 'example' => true]);
        foreach ([['Blue', '#2F6B8A'], ['Green', '#5B8C5A'], ['Red', '#8a2f4f'], ['Purple', '#7A4F8A'], ['Cream', '#c9b58a']] as [$label, $color]) {
            $this->add('colour', $label, ['site' => 'mats', 'color' => $color, 'example' => true]);
        }
        foreach (['Edge trim', 'Faulty string', 'Loom waste'] as $x) {
            $this->add('waste_source', $x, ['site' => 'mats', 'example' => $x === 'Loom waste']);
        }

        foreach (['Size 1', 'Size 2', 'Size 3', 'Size 4', 'Size 5'] as $x) {
            $this->add('bag_size', $x, ['site' => 'bags', 'example' => true]);
        }
        $this->add('bag_colour', 'Black', ['site' => 'bags', 'color' => '#1d272b']);
        $this->add('bag_colour', 'White', ['site' => 'bags', 'color' => '#d9d4cb']);
        $this->add('brand', 'Faras', ['site' => 'bags']);
        foreach (['Resin', 'Black masterbatch', 'White masterbatch'] as $x) {
            $this->add('material', $x, ['site' => 'bags', 'value' => 25, 'unit' => 'kg/bag', 'example' => true]);
        }
        $this->add('waste_source', 'Film scrap', ['site' => 'bags']);

        foreach (['Natural', 'Mixed colour', 'Black'] as $x) {
            $this->add('pellet_grade', $x, ['site' => 'recycling', 'example' => true]);
        }
        foreach ([0, 4, 6, 8, 10, 12] as $d) {
            $this->add('deduction_option', "{$d}%", ['site' => 'recycling', 'value' => $d, 'unit' => '%', 'example' => true]);
        }
        foreach ([850, 900, 950] as $p) {
            $this->add('price_option', "UGX {$p}/kg", ['site' => 'recycling', 'value' => $p, 'unit' => 'UGX/kg', 'example' => true]);
        }
        $this->add('material', 'HDPE scrap', ['site' => 'recycling', 'unit' => 'kg']);
        $this->add('material', 'Pellet sack', ['site' => 'recycling', 'value' => 25, 'unit' => 'kg/sack', 'example' => true]);
    }

    private function settingsAndProfiles(): void
    {
        foreach (['mats' => [2, 3900], 'bags' => [2, 5200], 'recycling' => [12, 950]] as $code => [$massGap, $materialCost]) {
            if (! SiteSetting::query()->where('site_id', $this->sites[$code])->exists()) {
                $this->send(new UpdateSiteSettings($this->ctx($code), [
                    'gate_tolerance_pct' => 2, 'mass_gap_pct' => $massGap, 'cash_variance' => 20000, 'benchmark_pct' => 80,
                    'expense_approval_limit' => 500000, 'archive_after_absent_days' => 5, 'contract_warning_days' => 3,
                    'standard_day_hours' => 8, 'month_working_days' => 26, 'downtime_reason_after_min' => 60, 'material_cost_per_kg' => $materialCost,
                ]));
            }
            // Blank until the client provides it; never invented.
            if (! CompanyProfile::query()->where('site_id', $this->sites[$code])->exists()) {
                $this->send(new UpdateCompanyProfile($this->ctx($code), [
                    'address' => '', 'phone' => '', 'email' => '', 'tin' => '', 'bank_details' => '', 'mobile_money' => '', 'vat_rate_pct' => 0, 'efris_connected' => false,
                ]));
            }
        }
    }

    /** @return array<string, string> product code => id */
    private function products(): array
    {
        $local = fn (string $cur, float $amt): array => ['local', $cur, $amt];
        $export = fn (float $amt): array => ['export', 'USD', $amt];
        $rows = [
            ['p_rihlaat', 'mats', 'Rihlaat', 'bundle', 'mat', 20, 38.0, 2, 'to_be_measured', '#2F6B8A', [$export(3.5), $local('UGX', 15000)]],
            ['p_sajaada_l', 'mats', 'Sajaada light', 'bundle', 'mat', 10, 27.0, 2, 'to_be_measured', '#5B8C5A', [$export(5.5), $local('UGX', 25000)]],
            ['p_sajaada_h', 'mats', 'Sajaada heavy', 'bundle', 'mat', 10, 34.0, 2, 'to_be_measured', '#8A5A2F', [$export(7.0), $local('USD', 7.5)]],
            ['p_domona', 'mats', 'Domona', 'bundle', 'mat', 6, 25.2, 2, 'to_be_measured', '#7A4F8A', [$export(7.0), $local('USD', 7.5)]],
            ['p_27m', 'mats', '2.7m', 'bundle', 'mat', 3, 24.0, 2, 'to_be_measured', '#B5631E', [$export(16.0), $local('UGX', 70000)]],
            ['p_black_pellet', 'mats', 'Recycled black pellet', null, 'pellet', 1, 50, 1, 'measured', null, [$local('UGX', 2200)]],
        ];
        foreach (['Small', 'Medium', 'Large', 'X-Large', 'Jumbo'] as $i => $size) {
            foreach (['Black', 'White'] as $col) {
                $rows[] = ['p_bag_'.strtolower(str_replace('-', '', $size)).'_'.strtolower($col), 'bags', 'Size '.($i + 1)." {$col}", 'pack', 'bag_pack', 100,
                    [1.8, 2.6, 3.4, 4.5, 6.0][$i], 3, 'to_be_measured', $col === 'Black' ? '#1d272b' : '#c9c4b8', [$local('UGX', [9000, 13000, 17000, 23000, 32000][$i])]];
            }
        }
        $rows[] = ['p_roll_faras', 'bags', 'Faras', 'roll', 'roll', 1, 60, 10, 'measured', null, []];
        $rows[] = ['p_roll_nuur', 'bags', 'Roll type 2 (example)', 'roll', 'roll', 1, 60, 10, 'measured', null, []];
        $rows[] = ['p_hdpe_nat', 'recycling', 'HDPE pellet · natural', null, 'pellet', 1, 25, 1, 'to_be_measured', '#d9d4cb', [$local('UGX', 3800)]];
        $rows[] = ['p_hdpe_mixed', 'recycling', 'HDPE pellet · mixed colour', null, 'pellet', 1, 25, 1, 'to_be_measured', '#6b7f86', [$local('UGX', 3200)]];
        $rows[] = ['p_hdpe_black', 'recycling', 'HDPE pellet · black', null, 'pellet', 1, 25, 1, 'to_be_measured', '#1d272b', [$local('UGX', 2900)]];

        $ids = [];
        foreach ($rows as [$code, $site, $name, $unit, $kind, $pieces, $weight, $tol, $status, $colour, $prices]) {
            $id = Product::query()->where('code', $code)->value('id');
            if (! is_string($id)) {
                $id = (string) Str::uuid7();
                $this->send(new SaveProduct($this->ctx($site), [
                    'product_id' => $id, 'code' => $code, 'internal_name' => $name, 'unit' => $unit, 'kind' => $kind, 'bundle_pieces' => $pieces,
                    'std_bundle_weight_kg' => $weight, 'weight_tolerance_pct' => $tol, 'weight_status' => $status, 'colour' => $colour, 'active' => true,
                    'prices' => array_map(fn (array $x): array => ['channel' => $x[0], 'currency' => $x[1], 'amount' => $x[2], 'effective_from' => '2026-01-01'], $prices),
                ]));
            }
            $ids[$code] = $id;
        }

        return $ids;
    }

    /** @param array<string, string> $products */
    private function machinesAndBenchmarks(array $products): void
    {
        $m = [['m_mixer', 'mats', 'Mixer', 'mixer', 'mixer', 'active', 'Lushan', 'High-Speed Batch Mixer 500L', null]];
        for ($i = 1; $i <= 14; $i++) {
            $m[] = ["m_ext{$i}", 'mats', "Extruder {$i}", 'extruder', 'extrude', 'active', 'Lushan', '65mm PP Flat Film Die Extruder', null];
        }
        for ($i = 1; $i <= 21; $i++) {
            $even = $i % 2 === 0;
            $m[] = ["m_loom{$i}", 'mats', "Loom {$i}", 'loom', 'weave', $i <= 15 ? 'active' : 'arriving', $even ? 'Starlinger' : 'Hengli', $even ? 'SL-6 Circular Loom (120 rpm)' : '6-Shuttle Heavy Weave Loom', null];
        }
        $m[] = ['m_crusher', 'mats', 'Crusher', 'crusher', 'store', 'active', 'Rapid', 'Granulator 400kg/h', null];
        foreach (str_split('ABCDEFGH') as $l) {
            $m[] = ["m_bext{$l}", 'bags', "Extruder {$l}", 'extruder', 'extrude', 'active', 'Windmöller & Hölscher', 'Blown Film Line 800mm', null];
        }
        for ($i = 1; $i <= 4; $i++) {
            $m[] = ["m_conv{$i}", 'bags', "Converter {$i}", 'converter', 'convert', 'active', 'Hemingstone', 'Bottom Seal Bag Maker', null];
        }
        $m[] = ['m_line10', 'recycling', 'Line 10T', 'line', 'pelletise', 'active', 'EREMA', 'Intarema 1108 TE Pelletizer', 10000];
        $m[] = ['m_line4', 'recycling', 'Line 4T', 'line', 'pelletise', 'active', 'NGR', 'F:Gran 65 Repelletizer', 4000];
        $m[] = ['m_wash', 'recycling', 'Wash line', 'washer', 'wash', 'active', 'Sorema', 'Flake Friction Washer & Float-Sink', null];

        $perShift = ['p_rihlaat' => 76, 'p_sajaada_l' => 48, 'p_sajaada_h' => 40, 'p_domona' => 36, 'p_27m' => 16];
        foreach ($m as [$key, $site, $code, $kind, $stage, $status, $make, $model, $capacity]) {
            $machineId = Machine::query()->where('code_key', $key)->value('id');
            if (! is_string($machineId)) {
                $machineId = (string) Str::uuid7();
                $this->send(new SaveMachine($this->ctx($site), [
                    'machine_id' => $machineId, 'code_key' => $key, 'code' => $code, 'kind' => $kind, 'stage_code' => $stage, 'status' => $status,
                    'make' => $make, 'model' => $model, 'capacity_kg_per_day' => $capacity,
                ]));
            }
            if ($kind !== 'loom') {
                continue;
            }
            foreach ($perShift as $productCode => $units) {
                if (Benchmark::query()->where(['machine_id' => $machineId, 'product_id' => $products[$productCode]])->exists()) {
                    continue;
                }
                $this->send(new SetBenchmark($this->ctx($site), ['machine_id' => $machineId, 'product_id' => $products[$productCode], 'units_per_shift' => $units]));
            }
        }
    }
}
