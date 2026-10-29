<?php

declare(strict_types=1);

use App\Filament\Resources\Imports\ImportResource;
use App\Filament\Resources\Imports\Pages\ListImports;
use App\Models\ImportJob;
use App\Modules\Iam\Domain\RoleName;
use App\Modules\Inventory\Application\RegisterMovement;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Operations\Application\CancelCount;
use App\Modules\Operations\Application\CloseCount;
use App\Modules\Operations\Application\ImportPosSales;
use App\Modules\Operations\Application\RecordCount;
use App\Modules\Operations\Application\StartCount;
use App\Modules\Operations\Application\SubmitCountForReview;
use App\Modules\Operations\Domain\CountScopeType;
use App\Modules\Operations\Domain\NewCount;
use App\Modules\Operations\Domain\OperationsException;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\Fixtures;
use Tests\Support\MovementFactory as M;
use Tests\Support\PanelUsers;

function cntWarehouse(string $code = 'BOD'): int
{
    return (int) DB::table('warehouses')->where('code', $code)->value('id');
}

function cntFrozen(int $location): bool
{
    return (bool) DB::table('locations')->where('id', $location)->value('is_frozen');
}

/** Conteo de zona con N productos en una ubicación, cada uno con el stock indicado. */
function cntZone(string $zone, array $stocks, int $actor): array
{
    $loc = Fixtures::location('BOD', ['zone' => $zone]);
    $products = [];
    foreach ($stocks as $qty) {
        $p = Fixtures::product();
        app(RegisterMovement::class)(M::purchase($p, $loc, $qty, '2', $actor));
        $products[] = $p;
    }
    $count = app(StartCount::class)(new NewCount(cntWarehouse(), CountScopeType::Zone, $zone, $actor));

    return [$count['id'], $loc, $products];
}

// HU-032 Iniciar conteo

it('CA-032-1 BOD alcance FULL con 3 líneas en dos ubicaciones fija el esperado, crea el conteo y congela', function () {
    $actor = Fixtures::user();
    $loc10 = Fixtures::location('BOD');
    $loc11 = Fixtures::location('BOD');
    $register = app(RegisterMovement::class);
    $register(M::purchase($p1 = Fixtures::product(), $loc10, 5, '1', $actor));
    $register(M::purchase($p2 = Fixtures::product(), $loc10, 7, '1', $actor));
    $register(M::purchase($p3 = Fixtures::product(), $loc11, 9, '1', $actor));

    $count = app(StartCount::class)(new NewCount(cntWarehouse(), CountScopeType::Full, null, $actor));

    expect($count['lines'])->toBe(3);
    expect(DB::table('stock_counts')->where('id', $count['id'])->value('status'))->toBe('COUNTING');
    $expected = DB::table('stock_count_lines')->where('count_id', $count['id'])->orderBy('product_id')->pluck('expected_qty', 'product_id')
        ->map(static fn ($q): string => (string) $q)->all();
    expect($expected)->toBe([$p1 => '5.000', $p2 => '7.000', $p3 => '9.000']);
    expect(cntFrozen($loc10) && cntFrozen($loc11))->toBeTrue();
});

it('CA-032-2 con un conteo activo en la ubicación, otro que la incluye se rechaza sin alterar el primero', function () {
    $actor = Fixtures::user();
    [$first, $loc] = cntZone('Z32', [4], $actor);
    $code = (string) DB::table('locations')->where('id', $loc)->value('code');

    expect(fn () => app(StartCount::class)(new NewCount(cntWarehouse(), CountScopeType::Location, $code, $actor)))
        ->toThrow(OperationsException::class, 'congelada');

    expect(DB::table('stock_counts')->count())->toBe(1);
    expect(DB::table('stock_counts')->where('id', $first)->value('status'))->toBe('COUNTING');
    expect(cntFrozen($loc))->toBeTrue();
});

