<?php

declare(strict_types=1);

use App\Modules\Operations\Application\ImportPosSales;
use App\Modules\Operations\Domain\OperationsException;
use App\Modules\Operations\Domain\PosSalesParser;
use App\Modules\Operations\Domain\SalesTarget;
use Tests\Support\Operations\FakePosInfra;
use Tests\Support\Operations\OperationsKit;
use Tests\Support\Operations\SnapshotTransaction;

function posKit(): array
{
    $k = new OperationsKit;
    $pos = new FakePosInfra;
    $pos->targets = [
        'LIB-000001' => new SalesTarget(5, 'LIB-000001', 11, 'PIS-Z1-P01-A01-N1', '10.000'),
        '4006381333931' => new SalesTarget(5, 'LIB-000001', 11, 'PIS-Z1-P01-A01-N1', '10.000'),
        'JUG-000001' => new SalesTarget(6, 'JUG-000001', 11, 'PIS-Z1-P01-A02-N1', '3.000'),
    ];
    $tx = new SnapshotTransaction([$k->store, $k->movements, $pos]);

    return [$k, $pos, new ImportPosSales($pos, $k->movements, $pos, $tx, $k->audit, $k->clock)];
}

it('lee el CSV con coma, punto y coma o tabulador, con BOM y encabezados con acentos', function () {
    $a = PosSalesParser::parse("sku,cantidad\nLIB-000001,2\nJUG-000001,1.5\n");
    $b = PosSalesParser::parse("\xEF\xBB\xBFCódigo;Cantidad\r\nLIB-000001;2,5\r\n\r\nJUG-000001;1\r\n");
    $c = PosSalesParser::parse("sku\tunidades\nLIB-000001\t3\n");

    expect(array_map(static fn ($r) => $r->quantity, $a->rows))->toBe(['2.000', '1.500']);
    expect(array_map(static fn ($r) => $r->quantity, $b->rows))->toBe(['2.500', '1.000']);
    expect($b->rows[1]->line)->toBe(4);
    expect($c->rows[0]->quantity)->toBe('3.000');
    expect($a->errors)->toBe([]);
});

it('reporta por fila los códigos vacíos y las cantidades inválidas, y exige las columnas', function () {
    $r = PosSalesParser::parse("sku,cantidad\n,2\nLIB-000001,abc\nLIB-000001,-1\nLIB-000001,0\nLIB-000001,3\n");

    expect($r->totalRows)->toBe(5);
    expect($r->rows)->toHaveCount(1);
    expect(array_map(static fn ($e) => $e->line, $r->errors))->toBe([2, 3, 4, 5]);
    expect($r->errors[2]->message)->toContain('devoluciones');

    expect(fn () => PosSalesParser::parse("producto,precio\nA,1\n"))->toThrow(OperationsException::class, 'columnas');
    expect(fn () => PosSalesParser::parse("  \n"))->toThrow(OperationsException::class, 'vacío');
});

it('la simulación calcula todo sin tocar el stock', function () {
    [$k, $pos, $import] = posKit();

    $r = $import("sku,cantidad\nLIB-000001,2\nJUG-000001,1\n", 'ventas.csv', '2026-10-09', 7, false);

    expect($r->committed)->toBeFalse();
    expect($r->okRows)->toBe(2);
    expect($r->units)->toBe('3.000');
    expect($k->movements->requests)->toHaveCount(0);
    expect($pos->jobs[0]['commit'])->toBeFalse();
    expect($k->audit->entries[0]->action)->toBe('pos.simulated');
});

it('al confirmar descuenta del piso, agrupa los SKU repetidos y los mismos por código de barras', function () {
    [$k, , $import] = posKit();

    $r = $import("sku,cantidad\nLIB-000001,2\nJUG-000001,1\n4006381333931,3\nlib-000001,1\n", 'ventas-0910.csv', '2026-10-09', 7, true);

    expect($r->okRows)->toBe(2); // 2 productos distintos
    expect($r->units)->toBe('7.000'); // 2+3+1 de LIB y 1 de JUG
    expect($r->errors)->toBe([]);
    expect(array_map(static fn ($m) => [$m->productId, $m->quantity->value], $k->movements->requests))->toBe([[5, '6.000'], [6, '1.000']]);
    $m = $k->movements->requests[0];
    expect($m->fromLocationId)->toBe(11);
    expect($m->sourceType)->toBe('POS_IMPORT');
    expect($m->documentRef)->toBe('POS 2026-10-09');
    expect($m->idempotencyKey)->toStartWith('POS-');
    expect(strlen($m->idempotencyKey) <= 80)->toBeTrue();
    expect($k->audit->entries[0]->action)->toBe('pos.imported');
});

it('las filas con problema van al reporte y las demás se aplican', function () {
    [$k, , $import] = posKit();

    $r = $import("sku,cantidad\nLIB-000001,2\nNO-EXISTE,1\nJUG-000001,5\nLIB-000001,x\n", 'ventas.csv', '2026-10-09', 7, true);

    expect($r->okRows)->toBe(1); // solo LIB-000001: JUG pidió 5 y hay 3
    expect(array_map(static fn ($e) => $e->line, $r->errors))->toBe([3, 4, 5]);
    expect($r->errors[0]->message)->toContain('NO-EXISTE');
    expect($r->errors[1]->message)->toContain('Stock insuficiente');
    expect($k->movements->requests)->toHaveCount(1);
    expect($r->errorReportCsv())->toContain('fila;codigo;error');
    expect($r->errorReportCsv())->toContain(';"No se encontró');
});

it('el mismo archivo no se confirma dos veces, pero sí se puede simular o corregir con otro', function () {
    [$k, , $import] = posKit();
    $csv = "sku,cantidad\nLIB-000001,1\n";

    $import($csv, 'ventas.csv', '2026-10-09', 7, true);

    expect(fn () => $import($csv, 'copia.csv', '2026-10-09', 7, true))->toThrow(OperationsException::class, 'ya se importó');
    expect($import($csv, 'ventas.csv', '2026-10-09', 7, false)->committed)->toBeFalse();
    expect($import("sku,cantidad\nLIB-000001,2\n", 'correccion.csv', '2026-10-09', 7, true)->okRows)->toBe(1);
    expect($k->movements->requests)->toHaveCount(2);
});

it('valida la fecha y el nombre del archivo', function () {
    [, , $import] = posKit();
    $csv = "sku,cantidad\nLIB-000001,1\n";

    expect(fn () => $import($csv, 'v.csv', '2026-13-01', 7, true))->toThrow(OperationsException::class, 'fecha');
    expect(fn () => $import($csv, 'v.csv', '2026-12-31', 7, true))->toThrow(OperationsException::class, 'futura');
    expect(fn () => $import($csv, '  ', '2026-10-09', 7, true))->toThrow(OperationsException::class, 'nombre');
    expect($import($csv, 'C:\\ventas\\dia.csv', '2026-10-09', 7, false)->importId)->toBe(1);
});
