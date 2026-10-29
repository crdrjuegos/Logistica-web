<?php

declare(strict_types=1);

use App\Filament\Resources\Brands\Pages\ManageBrands;
use App\Filament\Resources\Products\Pages\CreateProduct as CreateProductPage;
use App\Filament\Resources\Products\ProductFormMapper;
use App\Filament\Resources\Products\ProductResource;
use App\Models\Brand;
use App\Models\Category;
use App\Modules\Catalog\Application\CreateProduct;
use App\Modules\Catalog\Application\FindSimilarProducts;
use App\Modules\Catalog\Application\LookupProduct;
use App\Modules\Catalog\Application\UpdateProduct;
use App\Modules\Catalog\Domain\Barcode;
use App\Modules\Catalog\Domain\BarcodeInput;
use App\Modules\Catalog\Domain\CatalogException;
use App\Modules\Catalog\Domain\Factor;
use App\Modules\Catalog\Domain\NewProduct;
use App\Modules\Catalog\Domain\PresentationInput;
use App\Modules\Catalog\Domain\ProductChanges;
use App\Modules\Catalog\Domain\StockPolicy;
use App\Modules\Catalog\Domain\Symbology;
use App\Modules\Iam\Domain\RoleName;
use App\Modules\Inventory\Application\RegisterMovement;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Fixtures;
use Tests\Support\MovementFactory as M;
use Tests\Support\PanelUsers;

