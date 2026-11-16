<?php

declare(strict_types=1);

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\HorizonServiceProvider::class,
    Tanaad\Shared\SharedServiceProvider::class,
    Tanaad\Config\ConfigServiceProvider::class,
    Tanaad\People\PeopleServiceProvider::class,
    Tanaad\Cash\CashServiceProvider::class,
    Tanaad\Production\ProductionServiceProvider::class,
    Tanaad\Labels\LabelsServiceProvider::class,
    Tanaad\Stock\StockServiceProvider::class,
    Tanaad\Media\MediaServiceProvider::class,
    Tanaad\Messaging\MessagingServiceProvider::class,
    Tanaad\Documents\DocumentsServiceProvider::class,
    Tanaad\Group\GroupServiceProvider::class,
    Tanaad\Identity\IdentityServiceProvider::class,
    Tanaad\Recycling\RecyclingServiceProvider::class,
    Tanaad\Dispatch\DispatchServiceProvider::class,
    Tanaad\Orders\OrdersServiceProvider::class,
    Tanaad\Spares\SparesServiceProvider::class,
    Tanaad\Purchases\PurchasesServiceProvider::class,
    Tanaad\Costing\CostingServiceProvider::class,
    Tanaad\Sync\SyncServiceProvider::class,
];
