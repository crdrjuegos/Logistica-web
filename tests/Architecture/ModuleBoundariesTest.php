<?php

declare(strict_types=1);

$modules = ['Inventory', 'Catalog', 'Iam', 'Audit', 'Locations', 'Suppliers', 'Operations'];
$infrastructure = array_map(static fn (string $m): string => sprintf('App\Modules\%s\Infrastructure', $m), $modules);

foreach ($modules as $module) {
    arch("{$module}: el dominio no depende del framework ni de otras capas")
        ->expect("App\\Modules\\{$module}\\Domain")
        ->not->toUse(['Illuminate', 'Spatie', "App\\Modules\\{$module}\\Application", "App\\Modules\\{$module}\\Infrastructure", 'App\Models']);

    // La aplicación puede usar interfaces del dominio (propio o de otros módulos), nunca infraestructura ni framework.
    if (is_dir(__DIR__."/../../app/Modules/{$module}/Application")) {
        arch("{$module}: la aplicación no depende del framework ni de infraestructura")
            ->expect("App\\Modules\\{$module}\\Application")
            ->not->toUse(['Illuminate', 'Spatie', 'App\Models', ...$infrastructure]);
    }
}

arch('el kernel compartido (dominio) no depende del framework')
    ->expect('App\Shared\Domain')
    ->not->toUse(['Illuminate', 'Spatie', 'App\Modules']);

arch('todo el código de módulos y compartido usa strict_types')
    ->expect(['App\Modules', 'App\Shared'])
    ->toUseStrictTypes();

arch('no quedan funciones de depuración')
    ->expect(['dd', 'dump', 'var_dump', 'die', 'exit'])
    ->not->toBeUsed();

arch('la presentación (Filament) no usa la infraestructura de los módulos')
    ->expect('App\Filament')
    ->not->toUse($infrastructure);
