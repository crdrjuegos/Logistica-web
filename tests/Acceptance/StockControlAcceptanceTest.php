<?php

declare(strict_types=1);

use App\Filament\Resources\Replenishment\Pages\ListReplenishment;
use App\Filament\Resources\Reports\Abc\Pages\ListAbc;
use App\Filament\Resources\Reports\ValuedStock\Pages\ListValuedStock;
use App\Filament\Resources\Reports\ValuedStock\ValuedStockResource;
use App\Filament\Resources\Reports\Waste\Pages\ListWaste;
use App\Filament\Resources\Reports\Waste\WasteResource;
use App\Filament\Widgets\CriticalStockTable;
use App\Filament\Widgets\InventoryStats;
use App\Models\ReplenishmentSuggestion;
use App\Modules\Iam\Domain\RoleName;
use App\Modules\Inventory\Application\RegisterMovement;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Locations\Application\ChangeLocationState;
use App\Modules\Locations\Application\GenerateLocations;
use App\Modules\Locations\Domain\LocationBatch;
use App\Modules\Locations\Domain\LocationException;
use App\Modules\Operations\Application\ApproveAdjustment;
use App\Modules\Operations\Application\RequestAdjustment;
use App\Modules\Operations\Application\StartCount;
use App\Modules\Operations\Domain\AdjustmentKind;
use App\Modules\Operations\Domain\CountScopeType;
use App\Modules\Operations\Domain\NewAdjustment;
use App\Modules\Operations\Domain\NewCount;
use App\Modules\Suppliers\Application\ChangeSupplierState;
use App\Modules\Suppliers\Application\CreateSupplier;
use App\Modules\Suppliers\Application\LinkProductToSupplier;
use App\Modules\Suppliers\Domain\NewSupplier;
use App\Modules\Suppliers\Domain\Ruc;
use App\Modules\Suppliers\Domain\SupplierException;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Support\Fixtures;
use Tests\Support\MovementFactory as M;
use Tests\Support\PanelUsers;

