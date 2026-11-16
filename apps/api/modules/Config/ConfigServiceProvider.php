<?php

declare(strict_types=1);

namespace Tanaad\Config;

use Illuminate\Support\ServiceProvider;
use Tanaad\Config\Authorization\ConfigAuthorizer;
use Tanaad\Config\Projectors\ConfigProjector;
use Tanaad\Shared\Ledger\Registry;
use Tanaad\Sync\Snapshots;

final class ConfigServiceProvider extends ServiceProvider
{
    public function boot(Registry $registry, Snapshots $snapshots): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/migrations');

        $map = [
            'config.ref_list_changed' => [Commands\ChangeRefList::class, Handlers\ChangeRefListHandler::class, Factories\ChangeRefListFactory::class],
            'config.site_settings_changed' => [Commands\UpdateSiteSettings::class, Handlers\UpdateSiteSettingsHandler::class, Factories\UpdateSiteSettingsFactory::class],
            'config.product_saved' => [Commands\SaveProduct::class, Handlers\SaveProductHandler::class, Factories\SaveProductFactory::class],
            'config.machine_saved' => [Commands\SaveMachine::class, Handlers\SaveMachineHandler::class, Factories\SaveMachineFactory::class],
            'config.benchmark_set' => [Commands\SetBenchmark::class, Handlers\SetBenchmarkHandler::class, Factories\SetBenchmarkFactory::class],
            'config.zone_saved' => [Commands\SaveZone::class, Handlers\SaveZoneHandler::class, Factories\SaveZoneFactory::class],
            'config.fx_rate_set' => [Commands\SetFxRate::class, Handlers\SetFxRateHandler::class, Factories\SetFxRateFactory::class],
            'config.company_profile_changed' => [Commands\UpdateCompanyProfile::class, Handlers\UpdateCompanyProfileHandler::class, Factories\UpdateCompanyProfileFactory::class],
            'config.price_set' => [Commands\SetPrice::class, Handlers\SetPriceHandler::class, Factories\SetPriceFactory::class],
            'config.stage_saved' => [Commands\SaveStage::class, Handlers\SaveStageHandler::class, Factories\SaveStageFactory::class],
            'config.number_series_defined' => [Commands\DefineNumberSeries::class, Handlers\DefineNumberSeriesHandler::class, Factories\DefineNumberSeriesFactory::class],
        ];
        foreach ($map as $type => [$command, $handler, $factory]) {
            $registry->command($command, $handler, ConfigAuthorizer::class)->eventType($type, $factory);
        }
        $registry->projectors(ConfigProjector::class);

        // Group-wide rows (site_id null) are included for ref_items and fx_rates.
        foreach (['site_settings', 'stages', 'products', 'prices', 'machines', 'benchmarks', 'zones', 'company_profiles'] as $table) {
            $snapshots->register($table, $table);
        }
        $snapshots->register('ref_items', 'ref_items', siteScoped: false);
        $snapshots->register('fx_rates', 'fx_rates', siteScoped: false);
    }
}