it('CA-032-3 alcance LOCATION sin identificador o sin ubicaciones se rechaza sin congelar ni crear conteo', function () {
    $actor = Fixtures::user();
    $loc = Fixtures::location('BOD', ['zone' => 'Z33']);

    expect(fn () => new NewCount(cntWarehouse(), CountScopeType::Location, null, $actor))->toThrow(OperationsException::class, 'Indique');
    expect(fn () => app(StartCount::class)(new NewCount(cntWarehouse(), CountScopeType::Zone, 'ZONA-INEXISTENTE', $actor)))
        ->toThrow(OperationsException::class);

    expect(DB::table('stock_counts')->count())->toBe(0);
    expect(cntFrozen($loc))->toBeFalse();
});

// HU-033 Conteo ciego

it('CA-033-1 el almacenero sin counts.close guarda 8.000 sin ver el esperado 10 ni la diferencia', function () {
    PanelUsers::seedRoles();
    $counter = PanelUsers::withRole(RoleName::Almacenero);
    [$countId, $loc, [$product]] = cntZone('Z34', [10], $counter->id);

    app(RecordCount::class)($countId, $product, $loc, Quantity::of('8'), $counter->id);

    expect((string) DB::table('stock_count_lines')->where('count_id', $countId)->value('counted_qty'))->toBe('8.000');
    expect($counter->can('counts.close'))->toBeFalse();
    $html = view('filament.count-lines', [
        'count' => null, 'showExpected' => $counter->can('counts.close'),
        'lines' => DB::select('SELECT l.id, l.expected_qty::text AS expected, l.counted_qty::text AS counted, l.difference::text AS difference, l.recount_requested, p.sku, p.name, loc.code AS location_code
            FROM stock_count_lines l JOIN products p ON p.id = l.product_id JOIN locations loc ON loc.id = l.location_id WHERE l.count_id = ?', [$countId]),
    ])->render();
    expect($html)->toContain('>8<')->not->toContain('Esperado')->not->toContain('Diferencia')->not->toContain('>10<');
});

it('CA-033-2 un hallazgo de 3 crea línea con esperado 0 y el producto 999 se rechaza', function () {
    $actor = Fixtures::user();
    [$countId, $loc] = cntZone('Z35', [5], $actor);
    $found = Fixtures::product();

    app(RecordCount::class)($countId, $found, $loc, Quantity::of('3'), $actor);
    expect(fn () => app(RecordCount::class)($countId, 999, $loc, Quantity::of('3'), $actor))->toThrow(OperationsException::class, 'producto');

    $line = DB::table('stock_count_lines')->where('count_id', $countId)->where('product_id', $found)->first();
    expect([(string) $line->expected_qty, (string) $line->counted_qty])->toBe(['0.000', '3.000']);
    expect(DB::table('stock_count_lines')->where('product_id', 999)->count())->toBe(0);
});

it('CA-033-3 reemplazar el conteo 8 por 0 guarda 0.000 contado, distinto de una línea sin contar', function () {
    $actor = Fixtures::user();
    [$countId, $loc, [$a, $b]] = cntZone('Z36', [10, 4], $actor);

    app(RecordCount::class)($countId, $a, $loc, Quantity::of('8'), $actor);
    app(RecordCount::class)($countId, $a, $loc, Quantity::zero(), $actor);

    expect((string) DB::table('stock_count_lines')->where('count_id', $countId)->where('product_id', $a)->value('counted_qty'))->toBe('0.000');
    expect(DB::table('stock_count_lines')->where('count_id', $countId)->where('product_id', $b)->value('counted_qty'))->toBeNull();
});

// HU-034 Revisión y reconteo

it('CA-034-1 con 3 líneas contadas y una con esperado 4 y contado 3, pasa a REVIEW y marca solo esa', function () {
    $actor = Fixtures::user();
    [$countId, $loc, [$a, $b, $c]] = cntZone('Z37', [4, 6, 2], $actor);
    app(RecordCount::class)($countId, $a, $loc, Quantity::of('3'), $actor);
    app(RecordCount::class)($countId, $b, $loc, Quantity::of('6'), $actor);
    app(RecordCount::class)($countId, $c, $loc, Quantity::of('2'), $actor);

    $result = app(SubmitCountForReview::class)($countId, $actor);

    expect($result)->toBe(['lines' => 3, 'withDifference' => 1]);
    expect(DB::table('stock_counts')->where('id', $countId)->value('status'))->toBe('REVIEW');
    expect(DB::table('stock_count_lines')->where('count_id', $countId)->where('recount_requested', true)->pluck('product_id')->all())->toBe([$a]);
});

it('CA-034-2 con 3 líneas y solo una contada, rechaza el envío indicando las 2 faltantes y sigue en COUNTING', function () {
    $actor = Fixtures::user();
    [$countId, $loc, [$a]] = cntZone('Z38', [4, 6, 2], $actor);
    app(RecordCount::class)($countId, $a, $loc, Quantity::of('4'), $actor);

    expect(fn () => app(SubmitCountForReview::class)($countId, $actor))->toThrow(OperationsException::class, '2');

    expect(DB::table('stock_counts')->where('id', $countId)->value('status'))->toBe('COUNTING');
});

it('CA-034-3 en REVIEW el reconteo 3 de la línea marcada limpia la marca y la línea no marcada no se modifica', function () {
    $actor = Fixtures::user();
    [$countId, $loc, [$a, $b]] = cntZone('Z39', [4, 6], $actor);
    app(RecordCount::class)($countId, $a, $loc, Quantity::of('2'), $actor);
    app(RecordCount::class)($countId, $b, $loc, Quantity::of('6'), $actor);
    app(SubmitCountForReview::class)($countId, $actor);

    app(RecordCount::class)($countId, $a, $loc, Quantity::of('3'), $actor);
    expect(fn () => app(RecordCount::class)($countId, $b, $loc, Quantity::of('1'), $actor))->toThrow(OperationsException::class, 'marcadas');

    $lines = DB::table('stock_count_lines')->where('count_id', $countId)->get()->keyBy('product_id');
    expect((string) $lines[$a]->counted_qty)->toBe('3.000');
    expect((bool) $lines[$a]->recount_requested)->toBeFalse();
    expect((string) $lines[$b]->counted_qty)->toBe('6.000');
});

it('Bloque A: la exactitud del conteo 2 de 3 se redondea half-up a 66.67 %', function () {
    $counter = Fixtures::user();
    $closer = Fixtures::user();
    [$countId, $loc, [$a, $b, $c]] = cntZone('Z3A', [4, 6, 2], $counter);
    app(RecordCount::class)($countId, $a, $loc, Quantity::of('3'), $counter);
    app(RecordCount::class)($countId, $b, $loc, Quantity::of('6'), $counter);
    app(RecordCount::class)($countId, $c, $loc, Quantity::of('2'), $counter);
    app(SubmitCountForReview::class)($countId, $counter);
    app(RecordCount::class)($countId, $a, $loc, Quantity::of('3'), $counter);

    $result = app(CloseCount::class)($countId, $closer);

    expect($result['accuracyPct'])->toBe('66.67');
});

// HU-036 Anular conteo

it('CA-036-1 anular un conteo COUNTING con ubicaciones congeladas lo deja CANCELLED, descongela y sin ajustes', function () {
    $actor = Fixtures::user();
    [$countId, $loc] = cntZone('Z40', [5], $actor);

    app(CancelCount::class)($countId, $actor);

    expect(DB::table('stock_counts')->where('id', $countId)->value('status'))->toBe('CANCELLED');
    expect(cntFrozen($loc))->toBeFalse();
    expect(DB::table('stock_movements')->where('source_type', 'COUNT')->count())->toBe(0);
});

it('CA-036-2 un conteo CLOSED no se anula y conserva sus movimientos de cierre', function () {
    $counter = Fixtures::user();
    $closer = Fixtures::user();
    [$countId, $loc, [$a]] = cntZone('Z41', [5], $counter);
    app(RecordCount::class)($countId, $a, $loc, Quantity::of('4'), $counter);
    app(SubmitCountForReview::class)($countId, $counter);
    app(RecordCount::class)($countId, $a, $loc, Quantity::of('4'), $counter);
    app(CloseCount::class)($countId, $closer);
    $movements = DB::table('stock_movements')->where('source_type', 'COUNT')->count();

    expect(fn () => app(CancelCount::class)($countId, $closer))->toThrow(OperationsException::class);

    expect(DB::table('stock_counts')->where('id', $countId)->value('status'))->toBe('CLOSED');
    expect(DB::table('stock_movements')->where('source_type', 'COUNT')->count())->toBe($movements)->toBe(1);
});

it('CA-036-3 anular de nuevo un conteo CANCELLED no repite efectos y las ubicaciones siguen libres', function () {
    $actor = Fixtures::user();
    [$countId, $loc] = cntZone('Z42', [5], $actor);
    app(CancelCount::class)($countId, $actor);
    $audits = DB::table('audit_logs')->where('entity_type', 'count')->where('entity_id', $countId)->count();

    try {
        app(CancelCount::class)($countId, $actor);
    } catch (OperationsException) {
        // rechazar la repetición también es aceptable: lo importante es que no haya efectos
    }

    expect(DB::table('stock_counts')->where('id', $countId)->value('status'))->toBe('CANCELLED');
    expect(DB::table('audit_logs')->where('entity_type', 'count')->where('entity_id', $countId)->count())->toBe($audits);
    expect(DB::table('stock_movements')->where('source_type', 'COUNT')->count())->toBe(0);
    expect(cntFrozen($loc))->toBeFalse();
});

// HU-037 a HU-039 Ventas del POS

/** Producto con LIB-xxxxxx, ubicación principal en PIS y stock inicial. */
function posFixture(string $stock): array
{
    $actor = Fixtures::user();
    $product = Fixtures::product('2');
    $floor = Fixtures::location('PIS');
    app(RegisterMovement::class)(M::purchase($product, $floor, $stock, '2', $actor));
    DB::table('product_locations')->insert(['product_id' => $product, 'location_id' => $floor, 'is_primary' => true]);
    DB::table('products')->where('id', $product)->update(['sku' => 'LIB-000001']);

    return [$actor, $product, $floor];
}

function posToday(): string
{
    return now('America/Lima')->format('Y-m-d');
}

it('CA-037-1 simular LIB-000001,2 y LIB-000001,3 con stock PIS=10 agrupa 5 sin tocar stock', function () {
    [$actor, $product, $floor] = posFixture('10');

    $result = app(ImportPosSales::class)("sku,cantidad\nLIB-000001,2\nLIB-000001,3\n", 'ventas.csv', posToday(), $actor, false);

    expect($result->committed)->toBeFalse();
    expect($result->okRows)->toBe(1);
    expect($result->units)->toBe('5.000');
    expect(Fixtures::balance($product, $floor))->toBe('10.000');
    expect(DB::table('stock_movements')->where('type', 'SALIDA_VENTA')->count())->toBe(0);
});

it('CA-037-2 confirmar con 2 UND válidas, un código inexistente y una cantidad inválida aplica solo la válida y reporta fila y motivo', function () {
    [$actor, $product, $floor] = posFixture('10');

    $result = app(ImportPosSales::class)("sku,cantidad\nLIB-000001,2\nNO-EXISTE,1\nLIB-000001,abc\n", 'ventas.csv', posToday(), $actor, true);

    expect(Fixtures::balance($product, $floor))->toBe('8.000');
    expect(array_map(static fn ($e): int => $e->line, $result->errors))->toBe([3, 4]);
    expect($result->errors[0]->message)->toContain('No se encontró');
    expect($result->errors[1]->message)->toContain('Cantidad inválida');
});

it('CA-037-3 con saldo PIS=3 y venta agrupada 5 no descuenta y el error va al reporte', function () {
    [$actor, $product, $floor] = posFixture('3');

    $result = app(ImportPosSales::class)("sku,cantidad\nLIB-000001,2\nLIB-000001,3\n", 'ventas.csv', posToday(), $actor, true);

    expect(Fixtures::balance($product, $floor))->toBe('3.000');
    expect($result->okRows)->toBe(0);
    expect($result->errors)->toHaveCount(1);
    expect($result->errors[0]->message)->toContain('Stock insuficiente');
});

it('CA-038-1 el reporte de errores de las filas 2 y 4 trae fila, código original y explicación legible en Excel', function () {
    [$actor] = posFixture('10');

    $result = app(ImportPosSales::class)("sku,cantidad\nNO-EXISTE,1\nLIB-000001,1\nOTRO-MAL,-2\n", 'ventas.csv', posToday(), $actor, true);
    $csv = $result->errorReportCsv();

    expect(str_starts_with($csv, "\xEF\xBB\xBF"))->toBeTrue(); // BOM: Excel abre bien las tildes
    $rows = array_map(static fn (string $l): array => str_getcsv($l, ';', '"', ''), explode("\r\n", trim(substr($csv, 3))));
    expect($rows[0])->toBe(['fila', 'codigo', 'error']);
    expect([$rows[1][0], $rows[1][1]])->toBe(['2', 'NO-EXISTE']);
    expect([$rows[2][0], $rows[2][1]])->toBe(['4', 'OTRO-MAL']);
    expect($rows[2][2])->toContain('negativa');
});

it('CA-038-2 un archivo sin errores no ofrece reporte ni falla al consultar el detalle', function () {
    PanelUsers::seedRoles();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    [$actor] = posFixture('10');
    $result = app(ImportPosSales::class)("sku,cantidad\nLIB-000001,1\n", 'ventas.csv', posToday(), $actor, true);
    $this->actingAs(PanelUsers::withRole(RoleName::Supervisor));

    expect($result->errors)->toBe([]);
    Livewire::test(ListImports::class)
        ->assertCanSeeTableRecords([ImportJob::query()->findOrFail($result->importId)])
        ->assertTableActionHidden('errors', $result->importId);
});

it('CA-038-3 sin pos.import o con una ruta de reporte ajena no se entrega el archivo', function () {
    PanelUsers::seedRoles();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Storage::fake('local');
    Storage::disk('local')->put('secreto.txt', 'no debe salir');
    [$actor] = posFixture('10');
    $result = app(ImportPosSales::class)("sku,cantidad\nNO-EXISTE,1\n", 'ventas.csv', posToday(), $actor, true);
    DB::table('import_jobs')->where('id', $result->importId)->update(['error_report_path' => '../secreto.txt']);

    $this->actingAs(PanelUsers::withRole(RoleName::Lectura));
    expect(ImportResource::canViewAny())->toBeFalse();
    $this->get(ImportResource::getUrl('index'))->assertForbidden();

    $this->actingAs(PanelUsers::withRole(RoleName::Supervisor));
    Livewire::test(ListImports::class)->assertTableActionHidden('errors', $result->importId);
});

it('CA-039-1 un CSV nuevo se confirma y guarda su hash SHA-256 y el resultado', function () {
    [$actor] = posFixture('10');
    $csv = "sku,cantidad\nLIB-000001,2\n";

    $result = app(ImportPosSales::class)($csv, 'ventas.csv', posToday(), $actor, true);

    $job = DB::table('import_jobs')->where('id', $result->importId)->first();
    expect($job->file_sha256)->toBe(hash('sha256', $csv));
    expect([$job->mode, (int) $job->ok_rows, (int) $job->error_rows])->toBe(['COMMIT', 1, 0]);
});

it('CA-039-2 los mismos bytes con otro nombre ya confirmados se rechazan sin volver a descontar', function () {
    [$actor, $product, $floor] = posFixture('10');
    $csv = "sku,cantidad\nLIB-000001,2\n";
    app(ImportPosSales::class)($csv, 'ventas.csv', posToday(), $actor, true);

    expect(fn () => app(ImportPosSales::class)($csv, 'otro-nombre.csv', posToday(), $actor, true))->toThrow(OperationsException::class, 'ya se importó');

    expect(Fixtures::balance($product, $floor))->toBe('8.000');
});

it('CA-039-3 un archivo ya confirmado se puede simular y el contenido corregido (otro hash) se procesa', function () {
    [$actor, $product, $floor] = posFixture('10');
    $csv = "sku,cantidad\nLIB-000001,2\n";
    app(ImportPosSales::class)($csv, 'ventas.csv', posToday(), $actor, true);

    $dry = app(ImportPosSales::class)($csv, 'ventas.csv', posToday(), $actor, false);
    expect($dry->committed)->toBeFalse();
    expect(Fixtures::balance($product, $floor))->toBe('8.000');

    app(ImportPosSales::class)("sku,cantidad\nLIB-000001,3\n", 'ventas-corregido.csv', posToday(), $actor, true);
    expect(Fixtures::balance($product, $floor))->toBe('5.000');
});