beforeEach(function () {
    PanelUsers::seedRoles();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

function scWarehouse(string $code = 'BOD'): int
{
    return (int) DB::table('warehouses')->where('code', $code)->value('id');
}

function scPolicy(int $product, string $min, ?string $max, ?string $reorder = null, string $warehouse = 'BOD'): void
{
    DB::table('product_stock_policies')->insert([
        'product_id' => $product, 'warehouse_id' => scWarehouse($warehouse), 'min_qty' => $min, 'max_qty' => $max, 'reorder_qty' => $reorder,
    ]);
}

/** Producto con política y stock exacto en BOD. */
function scStocked(string $stock, string $min = '10', ?string $max = '20', ?string $reorder = null, string $cost = '2'): int
{
    $actor = Fixtures::user();
    $product = Fixtures::product();
    scPolicy($product, $min, $max, $reorder);
    if (bccomp($stock, '0', 3) > 0) {
        app(RegisterMovement::class)(M::purchase($product, Fixtures::location('BOD'), $stock, $cost, $actor));
    }

    return $product;
}

function scReplenishment(int $product): object
{
    return DB::table('v_replenishment')->where('product_id', $product)->firstOrFail();
}

/** Ejecuta una acción de descarga y devuelve el CSV generado. */
function scDownload(StreamedResponse $response): string
{
    ob_start();
    $response->sendContent();

    return (string) ob_get_clean();
}

function scCsvRows(string $csv): array
{
    $lines = array_values(array_filter(explode("\n", str_replace("\r", '', substr($csv, 3))), static fn (string $l): bool => $l !== ''));

    return array_map(static fn (string $l): array => str_getcsv($l, ';', '"', ''), $lines);
}

// HU-040 Ubicaciones en lote

it('CA-040-1 BOD zona 1, pasillo 3, anaquel 2, nivel 2 crea BOD-Z1-P03-A02-N2 con sus componentes', function () {
    $result = app(GenerateLocations::class)(new LocationBatch(scWarehouse(), 1, 3, 3, 2, 2, 2, 2));

    expect($result)->toBe(['created' => 1, 'skipped' => 0]);
    $loc = DB::table('locations')->where('code', 'BOD-Z1-P03-A02-N2')->first();
    expect((int) $loc->warehouse_id)->toBe(scWarehouse());
    expect([$loc->zone, $loc->aisle, $loc->rack, (int) $loc->level])->toBe(['Z1', 'P03', 'A02', 2]);
});

it('CA-040-2 más de 2000 combinaciones o pasillo inicial mayor que el final se rechazan sin lote parcial', function () {
    $before = DB::table('locations')->count();

    expect(fn () => new LocationBatch(scWarehouse(), 1, 1, 21, 1, 10, 1, 10))->toThrow(LocationException::class, '2000');
    expect(fn () => new LocationBatch(scWarehouse(), 1, 5, 3, 1, 1, 1, 1))->toThrow(LocationException::class, 'pasillos');

    expect(DB::table('locations')->count())->toBe($before);
});

it('CA-040-3 el mismo lote dos veces deja una ubicación por código e informa creadas y existentes', function () {
    $batch = new LocationBatch(scWarehouse(), 1, 3, 3, 2, 2, 2, 2);
    app(GenerateLocations::class)($batch);

    $again = app(GenerateLocations::class)($batch);

    expect($again)->toBe(['created' => 0, 'skipped' => 1]);
    expect(DB::table('locations')->where('code', 'BOD-Z1-P03-A02-N2')->count())->toBe(1);
});

// HU-041 Estados de ubicación

it('CA-041-1 congelar y liberar las ubicaciones 10 y 11 cambia todo el lote de forma atómica y auditada', function () {
    $actor = Fixtures::user();
    $a = Fixtures::location('BOD');
    $b = Fixtures::location('BOD');
    $state = app(ChangeLocationState::class);

    expect($state->freezeMany([$a, $b], $actor, 'CA-041-1'))->toBe(2);
    expect(DB::table('locations')->whereIn('id', [$a, $b])->where('is_frozen', true)->count())->toBe(2);
    expect($state->unfreezeMany([$a, $b], $actor, 'CA-041-1'))->toBe(2);
    expect(DB::table('locations')->whereIn('id', [$a, $b])->where('is_frozen', true)->count())->toBe(0);
    expect(DB::table('audit_logs')->whereIn('action', ['location.frozen_bulk', 'location.unfrozen_bulk'])->count())->toBe(2);
});

it('CA-041-2 no se desactiva una ubicación con stock, en conteo o de recepción', function () {
    $actor = Fixtures::user();
    $withStock = Fixtures::location('BOD');
    app(RegisterMovement::class)(M::purchase(Fixtures::product(), $withStock, 3, '1', $actor));
    $inCount = Fixtures::location('BOD', ['zone' => 'ZC41']);
    app(RegisterMovement::class)(M::purchase(Fixtures::product(), $inCount, 1, '1', $actor));
    app(StartCount::class)(new NewCount(scWarehouse(), CountScopeType::Zone, 'ZC41', $actor));
    $staging = (int) DB::table('locations')->where('code', 'BOD-REC')->value('id');
    $state = app(ChangeLocationState::class);

    foreach ([$withStock, $inCount, $staging] as $loc) {
        expect(fn () => $state->deactivate($loc, $actor))->toThrow(LocationException::class);
        expect((bool) DB::table('locations')->where('id', $loc)->value('is_active'))->toBeTrue();
    }
    expect(DB::table('stock_counts')->where('status', 'COUNTING')->count())->toBe(1);
});

it('CA-041-3 congelar el lote 10, 11 y 999 no modifica ninguna', function () {
    $a = Fixtures::location('BOD');
    $b = Fixtures::location('BOD');

    expect(fn () => app(ChangeLocationState::class)->freezeMany([$a, $b, 999]))->toThrow(LocationException::class);

    expect(DB::table('locations')->whereIn('id', [$a, $b])->where('is_frozen', true)->count())->toBe(0);
});

// HU-043 Proveedor preferido

function scSupplier(string $ruc, string $name): int
{
    return app(CreateSupplier::class)(new NewSupplier(Ruc::of($ruc), $name));
}

it('CA-043-1 marcar al proveedor A como preferido guarda el vínculo y como máximo un preferido', function () {
    $product = Fixtures::product();
    $a = scSupplier('20611519886', 'Proveedor A');

    app(LinkProductToSupplier::class)($product, $a, preferred: true);

    expect(DB::table('product_suppliers')->where('product_id', $product)->where('supplier_id', $a)->value('is_preferred'))->toBeTrue();
    expect(DB::table('product_suppliers')->where('product_id', $product)->where('is_preferred', true)->count())->toBe(1);
});

it('CA-043-2 proveedor inactivo o producto inexistente se rechazan sin vínculo parcial', function () {
    $actor = Fixtures::user();
    $product = Fixtures::product();
    $inactive = scSupplier('20100070970', 'Proveedor inactivo');
    app(ChangeSupplierState::class)->deactivate($inactive, $actor);
    $active = scSupplier('20611519886', 'Proveedor A');

    expect(fn () => app(LinkProductToSupplier::class)($product, $inactive, preferred: true))->toThrow(SupplierException::class);
    expect(fn () => app(LinkProductToSupplier::class)(999999, $active, preferred: true))->toThrow(SupplierException::class);

    expect(DB::table('product_suppliers')->count())->toBe(0);
});

it('CA-043-3 con A preferido, elegir B deja B preferido y A vinculado sin preferencia', function () {
    $product = Fixtures::product();
    $a = scSupplier('20611519886', 'Proveedor A');
    $b = scSupplier('20100070970', 'Proveedor B');
    app(LinkProductToSupplier::class)($product, $a, preferred: true);

    app(LinkProductToSupplier::class)($product, $b, preferred: true);

    $links = DB::table('product_suppliers')->where('product_id', $product)->pluck('is_preferred', 'supplier_id')->all();
    expect($links)->toBe([$a => false, $b => true]);
});

// HU-044 Alertas de stock

it('CA-044-1 con mínimo 10 y máximo 20, los stocks 0, 10, 12.5, 20 y 21 son OUT, LOW, NEAR, OK y OVER', function () {
    $statuses = array_map(static fn (string $stock): string => scReplenishment(scStocked($stock))->status, ['0', '10', '12.5', '20', '21']);

    expect($statuses)->toBe(['OUT', 'LOW', 'NEAR', 'OK', 'OVER']);
});

it('CA-044-2 un producto activo sin política no se clasifica como normal ni aparece en la vista; queda identificable', function () {
    $actor = Fixtures::user();
    $product = Fixtures::product();
    app(RegisterMovement::class)(M::purchase($product, Fixtures::location('BOD'), 5, '1', $actor));

    expect(DB::table('v_replenishment')->where('product_id', $product)->exists())->toBeFalse();
    $withoutPolicy = DB::table('products')->where('status', 'ACTIVE')
        ->whereNotExists(static fn ($q) => $q->from('product_stock_policies')->whereColumn('product_stock_policies.product_id', 'products.id'))
        ->pluck('id')->all();
    expect($withoutPolicy)->toContain($product);
});

it('CA-044-3 con mínimo 10 y stock 12.501 clasifica OK; el filtro BOD se respeta y los inactivos se excluyen', function () {
    $this->actingAs(PanelUsers::withRole(RoleName::Compras));
    $ok = scStocked('12.501');
    $inPiso = Fixtures::product();
    scPolicy($inPiso, '10', '20', null, 'PIS');
    $inactive = scStocked('0');
    DB::table('products')->where('id', $inactive)->update(['status' => 'INACTIVE']);

    expect(scReplenishment($ok)->status)->toBe('OK');
    expect(DB::table('v_replenishment')->where('product_id', $inactive)->exists())->toBeFalse();
    $models = static fn (array $ids) => ReplenishmentSuggestion::query()->whereIn('product_id', $ids)->get();
    Livewire::test(ListReplenishment::class)
        ->removeTableFilters()
        ->filterTable('warehouse_id', scWarehouse())
        ->assertCanSeeTableRecords($models([$ok]))
        ->assertCanNotSeeTableRecords($models([$inPiso]));
});

// HU-045 Pedido sugerido

it('CA-045-1 stock 3, mínimo 10, máximo 20, reposición 5 y proveedor preferido sugiere 17 y exporta por proveedor', function () {
    $user = PanelUsers::withRole(RoleName::Compras);
    $this->actingAs($user);
    $product = scStocked('3', '10', '20', '5');
    app(LinkProductToSupplier::class)($product, scSupplier('20611519886', 'Proveedor A'), preferred: true);

    expect((string) scReplenishment($product)->suggested_qty)->toBe('17.000');

    $component = Livewire::test(ListReplenishment::class)->callAction('export');
    $rows = scCsvRows(scDownload($component->instance()->exportResponse()));
    expect($rows[0])->toBe(['Proveedor', 'SKU', 'Producto', 'Almacén', 'Stock', 'Mínimo', 'Máximo', 'Pedir', 'Plazo (días)']);
    expect([$rows[1][0], $rows[1][7]])->toBe(['Proveedor A', '17.000']);
    expect(count($rows[1]))->toBe(count($rows[0]));
});

it('CA-045-2 un producto crítico sin preferido y sin ventas en 30 días muestra Sin proveedor y cobertura no calculable', function () {
    $this->actingAs(PanelUsers::withRole(RoleName::Compras));
    $product = scStocked('3');

    $row = scReplenishment($product);
    expect($row->supplier_name)->toBeNull();
    expect((string) $row->sold_30d)->toBe('0');
    Livewire::test(ListReplenishment::class)
        ->assertCanSeeTableRecords(ReplenishmentSuggestion::query()->where('product_id', $product)->get())
        ->assertSee('Sin proveedor')
        ->assertTableColumnStateSet('cover', '—', ReplenishmentSuggestion::query()->where('product_id', $product)->firstOrFail());
});

it('CA-045-3 con reposición 30 mayor que la diferencia (17) la regla vigente propone 30 (GREATEST)', function () {
    $product = scStocked('3', '10', '20', '30');

    expect((string) scReplenishment($product)->suggested_qty)->toBe('30.000');
});

it('CA-045-4 sin reports.export la acción de pedido sugerido queda oculta y no se puede invocar', function () {
    $reader = PanelUsers::withRole(RoleName::Lectura);
    $this->actingAs($reader);
    scStocked('3');

    expect($reader->can('alerts.view'))->toBeTrue();
    expect($reader->can('reports.export'))->toBeFalse();
    Livewire::test(ListReplenishment::class)->assertActionHidden('export');
});

// HU-046 Tablero

it('CA-046-1 con inventario valorizado, 1 agotado, 1 bajo y 1 ajuste pendiente, el tablero muestra las seis métricas coherentes', function () {
    $this->actingAs(PanelUsers::withRole(RoleName::Supervisor));
    $actor = Fixtures::user();
    scStocked('0');                       // agotado
    $low = scStocked('5', '10', '20', null, '2'); // bajo: 5 x 2 = S/10
    $loc = (int) DB::table('stock_balances')->where('product_id', $low)->value('location_id');
    app(RequestAdjustment::class)(new NewAdjustment(AdjustmentKind::WriteOff, $low, $loc, Quantity::of('1'),
        (int) DB::table('movement_reasons')->where('code', 'MERMA_DANIO')->value('id'), 'Se mojaron en bodega', $actor));

    Livewire::test(InventoryStats::class)
        ->assertSee('Valor del inventario')->assertSee('S/ 10.00')
        ->assertSee('Agotados')->assertSee('Bajo el mínimo')->assertSee('Ajustes por aprobar')
        ->assertSee('Conteos abiertos')->assertSee('Mermas del mes');
    expect(DB::table('v_replenishment')->where('status', 'OUT')->count())->toBe(1);
    expect(DB::table('v_replenishment')->where('status', 'LOW')->count())->toBe(1);
    expect(DB::table('stock_adjustment_requests')->where('status', 'PENDING')->count())->toBe(1);
});

it('CA-046-2 sin stock, ajustes, conteos ni mermas muestra ceros sin errores', function () {
    $this->actingAs(PanelUsers::withRole(RoleName::Supervisor));

    Livewire::test(InventoryStats::class)->assertOk()->assertSee('S/ 0.00')->assertDontSee('null');
    Livewire::test(CriticalStockTable::class)->assertOk();
});

it('CA-046-3 solo quien tiene alerts.view ve los widgets y la tabla de críticos', function () {
    $this->actingAs(PanelUsers::withRole(RoleName::Lectura));
    expect(InventoryStats::canView() && CriticalStockTable::canView())->toBeTrue();

    $noAlerts = PanelUsers::withRole(RoleName::Lectura);
    $noAlerts->syncRoles([]);
    $noAlerts->givePermissionTo('catalog.view');
    $this->actingAs($noAlerts->refresh());
    expect(InventoryStats::canView())->toBeFalse();
    expect(CriticalStockTable::canView())->toBeFalse();
});

// HU-048 Inventario valorizado

it('CA-048-1 10 UND a costo 2.0000 en BOD valen S/20 y el total por almacén cuadra', function () {
    $product = scStocked('10', '1', null, null, '2');

    $row = DB::table('v_report_valued_stock')->where('product_id', $product)->first();
    expect([(string) $row->qty, (string) $row->cost_avg])->toBe(['10.000', '2.0000']);
    expect(bccomp((string) $row->value, '20', 4))->toBe(0);
    $total = (string) DB::table('v_report_valued_stock')->where('warehouse_id', scWarehouse())->sum('value');
    $ledger = (string) DB::selectOne('SELECT SUM(sb.quantity * p.cost_avg) AS v FROM stock_balances sb JOIN locations l ON l.id = sb.location_id JOIN products p ON p.id = sb.product_id WHERE l.warehouse_id = ?', [scWarehouse()])->v;
    expect(bccomp($total, $ledger, 4))->toBe(0);
});

it('CA-048-2 con stock en BOD y PIS, el filtro BOD incluye solo BOD y la exportación usa ese filtro', function () {
    $this->actingAs(PanelUsers::withRole(RoleName::Supervisor));
    $actor = Fixtures::user();
    $product = Fixtures::product();
    app(RegisterMovement::class)(M::purchase($product, Fixtures::location('BOD'), 10, '2', $actor));
    app(RegisterMovement::class)(M::purchase($product, Fixtures::location('PIS'), 4, '2', $actor));

    $component = Livewire::test(ListValuedStock::class)->filterTable('warehouse_id', scWarehouse());
    $rows = scCsvRows(scDownload($component->instance()->exportResponse()));

    expect(count($rows))->toBe(2);
    expect($rows[1][3])->toBe('Bodega central');
    expect((float) $rows[1][6])->toBe(20.0);
});

it('CA-048-3 un producto sin stock no genera valor y el costo de 4 decimales se conserva', function () {
    $empty = Fixtures::product('3.3333');
    $product = scStocked('3', '1', null, null, '3.3333');

    expect(DB::table('v_report_valued_stock')->where('product_id', $empty)->exists())->toBeFalse();
    $row = DB::table('v_report_valued_stock')->where('product_id', $product)->first();
    expect((string) $row->cost_avg)->toBe('3.3333');
    expect(bccomp((string) $row->value, '9.9999', 4))->toBe(0); // se redondea solo al presentar (S/ 10.00)
});

// HU-049 ABC

function scSale(int $product, string $value, int $actor, string $daysAgo = '0'): void
{
    $loc = Fixtures::location('PIS');
    app(RegisterMovement::class)(M::purchase($product, $loc, 1, $value, $actor));
    DB::table('stock_movements')->insert([
        'uuid' => (string) Str::uuid(), 'type' => 'SALIDA_VENTA', 'product_id' => $product, 'from_location_id' => $loc,
        'quantity' => 1, 'unit_cost' => $value, 'user_id' => $actor, 'idempotency_key' => 'abc-'.Str::random(20),
        'created_at' => now()->subDays((int) $daysAgo),
    ]);
}

it('CA-049-1 ventas valorizadas 80, 15 y 5 clasifican A, B y C según el acumulado previo', function () {
    $actor = Fixtures::user();
    $p = [Fixtures::product(), Fixtures::product(), Fixtures::product()];
    scSale($p[0], '80', $actor);
    scSale($p[1], '15', $actor);
    scSale($p[2], '5', $actor);

    $classes = DB::table('v_report_abc')->whereIn('product_id', $p)->pluck('abc_class', 'product_id')->all();
    expect([$classes[$p[0]], $classes[$p[1]], $classes[$p[2]]])->toBe(['A', 'B', 'C']);
});

it('CA-049-2 un producto activo sin ventas en 90 días queda SIN_VENTAS, valor 0 y sin cobertura', function () {
    $product = Fixtures::product();

    $row = DB::table('v_report_abc')->where('product_id', $product)->first();
    expect($row->abc_class)->toBe('SIN_VENTAS');
    expect((float) $row->value_90d)->toBe(0.0);
    expect($row->cover_days)->toBeNull();
});

it('CA-049-3 dos productos con el mismo valor se ordenan por product_id y una venta de hace más de 90 días se excluye', function () {
    $actor = Fixtures::user();
    $big = Fixtures::product();
    $first = Fixtures::product();
    $second = Fixtures::product();
    $old = Fixtures::product();
    scSale($big, '90', $actor);
    scSale($second, '5', $actor); // se registra antes, pero el desempate es por product_id
    scSale($first, '5', $actor);
    scSale($old, '500', $actor, '120');

    $classes = DB::table('v_report_abc')->whereIn('product_id', [$big, $first, $second, $old])->pluck('abc_class', 'product_id')->all();
    expect($classes[$big])->toBe('A');      // acumulado previo 0 %
    expect($classes[$first])->toBe('B');    // empate: id menor primero, acumulado previo 90 %
    expect($classes[$second])->toBe('C');   // acumulado previo 95 %
    expect($classes[$old])->toBe('SIN_VENTAS'); // venta de hace 120 días: fuera del período
});

// HU-050 Mermas

function scWaste(int $requester, int $approver, string $qty = '2', string $cost = '3'): int
{
    $product = Fixtures::product();
    $loc = Fixtures::location('BOD');
    app(RegisterMovement::class)(M::purchase($product, $loc, 10, $cost, $requester));
    $request = app(RequestAdjustment::class)(new NewAdjustment(AdjustmentKind::WriteOff, $product, $loc, Quantity::of($qty),
        (int) DB::table('movement_reasons')->where('code', 'MERMA_DANIO')->value('id'), 'Se mojaron en bodega', $requester));

    return app(ApproveAdjustment::class)($request['id'], $approver, 'Verificado');
}

it('CA-050-1 MERMA de 2 UND a S/3 muestra solicitante 7, aprobador 9, motivo y valor S/6', function () {
    $requester = PanelUsers::withRole(RoleName::Almacenero, ['id' => 7, 'name' => 'Solicitante Siete']);
    $approver = PanelUsers::withRole(RoleName::Supervisor, ['id' => 9, 'name' => 'Aprobador Nueve']);

    $movement = scWaste($requester->id, $approver->id);

    $row = DB::table('v_report_waste')->where('id', $movement)->first();
    expect([$row->user_name, $row->approver_name, $row->reason_name])->toBe(['Solicitante Siete', 'Aprobador Nueve', 'Producto dañado']);
    expect(bccomp((string) $row->value, '6', 4))->toBe(0);
});

it('CA-050-2 con AJUSTE_NEGATIVO y COMPRA en un rango acotado solo se incluyen mermas y ajustes negativos del filtro', function () {
    $this->actingAs(PanelUsers::withRole(RoleName::Supervisor));
    $actor = Fixtures::user();
    $product = Fixtures::product();
    $loc = Fixtures::location('BOD');
    app(RegisterMovement::class)(M::purchase($product, $loc, 10, '2', $actor));
    app(RegisterMovement::class)(M::adjustmentOut($product, $loc, 1, (int) DB::table('movement_reasons')->where('code', 'AJU_ERROR_CONTEO')->value('id'), $actor));
    $today = now('America/Lima')->format('Y-m-d');

    $component = Livewire::test(ListWaste::class)->filterTable('period', ['from' => $today, 'until' => $today]);
    $rows = scCsvRows(scDownload($component->instance()->exportResponse()));

    expect(DB::table('v_report_waste')->pluck('type')->unique()->values()->all())->toBe(['AJUSTE_NEGATIVO']);
    expect(count($rows))->toBe(2);
    expect($rows[1][1])->toBe('Ajuste (-)');

    $outside = Livewire::test(ListWaste::class)->filterTable('period', ['from' => '2020-01-01', 'until' => '2020-01-31']);
    expect(scCsvRows(scDownload($outside->instance()->exportResponse())))->toHaveCount(1); // solo encabezado
});

it('CA-050-3 un período sin mermas para el rol lectura muestra la tabla vacía sin acciones de escritura', function () {
    $this->actingAs(PanelUsers::withRole(RoleName::Lectura));

    expect(WasteResource::canViewAny())->toBeTrue();
    expect(WasteResource::canCreate())->toBeFalse();
    Livewire::test(ListWaste::class)
        ->filterTable('period', ['from' => '2020-01-01', 'until' => '2020-01-31'])
        ->assertCountTableRecords(0)
        ->assertActionHidden('export');
});

// HU-051 Exportación

it('CA-051-1 el CSV escapa tildes, comillas y el separador dentro del nombre y Excel lo abre', function () {
    $this->actingAs(PanelUsers::withRole(RoleName::Supervisor));
    $product = scStocked('10', '1', null, null, '2');
    DB::table('products')->where('id', $product)->update(['name' => 'Cuaderno "Rayado"; Niño A4']);

    $csv = scDownload(Livewire::test(ListValuedStock::class)->instance()->exportResponse());

    expect(str_starts_with($csv, "\xEF\xBB\xBF"))->toBeTrue();
    expect($csv)->toContain('"Cuaderno ""Rayado""; Niño A4"');
    $rows = scCsvRows($csv);
    expect($rows[1][1])->toBe('Cuaderno "Rayado"; Niño A4');
    expect(count($rows[1]))->toBe(count($rows[0]));
});

it('CA-051-2 el rol lectura con reports.view ve los reportes pero no puede descargarlos', function () {
    $reader = PanelUsers::withRole(RoleName::Lectura);
    $this->actingAs($reader);

    expect($reader->can('reports.view'))->toBeTrue();
    expect($reader->can('reports.export'))->toBeFalse();
    foreach ([ListValuedStock::class, ListAbc::class, ListWaste::class] as $page) {
        Livewire::test($page)->assertOk()->assertActionHidden('export');
    }
});

it('CA-051-3 el CSV respeta el filtro BOD y no se anuncia exportación PDF', function () {
    $this->actingAs(PanelUsers::withRole(RoleName::Supervisor));
    $actor = Fixtures::user();
    $product = Fixtures::product();
    app(RegisterMovement::class)(M::purchase($product, Fixtures::location('BOD'), 10, '2', $actor));
    app(RegisterMovement::class)(M::purchase($product, Fixtures::location('PIS'), 4, '2', $actor));

    $component = Livewire::test(ListValuedStock::class)->filterTable('warehouse_id', scWarehouse());
    $rows = scCsvRows(scDownload($component->instance()->exportResponse()));

    expect(array_unique(array_column(array_slice($rows, 1), 3)))->toBe(['Bodega central']);
    $component->assertDontSee('PDF');
    expect(ValuedStockResource::canViewAny())->toBeTrue();
});
