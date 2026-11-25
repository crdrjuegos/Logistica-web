<?php

declare(strict_types=1);

use App\Filament\Pages\OpeningBalanceImportPage;
use App\Modules\Iam\Domain\RoleName;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Inventory\Domain\UnitCost;
use App\Modules\Operations\Application\ImportOpeningBalances;
use App\Modules\Operations\Application\RecordOpeningBalances;
use App\Modules\Operations\Domain\OpeningBalanceLine;
use App\Modules\Operations\Domain\OperationsException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Fixtures;
use Tests\Support\PanelUsers;

beforeEach(fn () => PanelUsers::seedRoles());

// HU-053 Carga masiva de saldos iniciales desde un archivo

/** @return array{0: int, 1: string, 2: int, 3: string, 4: int, 5: string, 6: int} producto A, SKU A, ubicación BOD, código, producto B, SKU B, ubicación PIS */
function openingImportSetup(): array
{
    $a = Fixtures::product();
    $b = Fixtures::product();
    $bod = Fixtures::location('BOD');
    $pis = Fixtures::location('PIS');

    return [$a, (string) DB::table('products')->where('id', $a)->value('sku'), $bod, (string) DB::table('locations')->where('id', $bod)->value('code'),
        $b, (string) DB::table('products')->where('id', $b)->value('sku'), $pis];
}

it('HU-053 el administrador simula y luego carga el CSV: SALDO_INICIAL con la referencia del lote, auditoría y resumen', function () {
    $admin = PanelUsers::withRole(RoleName::Admin);
    [$a, $skuA, $bod, $codeBod, $b, $skuB, $pis] = openingImportSetup();
    $codePis = (string) DB::table('locations')->where('id', $pis)->value('code');
    $csv = "sku;ubicacion;cantidad;costo\n{$skuA};{$codeBod};10;2,50\n{$skuB};{$codePis};4.5;1\n";

    $simulated = app(ImportOpeningBalances::class)($csv, 'apertura.csv', $admin->id, 'apertura-import', false);

    expect([$simulated->committed, $simulated->totalRows, $simulated->okRows, $simulated->errors, $simulated->units])->toBe([false, 2, 2, [], '14.500']);
    expect(DB::table('stock_movements')->where('type', 'SALDO_INICIAL')->count())->toBe(0);
    expect(DB::table('import_jobs')->where('kind', 'OPENING_STOCK')->value('mode'))->toBe('DRY_RUN');

    $result = app(ImportOpeningBalances::class)($csv, 'apertura.csv', $admin->id, 'apertura-import', true);

    expect([$result->committed, $result->okRows, $result->errors])->toBe([true, 2, []]);
    expect([Fixtures::balance($a, $bod), Fixtures::balance($b, $pis)])->toBe(['10.000', '4.500']);
    $movement = DB::table('stock_movements')->where('type', 'SALDO_INICIAL')->where('product_id', $a)->first();
    expect([$movement->document_ref, (string) $movement->unit_cost])->toBe(['APERTURA-IMPORT', '2.5000']);
    expect(DB::table('audit_logs')->where('action', 'opening.recorded')->count())->toBe(1);
    expect(DB::table('audit_logs')->where('action', 'opening.import')->count())->toBe(2);
    $job = DB::table('import_jobs')->where('kind', 'OPENING_STOCK')->where('mode', 'COMMIT')->first();
    expect([(int) $job->total_rows, (int) $job->ok_rows, (int) $job->error_rows])->toBe([2, 2, 0]);

    expect(fn () => app(ImportOpeningBalances::class)($csv, 'apertura-copia.csv', $admin->id, 'apertura-import-2', true))
        ->toThrow(OperationsException::class, 'ya se cargó');
    expect([Fixtures::balance($a, $bod), Fixtures::balance($b, $pis)])->toBe(['10.000', '4.500']);
});

