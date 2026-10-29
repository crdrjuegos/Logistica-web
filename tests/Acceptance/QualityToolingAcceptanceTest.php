<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/*
 * Criterios de calidad del código (HU-057 y HU-058). Ejecutan las herramientas reales, por eso están
 * en el grupo "tooling" (excluido de la corrida normal). Se ejecutan con:
 *   docker compose run --rm -T tools vendor/bin/pest --group=tooling
 */

function toolRun(array $command, int $timeout = 600): Process
{
    $process = new Process($command, base_path(), null, null, $timeout);
    $process->run();

    return $process;
}

it('CA-057-1 Deptrac sobre los módulos, Shared y la presentación termina con 0 violaciones, 0 errores y 0 no cubiertas', function () {
    $run = toolRun(['vendor/bin/deptrac', 'analyse', '--no-progress', '--no-cache', '--report-uncovered', '--fail-on-uncovered']);

    expect($run->getExitCode())->toBe(0, $run->getOutput().$run->getErrorOutput());
    expect($run->getOutput())->toMatch('/Violations\s+0/')->toMatch('/Errors\s+0/')->toMatch('/Uncovered\s+0/');
})->group('tooling');

it('CA-057-2 la regla detecta una importación de Illuminate en el dominio, en una copia de prueba', function () {
    $dir = storage_path('framework/testing/deptrac-'.bin2hex(random_bytes(4)));
    mkdir($dir.'/app/Modules/Demo/Domain', 0777, true);
    file_put_contents($dir.'/app/Modules/Demo/Domain/Bad.php', <<<'PHP'
        <?php
        namespace App\Modules\Demo\Domain;
        use Illuminate\Support\Facades\DB;
        final class Bad { public function x(): mixed { return DB::table('x'); } }
        PHP);
    $config = (string) file_get_contents(base_path('deptrac.yaml'));
    $config = preg_replace('/paths:\n(\s+- .+\n)+/', "paths:\n    - {$dir}/app\n", $config, 1);
    file_put_contents($dir.'/deptrac.yaml', (string) str_replace('app/(', $dir.'/app/(', (string) $config));

    $run = toolRun(['vendor/bin/deptrac', 'analyse', '--config-file='.$dir.'/deptrac.yaml', '--no-progress', '--no-cache']);

    expect($run->getExitCode())->not->toBe(0);
    expect($run->getOutput())->toContain('Bad')->toContain('Illuminate');
    expect(is_file(base_path('app/Modules/Demo/Domain/Bad.php')))->toBeFalse(); // el proyecto entregado no cambia
})->group('tooling');

it('CA-057-3 la configuración de Deptrac incluye la presentación (app/Filament)', function () {
    $config = (string) file_get_contents(base_path('deptrac.yaml'));

    expect($config)->toContain('./app/Filament');
    expect($config)->toContain('name: Presentation');
})->group('tooling');

it('CA-058-1 Pint --test en Docker termina con código 0 sin modificar archivos', function () {
    $before = toolRun(['git', 'status', '--porcelain'])->getOutput();

    $run = toolRun(['vendor/bin/pint', '--test']);

    expect($run->getExitCode())->toBe(0, $run->getOutput());
    expect(toolRun(['git', 'status', '--porcelain'])->getOutput())->toBe($before);
})->group('tooling');

it('CA-058-2 PHPStan con el nivel configurado (6) y 1G de memoria termina sin errores', function () {
    expect((string) file_get_contents(base_path('phpstan.neon')))->toMatch('/level:\s*6/');

    $run = toolRun(['vendor/bin/phpstan', 'analyse', '--memory-limit=1G', '--no-progress', '--error-format=raw'], 900);

    expect($run->getExitCode())->toBe(0, $run->getOutput());
})->group('tooling');

it('CA-058-3 composer.json y composer.lock declaran Filament y Livewire usados por la app', function () {
    $json = json_decode((string) file_get_contents(base_path('composer.json')), true);
    $lock = json_decode((string) file_get_contents(base_path('composer.lock')), true);
    $locked = array_column($lock['packages'], 'name');

    foreach (['filament/filament', 'livewire/livewire', 'laravel/framework', 'spatie/laravel-permission'] as $package) {
        expect($locked)->toContain($package);
    }
    expect($json['require'])->toHaveKey('filament/filament');
    expect(hash('md5', (string) file_get_contents(base_path('composer.json'))))->not->toBeEmpty();
    expect($lock['content-hash'])->not->toBeEmpty();
})->group('tooling');
