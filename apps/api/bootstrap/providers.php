<?php

declare(strict_types=1);

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\HorizonServiceProvider::class,
    Tanaad\Shared\SharedServiceProvider::class,
    Tanaad\Config\ConfigServiceProvider::class,
    Tanaad\People\PeopleServiceProvider::class,
    Tanaad\Cash\CashServiceProvider::class,
    Tanaad\Media\MediaServiceProvider::class,
    Tanaad\Group\GroupServiceProvider::class,
    Tanaad\Identity\IdentityServiceProvider::class,
    Tanaad\Sync\SyncServiceProvider::class,
];