it('HU-053 con errores no se carga nada (todo o nada) y el reporte indica fila y causa de cada uno', function () {
    $admin = PanelUsers::withRole(RoleName::Admin);
    [$a, $skuA, $bod, $codeBod, $b, $skuB, $pis] = openingImportSetup();
    $codePis = (string) DB::table('locations')->where('id', $pis)->value('code');
    app(RecordOpeningBalances::class)([new OpeningBalanceLine($b, $pis, Quantity::of('3'), UnitCost::of('1'))], $admin->id, 'APERTURA-PREVIA');
    $csv = implode("\n", [
        'sku;ubicacion;cantidad;costo',
        "{$skuA};{$codeBod};10;2",            // fila 2: válida
        "NO-EXISTE;{$codeBod};5;1",           // fila 3: producto inexistente
        "{$skuA};ZZZ-999;5;1",                // fila 4: ubicación inexistente
        "{$skuA};{$codeBod};-10;1",           // fila 5: cantidad negativa (y repetida en el archivo)
        "{$skuA};{$codeBod};0;1",             // fila 6: cantidad cero
        "{$skuA};{$codeBod};4;-1",            // fila 7: costo negativo
        "{$skuB};{$codePis};2;1",             // fila 8: ya tiene saldo inicial
    ])."\n";

    $result = app(ImportOpeningBalances::class)($csv, 'con-errores.csv', $admin->id, 'APERTURA-ERRORES', true);

    expect([$result->committed, $result->totalRows, $result->okRows, count($result->errors)])->toBe([false, 7, 1, 6]);
    expect(array_map(static fn ($e): int => $e->line, $result->errors))->toBe([3, 4, 5, 6, 7, 8]);
    $messages = array_map(static fn ($e): string => $e->message, $result->errors);
    expect($messages[0])->toContain('no existe')->and($messages[1])->toContain('ubicación')->and($messages[2])->toContain('negativa')
        ->and($messages[3])->toContain('mayor que cero')->and($messages[4])->toContain('costo no puede ser negativo')->and($messages[5])->toContain('Ya existe un saldo inicial');
    expect(DB::table('stock_movements')->where('type', 'SALDO_INICIAL')->where('document_ref', 'APERTURA-ERRORES')->count())->toBe(0);
    expect(Fixtures::balance($a, $bod))->toBe('0');
    expect(DB::table('import_jobs')->where('kind', 'OPENING_STOCK')->value('mode'))->toBe('DRY_RUN'); // nada se cargó, el archivo corregido se puede subir
    expect($result->errorReportCsv())->toContain('fila;codigo;error')->toContain('NO-EXISTE');
});

it('HU-053 el mismo producto dos veces en la misma ubicación se informa con la fila de la primera aparición', function () {
    $admin = PanelUsers::withRole(RoleName::Admin);
    [, $skuA, , $codeBod] = openingImportSetup();
    $csv = "sku;ubicacion;cantidad;costo\n{$skuA};{$codeBod};10;2\n{$skuA};{$codeBod};5;2\n";

    $result = app(ImportOpeningBalances::class)($csv, 'repetido.csv', $admin->id, 'APERTURA-REP', false);

    expect(count($result->errors))->toBe(1);
    expect($result->errors[0]->line)->toBe(3);
    expect($result->errors[0]->message)->toContain('fila 2');
});

it('HU-053 solo quien tiene inventory.opening_balance accede a la carga; una referencia o un encabezado inválidos se rechazan', function () {
    $admin = PanelUsers::withRole(RoleName::Admin);
    [, $skuA, , $codeBod] = openingImportSetup();

    $this->actingAs($admin);
    expect(OpeningBalanceImportPage::canAccess())->toBeTrue();
    $this->actingAs(PanelUsers::withRole(RoleName::Supervisor));
    expect(OpeningBalanceImportPage::canAccess())->toBeFalse();

    $good = "sku;ubicacion;cantidad;costo\n{$skuA};{$codeBod};1;1\n";
    expect(fn () => app(ImportOpeningBalances::class)($good, 'a.csv', $admin->id, 'x', false))->toThrow(OperationsException::class, 'referencia');
    expect(fn () => app(ImportOpeningBalances::class)("sku;cantidad\nX;1\n", 'a.csv', $admin->id, 'APERTURA-X', false))->toThrow(OperationsException::class, 'Faltan columnas');
    expect(fn () => app(ImportOpeningBalances::class)('', 'a.csv', $admin->id, 'APERTURA-X', false))->toThrow(OperationsException::class, 'vacío');
});

it('HU-053 una cantidad o un costo con formato inválido se informa por fila y no rompe la carga', function () {
    $admin = PanelUsers::withRole(RoleName::Admin);
    [, $skuA, , $codeBod] = openingImportSetup();
    $csv = "sku;ubicacion;cantidad;costo\n{$skuA};{$codeBod};abc;1\n{$skuA};{$codeBod};5;1.23456\n{$skuA};{$codeBod};;\n";

    $result = app(ImportOpeningBalances::class)($csv, 'formatos.csv', $admin->id, 'APERTURA-FMT', true);

    expect([$result->committed, $result->okRows, count($result->errors)])->toBe([false, 0, 3]);
    expect($result->errors[0]->message)->toContain('Cantidad inválida');
    expect($result->errors[1]->message)->toContain('Costo unitario inválido');
    expect($result->errors[2]->message)->toContain('Falta la cantidad')->toContain('Falta el costo');
});