beforeEach(function () {
    PanelUsers::seedRoles();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

function catUnit(string $code): int
{
    return (int) DB::table('units')->where('code', $code)->value('id');
}

function catRoot(string $code): int
{
    return (int) DB::table('categories')->whereNull('parent_id')->where('code', $code)->value('id');
}

function catWarehouse(string $code): int
{
    return (int) DB::table('warehouses')->where('code', $code)->value('id');
}

function catProduct(string $name, ?int $actor = null, array $over = []): array
{
    return app(CreateProduct::class)(new NewProduct(
        $name, $over['category'] ?? catRoot('LIB'), $over['unit'] ?? catUnit('UND'), $actor, null, $over['brand'] ?? null, null,
        $over['presentations'] ?? [], $over['barcodes'] ?? [], $over['policies'] ?? [],
    ));
}

// HU-008 Crear producto

it('CA-008-1 supervisor crea en LIB con nombre con espacios y UND: SKU LIB-000001, nombre normalizado y presentación base 1', function () {
    $supervisor = PanelUsers::withRole(RoleName::Supervisor);
    expect($supervisor->can('catalog.write'))->toBeTrue();

    $created = catProduct('   Cuaderno    A4   100 hojas  ', $supervisor->id);

    expect($created['sku'])->toBe('LIB-000001');
    expect(DB::table('products')->where('id', $created['id'])->value('name'))->toBe('Cuaderno A4 100 hojas');
    $units = DB::table('product_units')->where('product_id', $created['id'])->get();
    expect($units)->toHaveCount(1);
    expect((int) $units[0]->unit_id)->toBe(catUnit('UND'));
    expect((string) $units[0]->factor_to_base)->toBe('1.000');
});

it('CA-008-2 categoría inexistente o inactiva, marca o unidad inexistentes: rechaza sin producto parcial', function () {
    $inactive = (int) DB::table('categories')->insertGetId(['code' => 'OLD', 'name' => 'Antigua', 'depth' => 1, 'is_active' => false], 'id');

    foreach ([
        ['category' => 9999],
        ['category' => $inactive],
        ['brand' => 9999],
        ['unit' => 9999],
    ] as $over) {
        expect(fn () => catProduct('Producto inválido', null, $over))->toThrow(CatalogException::class);
    }

    expect(DB::table('products')->count())->toBe(0);
    expect(DB::table('product_units')->count())->toBe(0);
});

it('CA-008-3 el almacenero no puede crear productos pero sí consultarlos', function () {
    $this->actingAs(PanelUsers::withRole(RoleName::Almacenero));

    expect(ProductResource::canViewAny())->toBeTrue();
    expect(ProductResource::canCreate())->toBeFalse();
    $this->get(ProductResource::getUrl('index'))->assertOk();
    $this->get(ProductResource::getUrl('create'))->assertForbidden();
});

// HU-010 Presentaciones

it('CA-010-1 base UND=1, DOC=12 y CJA=24 se guardan con factores positivos de tres decimales', function () {
    $created = catProduct('Lapicero azul', null, ['presentations' => [
        new PresentationInput(catUnit('DOC'), Factor::of(12)),
        new PresentationInput(catUnit('CJA'), Factor::of(24)),
    ]]);

    $factors = DB::table('product_units')->where('product_id', $created['id'])->orderBy('factor_to_base')->pluck('factor_to_base', 'unit_id')
        ->map(static fn ($f): string => (string) $f)->all();
    expect($factors)->toBe([catUnit('UND') => '1.000', catUnit('DOC') => '12.000', catUnit('CJA') => '24.000']);
});

it('CA-010-3 rechaza unidad base con factor 2 y la unidad DOC repetida', function () {
    expect(fn () => catProduct('Base mal', null, ['presentations' => [new PresentationInput(catUnit('UND'), Factor::of(2))]]))
        ->toThrow(CatalogException::class, 'factor 1');
    expect(fn () => catProduct('Doc repetida', null, ['presentations' => [
        new PresentationInput(catUnit('DOC'), Factor::of(12)),
        new PresentationInput(catUnit('DOC'), Factor::of(12)),
    ]]))->toThrow(CatalogException::class, 'repetidas');

    expect(DB::table('products')->count())->toBe(0);
});

// HU-011 Editar producto

it('CA-011-1 con versión 1 y nombre nuevo guarda versión 2 y audita antes y después', function () {
    $actor = Fixtures::user();
    $id = catProduct('Cuaderno A4', $actor)['id'];

    app(UpdateProduct::class)($id, new ProductChanges(1, $actor, 'Cuaderno A4 rayado'));

    $row = DB::table('products')->where('id', $id)->first();
    expect($row->name)->toBe('Cuaderno A4 rayado');
    expect((int) $row->version)->toBe(2);
    $audit = DB::table('audit_logs')->where('action', 'product.updated')->where('entity_id', $id)->first();
    expect(json_decode((string) $audit->old_values, true))->toBe(['name' => 'Cuaderno A4']);
    expect(json_decode((string) $audit->new_values, true))->toBe(['name' => 'Cuaderno A4 rayado']);
});

it('CA-011-2 con el producto en versión 2, una edición con expectedVersion=1 se rechaza y conserva lo reciente', function () {
    $actor = Fixtures::user();
    $id = catProduct('Cuaderno A4', $actor)['id'];
    app(UpdateProduct::class)($id, new ProductChanges(1, $actor, 'Cuaderno A4 rayado'));

    expect(fn () => app(UpdateProduct::class)($id, new ProductChanges(1, $actor, 'Nombre obsoleto')))
        ->toThrow(CatalogException::class, 'modificado por otra persona');

    expect(DB::table('products')->where('id', $id)->value('name'))->toBe('Cuaderno A4 rayado');
    expect((int) DB::table('products')->where('id', $id)->value('version'))->toBe(2);
});

it('CA-011-3 en versión 2 con valores idénticos no sube la versión ni duplica la auditoría', function () {
    $actor = Fixtures::user();
    $id = catProduct('Cuaderno A4', $actor)['id'];
    app(UpdateProduct::class)($id, new ProductChanges(1, $actor, 'Cuaderno A4 rayado'));

    app(UpdateProduct::class)($id, new ProductChanges(2, $actor, 'Cuaderno A4 rayado'));

    expect((int) DB::table('products')->where('id', $id)->value('version'))->toBe(2);
    expect(DB::table('audit_logs')->where('action', 'product.updated')->where('entity_id', $id)->count())->toBe(1);
});

// HU-013 Posibles duplicados

it('CA-013-1 Cuaderno A4 100 hojas con mayúsculas y espacios devuelve el candidato con similitud >= 0.8', function () {
    $id = catProduct('Cuaderno A4 100 hojas')['id'];

    $matches = app(FindSimilarProducts::class)('  CUADERNO   a4 100   HOJAS ');

    expect($matches)->toHaveCount(1);
    expect($matches[0]->id)->toBe($id);
    expect($matches[0]->similarity)->toBeGreaterThanOrEqual(0.8);
});

it('CA-013-2 existente Cuaderno A4 y búsqueda Pelota de fútbol número 5 no devuelve coincidencias', function () {
    catProduct('Cuaderno A4');

    expect(app(FindSimilarProducts::class)('Pelota de fútbol número 5'))->toBe([]);
});

it('CA-013-3 el formulario avisa del posible duplicado antes de guardar', function () {
    catProduct('Cuaderno A4 100 hojas');
    $this->actingAs(PanelUsers::withRole(RoleName::Supervisor));

    Livewire::test(CreateProductPage::class)
        ->set('data.name', 'cuaderno a4 100 hojas')
        ->assertSee('Posible duplicado')
        ->assertSee('LIB-000001');

    expect(DB::table('products')->count())->toBe(1); // solo avisa; no guarda nada
});

// HU-014 Consultar por SKU o código

it('CA-014-1 LIB-000001 o EAN13 4006381333931 devuelven el mismo producto, factor y stock BOD=10', function () {
    $actor = Fixtures::user();
    $created = catProduct('Lápiz HB', $actor, ['barcodes' => [new BarcodeInput(Barcode::of('4006381333931', Symbology::Ean13))]]);
    app(RegisterMovement::class)(M::purchase($created['id'], Fixtures::location('BOD'), 10, '2', $actor));

    $bySku = app(LookupProduct::class)('LIB-000001');
    $byEan = app(LookupProduct::class)('4006381333931');

    foreach ([$bySku, $byEan] as $result) {
        expect($result->productId)->toBe($created['id']);
        expect($result->presentationFactor)->toBe('1.000');
        expect($result->stock)->toHaveCount(1);
        expect($result->stock[0]['warehouse'])->toBe('BOD');
        expect($result->stock[0]['quantity'])->toBe('10.000');
    }
});

it('CA-014-2 el código no-existe o vacío devuelve vacío controlado sin modificar datos', function () {
    catProduct('Lápiz HB');
    $before = DB::table('products')->count();

    expect(app(LookupProduct::class)('no-existe'))->toBeNull();
    expect(app(LookupProduct::class)(''))->toBeNull();
    expect(DB::table('products')->count())->toBe($before);
});

it('CA-014-3 DOCENA-0001 identifica la docena con factor 12 y el stock sigue en unidad base', function () {
    $actor = Fixtures::user();
    $created = catProduct('Lápiz HB', $actor, [
        'presentations' => [new PresentationInput(catUnit('DOC'), Factor::of(12))],
        'barcodes' => [new BarcodeInput(Barcode::of('DOCENA-0001', Symbology::Internal), catUnit('DOC'))],
    ]);
    app(RegisterMovement::class)(M::purchase($created['id'], Fixtures::location('BOD'), 24, '1', $actor));

    $result = app(LookupProduct::class)('DOCENA-0001');

    expect($result->presentationFactor)->toBe('12.000');
    expect($result->presentationId)->toBe((int) DB::table('product_units')->where('product_id', $created['id'])->where('unit_id', catUnit('DOC'))->value('id'));
    expect($result->stock[0]['quantity'])->toBe('24.000');
});

// HU-015 Categorías y marcas

it('CA-015-1 crea LIB, hija y nieta (niveles 1 a 3) y una marca única, con auditoría', function () {
    $this->actingAs(PanelUsers::withRole(RoleName::Supervisor));
    $root = Category::query()->create(['code' => 'LBX', 'name' => 'Librería X']);
    $child = Category::query()->create(['parent_id' => $root->id, 'code' => '', 'name' => 'Cuadernos']);
    $grandchild = Category::query()->create(['parent_id' => $child->id, 'code' => '', 'name' => 'Espiralados']);
    $brand = Brand::query()->create(['name' => 'Standford']);

    expect([$root->depth, $child->depth, $grandchild->depth])->toBe([1, 2, 3]);
    expect($grandchild->parent_id)->toBe($child->id);
    expect(DB::table('audit_logs')->where('action', 'category.created')->count())->toBe(3);
    expect(DB::table('audit_logs')->where('action', 'brand.created')->where('entity_id', $brand->id)->count())->toBe(1);
});

it('CA-015-2 una hija de nivel 3 o una raíz LIB repetida se rechazan sin corromper la jerarquía', function () {
    $this->actingAs(PanelUsers::withRole(RoleName::Supervisor));
    $child = Category::query()->create(['parent_id' => catRoot('LIB'), 'code' => '', 'name' => 'Cuadernos']);
    $grandchild = Category::query()->create(['parent_id' => $child->id, 'code' => '', 'name' => 'Espiralados']);
    $before = DB::table('categories')->count();

    expect(fn () => Category::query()->create(['parent_id' => $grandchild->id, 'code' => '', 'name' => 'Nivel cuatro']))
        ->toThrow(ValidationException::class, '3 niveles');
    expect(fn () => Category::query()->create(['code' => 'LIB', 'name' => 'Librería duplicada']))
        ->toThrow(ValidationException::class, 'Ese código ya lo usa');

    expect(DB::table('categories')->count())->toBe($before);
    expect(DB::table('categories')->max('depth'))->toBe(3);
});

it('CA-015-3 el supervisor cambia el nombre de una marca y queda el valor anterior y el nuevo', function () {
    $this->actingAs($supervisor = PanelUsers::withRole(RoleName::Supervisor));
    $brand = Brand::query()->create(['name' => 'Standford']);

    Livewire::test(ManageBrands::class)
        ->mountTableAction('edit', $brand->id)
        ->set('mountedActions.0.data.name', 'Stanford')
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    expect($brand->refresh()->name)->toBe('Stanford');
    $audit = DB::table('audit_logs')->where('action', 'brand.updated')->where('entity_id', $brand->id)->first();
    expect((int) $audit->actor_id)->toBe($supervisor->id);
    expect(json_decode((string) $audit->old_values, true))->toBe(['name' => 'Standford']);
    expect(json_decode((string) $audit->new_values, true))->toBe(['name' => 'Stanford']);
});

// HU-016 Política de stock

it('CA-016-1 BOD con mínimo 10, máximo 100 y reposición 50 guarda 10.000, 100.000 y 50.000', function () {
    $created = catProduct('Cuaderno A4', null, ['policies' => [new StockPolicy(catWarehouse('BOD'), 10, 100, 50)]]);

    $policy = DB::table('product_stock_policies')->where('product_id', $created['id'])->first();
    expect((int) $policy->warehouse_id)->toBe(catWarehouse('BOD'));
    expect([(string) $policy->min_qty, (string) $policy->max_qty, (string) $policy->reorder_qty])->toBe(['10.000', '100.000', '50.000']);
});

it('CA-016-3 mínimo vacío se normaliza a 0, máximo vacío queda sin límite y reposición 0 se rechaza', function () {
    $form = static fn (?string $reorder): array => [
        'name' => 'Cuaderno A4', 'category_id' => catRoot('LIB'), 'base_unit_id' => catUnit('UND'),
        'policies' => [['warehouse_id' => catWarehouse('BOD'), 'min_qty' => '', 'max_qty' => '', 'reorder_qty' => $reorder]],
    ];

    $policy = ProductFormMapper::toNewProduct($form(''), null)->policies[0];
    expect($policy->minQty)->toBe('0.000');
    expect($policy->maxQty)->toBeNull();

    expect(fn () => ProductFormMapper::toNewProduct($form('0'), null))->toThrow(CatalogException::class, 'mayor que cero');
});
