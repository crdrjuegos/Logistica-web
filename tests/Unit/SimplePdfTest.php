<?php

declare(strict_types=1);

use App\Filament\Support\SimplePdf;
use Tests\Support\PdfAssert;

it('genera un PDF válido con encabezado, filas y pie de página', function () {
    $pdf = SimplePdf::table('Inventario valorizado', 'Generado de prueba', ['SKU', 'Producto', 'Valor'], [
        ['LIB-000001', 'Cuaderno A4', '20.00'],
        ['LIB-000002', 'Lapicero azul', '12.50'],
    ]);

    expect(str_starts_with($pdf, "%PDF-1.4\n"))->toBeTrue();
    expect(str_ends_with($pdf, "%%EOF\n"))->toBeTrue();
    expect(PdfAssert::xrefIsValid($pdf))->toBeTrue();
    expect($pdf)->toContain('Inventario valorizado')->toContain('Cuaderno A4')->toContain('LIB-000002')
        ->toContain('P'."\xE1".'gina 1 de 1');
    expect(substr_count($pdf, '/Type /Page '))->toBe(1);
});

it('codifica tildes y eñes en Windows-1252 y escapa paréntesis y barras', function () {
    $pdf = SimplePdf::table('Prueba', 'x', ['Producto'], [['Niño (A4) \\ Ñandú']]);

    expect($pdf)->toContain("Ni\xF1o \\(A4\\) \\\\ \xD1and\xFA");
    expect(PdfAssert::xrefIsValid($pdf))->toBeTrue();
});

it('reparte muchas filas en varias hojas y numera «Página X de Y»', function () {
    $rows = [];
    for ($i = 1; $i <= 200; $i++) {
        $rows[] = ['LIB-'.str_pad((string) $i, 6, '0', STR_PAD_LEFT), 'Producto número '.$i, (string) ($i * 2)];
    }

    $pdf = SimplePdf::table('Reporte largo', 'x', ['SKU', 'Producto', 'Valor'], $rows);
    $pages = substr_count($pdf, '/Type /Page ');

    expect($pages)->toBeGreaterThan(2);
    expect($pdf)->toContain('P'."\xE1".'gina 1 de '.$pages)->toContain('P'."\xE1".'gina '.$pages.' de '.$pages);
    expect($pdf)->toContain('LIB-000001')->toContain('LIB-000200');
    expect(PdfAssert::xrefIsValid($pdf))->toBeTrue();
});

it('parte los textos largos en varias líneas y trunca lo que pasa de tres', function () {
    $long = str_repeat('palabra ', 80);
    $pdf = SimplePdf::table('Texto largo', 'x', ['Nota', 'Valor'], [[$long, '1']]);

    expect(substr_count($pdf, '(palabra'))->toBeLessThanOrEqual(3);
    expect($pdf)->toContain('...');
    expect(PdfAssert::xrefIsValid($pdf))->toBeTrue();
});

it('sin filas muestra el aviso y limita a 5000 filas con la indicación del CSV', function () {
    expect(SimplePdf::table('Vacío', 'x', ['A'], []))->toContain('Sin registros para los filtros aplicados.');

    $rows = (static function () {
        for ($i = 0; $i < SimplePdf::MAX_ROWS + 10; $i++) {
            yield ['fila '.$i];
        }
    })();
    $pdf = SimplePdf::table('Enorme', 'x', ['A'], $rows);

    expect($pdf)->toContain('descargue el CSV para ver todas.')->not->toContain('fila 5000');
    expect(PdfAssert::xrefIsValid($pdf))->toBeTrue();
});
