<?php

declare(strict_types=1);

use App\Modules\Catalog\Application\ImportCatalog;
use App\Modules\Catalog\Domain\CatalogException;
use App\Modules\Iam\Domain\RoleName;
use Illuminate\Support\Facades\DB;
use Tests\Support\Fixtures;
use Tests\Support\PanelUsers;

beforeEach(fn () => PanelUsers::seedRoles());

// HU-052 Importar el catálogo desde un archivo

it('CA-052-1 el supervisor simula un CSV con categoría LIB, UND, nombre y EAN válidos: se valida fila a fila y no se inserta nada', function () {
    $supervisor = PanelUsers::withRole(RoleName::Supervisor);
    expect($supervisor->can('catalog.import'))->toBeTrue();
    $before = DB::table('products')->count();
    $csv = "nombre;categoria;unidad;ean\nCuaderno rayado A5;LIB;UND;4006381333931\nLapicero azul punta fina;LIB;UND;5901234123457\n";

    $result = app(ImportCatalog::class)($csv, 'catalogo.csv', $supervisor->id, false);

    expect([$result->committed, $result->totalRows, $result->okRows, $result->errors, $result->skus])->toBe([false, 2, 2, [], []]);
    expect(DB::table('products')->count())->toBe($before);
    expect(DB::table('product_barcodes')->whereIn('barcode', ['4006381333931', '5901234123457'])->count())->toBe(0);
    $job = DB::table('import_jobs')->where('kind', 'PRODUCTS')->first();
    expect([$job->mode, (int) $job->total_rows, (int) $job->ok_rows, (int) $job->error_rows])->toBe(['DRY_RUN', 2, 2, 0]);
    expect(DB::table('audit_logs')->where('action', 'catalog.simulated')->count())->toBe(1);
});

it('CA-052-2 las filas con EAN incorrecto, categoría inexistente, SKU duplicado y costo negativo devuelven su fila y causa y ninguna se guarda en silencio', function () {
    $supervisor = PanelUsers::withRole(RoleName::Supervisor);
    $existingSku = (string) DB::table('products')->where('id', Fixtures::product())->value('sku');
    $csv = implode("\n", [
        'nombre;categoria;unidad;ean;costo;sku',
        'Producto con EAN malo;LIB;UND;4006381333932;;',
        'Producto sin categoría;ZZZ;UND;;;',
        'Producto con SKU repetido;LIB;UND;;;'.$existingSku,
        'Producto con costo negativo;LIB;UND;;-5;',
        'Producto correcto;LIB;UND;5901234123457;3,50;',
    ])."\n";
    $before = DB::table('products')->count();

    $simulated = app(ImportCatalog::class)($csv, 'mixto.csv', $supervisor->id, false);

    expect(array_map(static fn ($e): int => $e->line, $simulated->errors))->toBe([2, 3, 4, 5]);
    expect($simulated->errors[0]->message)->toContain('dígito verificador');
    expect($simulated->errors[1]->message)->toContain('categoría');
    expect($simulated->errors[2]->message)->toContain('ya existe');
    expect($simulated->errors[3]->message)->toContain('costo no puede ser negativo');
    expect($simulated->okRows)->toBe(1);
    expect(DB::table('products')->count())->toBe($before);

    $confirmed = app(ImportCatalog::class)($csv, 'mixto.csv', $supervisor->id, true);

    expect([$confirmed->okRows, count($confirmed->errors)])->toBe([1, 4]);
    expect(DB::table('products')->count())->toBe($before + 1);
    expect(DB::table('products')->where('name', 'like', 'Producto con%')->orWhere('name', 'Producto sin categoría')->count())->toBe(0);
    expect($confirmed->errorReportCsv())->toContain('fila;codigo;error')->toContain('4006381333932')->toContain($existingSku);
});

it('CA-052-3 el usuario con catalog.import confirma el archivo validado y al repetir el mismo contenido no se duplica nada; queda auditoría y resumen', function () {
    $supervisor = PanelUsers::withRole(RoleName::Supervisor);
    $csv = "nombre;categoria;unidad;ean;precio\nCuaderno rayado A5;LIB;UND;4006381333931;4,50\nPelota de fútbol N°5;JUG;UND;5901234123457;18\n";
    $before = DB::table('products')->count();

    $result = app(ImportCatalog::class)($csv, 'catalogo.csv', $supervisor->id, true);

    expect([$result->committed, $result->okRows, count($result->skus), $result->errors])->toBe([true, 2, 2, []]);
    expect($result->skus[0])->toStartWith('LIB-');
    expect($result->skus[1])->toStartWith('JUG-');
    $product = DB::table('products')->where('sku', $result->skus[0])->first();
    expect([$product->name, (string) $product->reference_price])->toBe(['Cuaderno rayado A5', '4.50']);
    expect(DB::table('product_barcodes')->where('product_id', $product->id)->value('barcode'))->toBe('4006381333931');
    $job = DB::table('import_jobs')->where('kind', 'PRODUCTS')->where('mode', 'COMMIT')->first();
    expect([(int) $job->total_rows, (int) $job->ok_rows, (int) $job->error_rows, (int) $job->created_by])->toBe([2, 2, 0, $supervisor->id]);
    $audit = DB::table('audit_logs')->where('action', 'catalog.imported')->first();
    expect((int) $audit->actor_id)->toBe($supervisor->id);
    expect(DB::table('products')->count())->toBe($before + 2);

    expect(fn () => app(ImportCatalog::class)($csv, 'catalogo-copia.csv', $supervisor->id, true))
        ->toThrow(CatalogException::class, 'ya se importó');
    expect(DB::table('products')->count())->toBe($before + 2);
    expect(DB::table('import_jobs')->where('kind', 'PRODUCTS')->where('mode', 'COMMIT')->count())->toBe(1);

    expect(PanelUsers::withRole(RoleName::Lectura)->can('catalog.import'))->toBeFalse();
    expect(PanelUsers::withRole(RoleName::Almacenero)->can('catalog.import'))->toBeFalse();
});
