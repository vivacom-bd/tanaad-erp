<?php

declare(strict_types=1);

arch()->preset()->php();
arch()->preset()->security();

arch('every file declares strict types')
    ->expect('Tanaad')
    ->toUseStrictTypes();

arch('the shared kernel depends on nothing in a business module')
    ->expect('Tanaad\Shared')
    ->not->toUse([
        'Tanaad\Identity', 'Tanaad\Config', 'Tanaad\People', 'Tanaad\Cash', 'Tanaad\Production',
        'Tanaad\Stock', 'Tanaad\Dispatch', 'Tanaad\Orders', 'Tanaad\Costing', 'Tanaad\Group',
        'Tanaad\Media', 'Tanaad\Messaging', 'Tanaad\Documents',
    ]);

arch('commands and events are final and readonly')
    ->expect('Tanaad\Shared\Ledger\Event')->toBeFinal()->toBeReadonly()
    ->and('Tanaad\Shared\Ledger\StoredEvent')->toBeFinal()->toBeReadonly()
    ->and('Tanaad\Shared\Ledger\CommandContext')->toBeFinal()->toBeReadonly();

/**
 * Namespaces of the given kind in every module that has one, e.g. Tanaad\Cash\Handlers.
 *
 * @return list<string>
 */
function moduleNamespaces(string ...$kinds): array
{
    $out = [];
    foreach (glob(dirname(__DIR__, 2).'/modules/*/') ?: [] as $dir) {
        $module = basename($dir);
        foreach ($kinds as $kind) {
            if (is_dir($dir.$kind)) {
                $out[] = "Tanaad\\{$module}\\{$kind}";
            }
        }
    }

    return $out;
}

if (moduleNamespaces('Handlers') !== []) {
    arch('handlers get their dependencies injected, never through facades')
        ->expect(moduleNamespaces('Handlers'))
        ->not->toUse('Illuminate\Support\Facades');
}

if (moduleNamespaces('Handlers', 'Policies', 'Http', 'Queries') !== []) {
    arch('projectors are the only classes that write read models')
        ->expect(moduleNamespaces('Handlers', 'Policies', 'Http', 'Queries'))
        ->not->toUse('Illuminate\Support\Facades\DB');
}
