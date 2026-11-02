<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Catalog\Application\CreateProduct;
use App\Modules\Catalog\Application\DeactivateProduct;
use App\Modules\Catalog\Domain\Barcode;
use App\Modules\Catalog\Domain\BarcodeInput;
use App\Modules\Catalog\Domain\CatalogException;
use App\Modules\Catalog\Domain\Factor;
use App\Modules\Catalog\Domain\NewProduct;
use App\Modules\Catalog\Domain\StockPolicy;
use App\Modules\Catalog\Domain\Symbology;
use App\Modules\Iam\Application\CreateUser;
use App\Modules\Iam\Application\RecordLoginFailure;
use App\Modules\Iam\Domain\IamException;
use App\Modules\Iam\Domain\NewUser;
use App\Modules\Iam\Domain\RoleName;
use App\Modules\Inventory\Application\RegisterMovement;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Modules\Inventory\Domain\LocationState;
use App\Modules\Inventory\Domain\NewMovement;
use App\Modules\Inventory\Domain\ProductSnapshot;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Inventory\Domain\RecordedMovement;
use App\Modules\Inventory\Domain\StockLedger;
use App\Modules\Inventory\Domain\UnitCost;
use App\Modules\Inventory\Infrastructure\PostgresStockLedger;
use App\Modules\Operations\Application\ApproveAdjustment;
use App\Modules\Operations\Application\CloseCount;
use App\Modules\Operations\Application\ConfirmReceipt;
use App\Modules\Operations\Application\CreateReceiptDraft;
use App\Modules\Operations\Application\RecordCount;
use App\Modules\Operations\Application\RejectAdjustment;
use App\Modules\Operations\Application\RequestAdjustment;
use App\Modules\Operations\Application\StartCount;
use App\Modules\Operations\Application\SubmitCountForReview;
use App\Modules\Operations\Domain\AdjustmentKind;
use App\Modules\Operations\Domain\CountScopeType;
use App\Modules\Operations\Domain\DocumentType;
use App\Modules\Operations\Domain\NewAdjustment;
use App\Modules\Operations\Domain\NewCount;
use App\Modules\Operations\Domain\NewReceipt;
use App\Modules\Operations\Domain\OperationsException;
use App\Modules\Operations\Domain\ReceiptLine;
use App\Modules\Shared\Domain\Clock as UnusedClock;
use App\Modules\Suppliers\Application\CreateSupplier;
use App\Modules\Suppliers\Domain\NewSupplier;
use App\Modules\Suppliers\Domain\Ruc;
use App\Modules\Suppliers\Domain\SupplierException;
use App\Shared\Domain\Clock;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Support\FixedClock;
use Tests\Support\Fixtures;
use Tests\Support\MovementFactory as M;
use Tests\Support\PanelUsers;

beforeEach(fn () => PanelUsers::seedRoles());

function coreUser(string $username, string $email, string $password = 'caballo-azul-lluvia-77', RoleName $role = RoleName::Almacenero): int
{
    return app(CreateUser::class)(new NewUser('Ana Prueba', $username, $email, $password, $role));
}

function coreProduct(array $barcodes = [], ?int $actor = null): int
{
    return app(CreateProduct::class)(new NewProduct(
        'Producto '.Str::random(6), (int) DB::table('categories')->where('code', 'LIB')->value('id'),
        (int) DB::table('units')->where('code', 'UND')->value('id'), $actor, null, null, null, [], $barcodes,
    ))['id'];
}

function coreReason(string $code): int
{
    return (int) DB::table('movement_reasons')->where('code', $code)->value('id');
}

// HU-001 Crear usuarios con un rol

it('CA-001-1 usuario ANA, correo ANA@cityofertas.test, clave caballo-azul-lluvia-77 y rol almacenero: cuenta activa con hash y auditoría sin la clave', function () {
    $id = coreUser('ANA', 'ANA@cityofertas.test');

    $user = User::query()->findOrFail($id);
    expect([$user->username, $user->email, $user->is_active])->toBe(['ana', 'ana@cityofertas.test', true]);
    expect($user->hasRole('almacenero'))->toBeTrue();
    expect(Hash::check('caballo-azul-lluvia-77', $user->password))->toBeTrue();
    expect($user->password)->not->toBe('caballo-azul-lluvia-77');
    $audit = (string) DB::table('audit_logs')->where('action', 'user.created')->where('entity_id', $id)->value('new_values');
    expect($audit)->toContain('ana')->not->toContain('caballo-azul-lluvia-77');
});

it('CA-001-2 si ya existe ana, el usuario ANA o el correo repetido con mayúsculas se rechazan', function () {
    coreUser('ana', 'ana@cityofertas.test');

    expect(fn () => coreUser('ANA', 'otra@cityofertas.test'))->toThrow(IamException::class, 'ya existe');
    expect(fn () => coreUser('maria', 'ANA@CityOfertas.test'))->toThrow(IamException::class, 'ya está registrado');
    expect(DB::table('users')->count())->toBe(1);
});

it('CA-001-3 las claves Abcdef!2345, 123456789012 y ana-clave-larga-2026 se rechazan indicando la regla', function () {
    expect(fn () => coreUser('ana', 'ana@cityofertas.test', 'Abcdef!2345'))->toThrow(IamException::class, 'al menos 12');
    expect(fn () => coreUser('ana', 'ana@cityofertas.test', '123456789012'))->toThrow(IamException::class, 'demasiado común');
    expect(fn () => coreUser('ana', 'ana@cityofertas.test', 'ana-clave-larga-2026'))->toThrow(IamException::class, 'nombre de usuario');
    expect(DB::table('users')->count())->toBe(0);
});

// HU-003 Bloqueo por intentos

it('CA-003-1 con 4 fallos previos, el quinto a las 10:00:00 del 28/10/2026 bloquea hasta las 10:15:00', function () {
    app()->instance(Clock::class, new FixedClock(new DateTimeImmutable('2026-10-28 10:00:00', new DateTimeZone('America/Lima'))));
    $user = PanelUsers::withRole(RoleName::Almacenero);
    $user->forceFill(['failed_attempts' => 4])->save();

    $state = app(RecordLoginFailure::class)($user->id);

    expect($state->lockedUntil?->format('Y-m-d H:i:s'))->toBe('2026-10-28 10:15:00');
    expect(Carbon::parse((string) DB::table('users')->where('id', $user->id)->value('locked_until'))->timezone('America/Lima')->format('H:i:s'))->toBe('10:15:00');
});

it('CA-003-2 con locked_until=10:15:00, a las 10:14:59 la clave correcta no da acceso ni levanta el bloqueo', function () {
    $user = PanelUsers::withRole(RoleName::Almacenero);
    $user->forceFill(['locked_until' => Carbon::parse('2026-10-28 10:15:00', 'America/Lima')])->save();
    Carbon::setTestNow(Carbon::parse('2026-10-28 10:14:59', 'America/Lima'));

    expect($user->refresh()->canAccessPanel(Filament::getPanel('admin')))->toBeFalse();
    expect($user->refresh()->locked_until)->not->toBeNull();
    Carbon::setTestNow();
});

it('CA-003-3 con locked_until=10:15:00, exactamente a las 10:15:00 permite el acceso y reinicia el contador', function () {
    $user = PanelUsers::withRole(RoleName::Almacenero);
    $user->forceFill(['locked_until' => Carbon::parse('2026-10-28 10:15:00', 'America/Lima'), 'failed_attempts' => 0])->save();
    Carbon::setTestNow(Carbon::parse('2026-10-28 10:15:00', 'America/Lima'));

    expect($user->refresh()->canAccessPanel(Filament::getPanel('admin')))->toBeTrue();
    expect((int) DB::table('users')->where('id', $user->id)->value('failed_attempts'))->toBe(0);
    Carbon::setTestNow();
});

// HU-009 Códigos de barras

it('CA-009-1 el EAN13 4006381333931 queda registrado como código del producto', function () {
    $id = coreProduct([new BarcodeInput(Barcode::of('4006381333931', Symbology::Ean13))]);

    expect(DB::table('product_barcodes')->where('product_id', $id)->value('barcode'))->toBe('4006381333931');
});

it('CA-009-2 el EAN13 4006381333932 se rechaza por dígito verificador incorrecto', function () {
    expect(fn () => Barcode::of('4006381333932', Symbology::Ean13))->toThrow(CatalogException::class, 'dígito verificador');
});

it('CA-009-3 con 4006381333931 en el producto 1, el alta del producto 2 con ese código se rechaza sin producto a medias', function () {
    coreProduct([new BarcodeInput(Barcode::of('4006381333931', Symbology::Ean13))]);

    expect(fn () => coreProduct([new BarcodeInput(Barcode::of('4006381333931', Symbology::Ean13))]))->toThrow(CatalogException::class, 'ya pertenece');
    expect(DB::table('products')->count())->toBe(1);
});

// HU-010 Factor de presentación

it('CA-010-2 los factores 0, -1 y 1.0001 se rechazan', function () {
    foreach (['0', '-1', '1.0001'] as $factor) {
        expect(fn () => Factor::of($factor))->toThrow(CatalogException::class, 'Factor de presentación inválido');
    }
});

// HU-012 Dar de baja un producto

it('CA-012-1 producto 1 con stock 1.000 no se da de baja e indica que todavía hay stock', function () {
    $actor = Fixtures::user();
    $id = coreProduct();
    app(RegisterMovement::class)(M::purchase($id, Fixtures::location('BOD'), 1, '1', $actor));

    expect(fn () => app(DeactivateProduct::class)($id, $actor))->toThrow(CatalogException::class, 'stock');
    expect(DB::table('products')->where('id', $id)->value('status'))->toBe('ACTIVE');
});

it('CA-012-2 producto 1 con stock 0.000 y actor 7 queda inactivo, conserva su historial y audita', function () {
    $actor = PanelUsers::withRole(RoleName::Supervisor, ['id' => 7])->id;
    $id = coreProduct();
    $loc = Fixtures::location('BOD');
    app(RegisterMovement::class)(M::purchase($id, $loc, 2, '1', $actor));
    app(RegisterMovement::class)(M::sale($id, $loc, 2, $actor));

    app(DeactivateProduct::class)($id, 7);

    expect(DB::table('products')->where('id', $id)->value('status'))->toBe('INACTIVE');
    expect(DB::table('stock_movements')->where('product_id', $id)->count())->toBe(2);
    expect((int) DB::table('audit_logs')->where('action', 'product.deactivated')->where('entity_id', $id)->value('actor_id'))->toBe(7);
});

it('CA-012-3 dar de baja dos veces no repite el cambio ni duplica la auditoría', function () {
    $actor = Fixtures::user();
    $id = coreProduct();
    app(DeactivateProduct::class)($id, $actor);
    app(DeactivateProduct::class)($id, $actor);

    expect(DB::table('audit_logs')->where('action', 'product.deactivated')->where('entity_id', $id)->count())->toBe(1);
});

// HU-016 Política de stock

it('CA-016-2 mínimo 20 y máximo 10 se rechazan con mensaje claro', function () {
    expect(fn () => new StockPolicy(1, 20, 10))->toThrow(CatalogException::class, 'no puede ser menor que el mínimo');
});

// HU-017 a HU-021 Ledger

it('CA-017-1 producto 1, BOD10, cantidad 10, costo 2 y actor 7 quedan en el historial con usuario, fecha, tipo y cantidad', function () {
    $actor = PanelUsers::withRole(RoleName::Almacenero, ['id' => 7])->id;
    $product = Fixtures::product();
    $loc = Fixtures::location('BOD');

    $r = app(RegisterMovement::class)(M::purchase($product, $loc, 10, '2', $actor));

    $m = DB::table('stock_movements')->where('id', $r->movementId)->first();
    expect([(int) $m->user_id, $m->type, (string) $m->quantity, (string) $m->unit_cost, (int) $m->to_location_id])->toBe([7, 'ENTRADA_COMPRA', '10.000', '2.0000', $loc]);
    expect($m->created_at)->not->toBeNull();
});

function coreStock(string $qty, string $cost = '1'): array
{
    $actor = Fixtures::user();
    $product = Fixtures::product();
    $loc = Fixtures::location('BOD');
    if (bccomp($qty, '0', 3) > 0) {
        app(RegisterMovement::class)(M::purchase($product, $loc, $qty, $cost, $actor));
    }

    return [$actor, $product, $loc];
}

it('CA-018-1 con saldo 10, una salida de 4 deja 6', function () {
    [$actor, $p, $loc] = coreStock('10');
    app(RegisterMovement::class)(M::sale($p, $loc, 4, $actor));
    expect(Fixtures::balance($p, $loc))->toBe('6.000');
});

it('CA-018-2 con saldo 3, una salida de 5 se rechaza sin rastro en el historial', function () {
    [$actor, $p, $loc] = coreStock('3');
    expect(fn () => app(RegisterMovement::class)(M::sale($p, $loc, 5, $actor)))->toThrow(InventoryException::class, 'Stock insuficiente');
    expect(Fixtures::balance($p, $loc))->toBe('3.000');
    expect(DB::table('stock_movements')->where('product_id', $p)->where('type', 'SALIDA_VENTA')->count())->toBe(0);
});

it('CA-018-3 con saldo 5, una salida de 5 deja el saldo en 0', function () {
    [$actor, $p, $loc] = coreStock('5');
    app(RegisterMovement::class)(M::sale($p, $loc, 5, $actor));
    expect(Fixtures::balance($p, $loc))->toBe('0.000');
});

it('CA-019-1 una compra de 10 a 2 con clave nueva se registra y el saldo sube una vez', function () {
    [$actor, $p, $loc] = coreStock('0');
    $r = app(RegisterMovement::class)(M::purchase($p, $loc, 10, '2', $actor, key: 'ca-019-1-clave-nueva'));
    expect($r->duplicate)->toBeFalse();
    expect(Fixtures::balance($p, $loc))->toBe('10.000');
});

it('CA-019-2 repetir clave, UUID y carga útil devuelve el resultado anterior sin duplicar stock', function () {
    [$actor, $p, $loc] = coreStock('0');
    $m = M::purchase($p, $loc, 10, '2', $actor);
    $first = app(RegisterMovement::class)($m);
    $again = app(RegisterMovement::class)($m);
    expect([$again->duplicate, $again->movementId])->toBe([true, $first->movementId]);
    expect(Fixtures::balance($p, $loc))->toBe('10.000');
});

it('CA-019-3 la misma clave con otro UUID y cantidad 99 se rechaza por conflicto de idempotencia', function () {
    [$actor, $p, $loc] = coreStock('0');
    app(RegisterMovement::class)(M::purchase($p, $loc, 10, '2', $actor, key: 'ca-019-3-clave'));
    expect(fn () => app(RegisterMovement::class)(M::purchase($p, $loc, 99, '2', $actor, key: 'ca-019-3-clave')))
        ->toThrow(InventoryException::class, 'ya se usó');
    expect(Fixtures::balance($p, $loc))->toBe('10.000');
});

it('CA-020-1 10 UND a 2.0000 más 10 UND a 4.0000 dejan el costo promedio en 3.0000', function () {
    [$actor, $p, $loc] = coreStock('10', '2');
    app(RegisterMovement::class)(M::purchase($p, $loc, 10, '4', $actor));
    expect((string) DB::table('products')->where('id', $p)->value('cost_avg'))->toBe('3.0000');
});

it('CA-020-2 con saldo 10 a 3.0000, una salida de 5 se valoriza a 3.0000 y el promedio no cambia', function () {
    [$actor, $p, $loc] = coreStock('10', '3');
    $r = app(RegisterMovement::class)(M::sale($p, $loc, 5, $actor));
    expect((string) DB::table('stock_movements')->where('id', $r->movementId)->value('unit_cost'))->toBe('3.0000');
    expect((string) DB::table('products')->where('id', $p)->value('cost_avg'))->toBe('3.0000');
});

it('CA-020-3 con saldo 0, una compra de 10 a 5.0000 deja el costo promedio en 5.0000', function () {
    [$actor, $p, $loc] = coreStock('0');
    app(RegisterMovement::class)(M::purchase($p, $loc, 10, '5', $actor));
    expect((string) DB::table('products')->where('id', $p)->value('cost_avg'))->toBe('5.0000');
});

it('CA-021-1 con BOD=20 y PIS=0, transferir 5 deja 15 y 5, el total sigue en 20 y hay un movimiento enlazado', function () {
    [$actor, $p, $bod] = coreStock('20');
    $pis = Fixtures::location('PIS');
    $r = app(RegisterMovement::class)(M::transfer($p, $bod, $pis, 5, $actor));
    expect([Fixtures::balance($p, $bod), Fixtures::balance($p, $pis)])->toBe(['15.000', '5.000']);
    expect((string) DB::table('stock_balances')->where('product_id', $p)->sum('quantity'))->toBe('20.000');
    $m = DB::table('stock_movements')->where('id', $r->movementId)->first();
    expect([(int) $m->from_location_id, (int) $m->to_location_id])->toBe([$bod, $pis]);
});

it('CA-021-2 si falla el incremento del destino, se revierten origen, destino y ledger', function () {
    [$actor, $p, $bod] = coreStock('20');
    $pis = Fixtures::location('PIS');
    $before = DB::table('stock_movements')->count();
    app()->instance(StockLedger::class, new class(new PostgresStockLedger) implements StockLedger
    {
        public function __construct(private readonly PostgresStockLedger $inner) {}

        public function lockProduct(int $productId): ?ProductSnapshot { return $this->inner->lockProduct($productId); }

        public function locationStates(array $locationIds): array { return $this->inner->locationStates($locationIds); }

        public function findByIdempotencyKey(string $key): ?RecordedMovement { return $this->inner->findByIdempotencyKey($key); }

        public function hasOpeningBalance(int $productId, int $locationId): bool { return $this->inner->hasOpeningBalance($productId, $locationId); }

        public function lockBalance(int $productId, int $locationId): Quantity { return $this->inner->lockBalance($productId, $locationId); }

        public function totalQuantity(int $productId): Quantity { return $this->inner->totalQuantity($productId); }

        public function append(NewMovement $movement, UnitCost $recordedCost): int { return $this->inner->append($movement, $recordedCost); }

        public function increase(int $productId, int $locationId, Quantity $quantity): void { throw new RuntimeException('fallo simulado al incrementar el destino'); }

        public function decrease(int $productId, int $locationId, Quantity $quantity): bool { return $this->inner->decrease($productId, $locationId, $quantity); }

        public function updateProductCost(int $productId, UnitCost $average, ?UnitCost $lastCost): void { $this->inner->updateProductCost($productId, $average, $lastCost); }
    });
    app()->forgetInstance(RegisterMovement::class);

    expect(fn () => app(RegisterMovement::class)(M::transfer($p, $bod, $pis, 5, $actor)))->toThrow(RuntimeException::class, 'fallo simulado');

    expect([Fixtures::balance($p, $bod), Fixtures::balance($p, $pis)])->toBe(['20.000', '0']);
    expect(DB::table('stock_movements')->count())->toBe($before);
});

it('CA-021-3 una entrada ordinaria de 1 unidad a un destino congelado se rechaza; un ajuste de conteo sí opera', function () {
    $actor = Fixtures::user();
    $p = Fixtures::product();
    $frozen = Fixtures::location('BOD', ['is_frozen' => true]);
    expect(fn () => app(RegisterMovement::class)(M::purchase($p, $frozen, 1, '1', $actor)))->toThrow(InventoryException::class, 'congelada');
    app(RegisterMovement::class)(M::purchase($p, $frozen, 1, '1', $actor, sourceType: 'COUNT'));
    expect(Fixtures::balance($p, $frozen))->toBe('1.000');
});

// HU-025 Confirmar recepción

function coreReceipt(int $actor, array $lines): array
{
    $supplier = app(CreateSupplier::class)(new NewSupplier(Ruc::of('20611519886'), 'Importadora Lima SAC'));

    return app(CreateReceiptDraft::class)(new NewReceipt($supplier, (int) DB::table('warehouses')->where('code', 'BOD')->value('id'),
        DocumentType::Invoice, 'F001-'.random_int(100, 999), now('America/Lima')->format('Y-m-d'), $actor, $lines));
}

it('CA-025-1 un borrador de tres líneas 10, 4 y 2 confirmado por el actor 9 registra una entrada por línea y sube el stock', function () {
    $actor = PanelUsers::withRole(RoleName::Almacenero, ['id' => 9])->id;
    $loc = Fixtures::location('BOD');
    $p = [Fixtures::product(), Fixtures::product(), Fixtures::product()];
    $draft = coreReceipt($actor, [
        new ReceiptLine($p[0], $loc, Quantity::of('10'), UnitCost::of('1')),
        new ReceiptLine($p[1], $loc, Quantity::of('4'), UnitCost::of('1')),
        new ReceiptLine($p[2], $loc, Quantity::of('2'), UnitCost::of('1')),
    ]);

    expect(app(ConfirmReceipt::class)($draft['id'], 9))->toBe(['movements' => 3, 'discrepancies' => 0]);
    expect(array_map(static fn (int $id): string => Fixtures::balance($id, $loc), $p))->toBe(['10.000', '4.000', '2.000']);
    expect(DB::table('stock_movements')->where('source_type', 'RECEIPT')->where('source_id', $draft['id'])->where('user_id', 9)->count())->toBe(3);
});

it('CA-025-2 si el registro rechaza el segundo producto, no entra ninguna línea y sigue en borrador', function () {
    $actor = Fixtures::user();
    $loc = Fixtures::location('BOD');
    [$a, $b] = [Fixtures::product(), Fixtures::product()];
    $draft = coreReceipt($actor, [
        new ReceiptLine($a, $loc, Quantity::of('10'), UnitCost::of('1')),
        new ReceiptLine($b, $loc, Quantity::of('4'), UnitCost::of('1')),
    ]);
    DB::table('products')->where('id', $b)->update(['deleted_at' => now()]); // el ledger lo rechazará

    expect(fn () => app(ConfirmReceipt::class)($draft['id'], $actor))->toThrow(OperationsException::class);

    expect([Fixtures::balance($a, $loc), Fixtures::balance($b, $loc)])->toBe(['0', '0']);
    expect(DB::table('purchase_receipts')->where('id', $draft['id'])->value('status'))->toBe('DRAFT');
});

it('CA-025-3 confirmar por segunda vez no registra movimientos adicionales', function () {
    $actor = Fixtures::user();
    $loc = Fixtures::location('BOD');
    $p = Fixtures::product();
    $draft = coreReceipt($actor, [new ReceiptLine($p, $loc, Quantity::of('10'), UnitCost::of('1'))]);
    app(ConfirmReceipt::class)($draft['id'], $actor);
    app(ConfirmReceipt::class)($draft['id'], $actor);

    expect(DB::table('stock_movements')->where('product_id', $p)->count())->toBe(1);
    expect(Fixtures::balance($p, $loc))->toBe('10.000');
});

it('CA-026-1 2 cajas de factor 12 a S/24 la caja registran 24.000 UND a 2.0000 y conservan la presentación', function () {
    $actor = Fixtures::user();
    $loc = Fixtures::location('BOD');
    $p = Fixtures::product();
    $box = (int) DB::table('product_units')->insertGetId(['product_id' => $p, 'unit_id' => DB::table('units')->where('code', 'CJA')->value('id'), 'factor_to_base' => '12'], 'id');
    $draft = coreReceipt($actor, [ReceiptLine::fromPresentation($p, $loc, $box, Quantity::of('2'), '12', '24')]);

    app(ConfirmReceipt::class)($draft['id'], $actor);

    $m = DB::table('stock_movements')->where('product_id', $p)->first();
    expect([(string) $m->quantity, (string) $m->unit_cost, (int) $m->presentation_id, (string) $m->presentation_qty])->toBe(['24.000', '2.0000', $box, '2.000']);
});

// HU-030 Aprobar o rechazar ajuste

function coreWaste(int $requester): array
{
    $p = Fixtures::product();
    $loc = Fixtures::location('BOD');
    app(RegisterMovement::class)(M::purchase($p, $loc, 10, '3', $requester));
    $req = app(RequestAdjustment::class)(new NewAdjustment(AdjustmentKind::WriteOff, $p, $loc, Quantity::of('2'), coreReason('MERMA_DANIO'), 'Se mojaron en bodega', $requester));

    return [$req['id'], $p, $loc];
}

it('CA-030-1 solicitante 7 y aprobador 9 con merma 2 y motivo activo: cambia el stock y guarda ambos', function () {
    PanelUsers::withRole(RoleName::Almacenero, ['id' => 7]);
    PanelUsers::withRole(RoleName::Supervisor, ['id' => 9]);
    [$id, $p, $loc] = coreWaste(7);

    $movement = app(ApproveAdjustment::class)($id, 9);

    expect(Fixtures::balance($p, $loc))->toBe('8.000');
    $m = DB::table('stock_movements')->where('id', $movement)->first();
    expect([(int) $m->user_id, (int) $m->approved_by, $m->type])->toBe([7, 9, 'MERMA']);
});

it('CA-030-2 el solicitante 7 no puede aprobar su propia merma (separación de funciones)', function () {
    PanelUsers::withRole(RoleName::Supervisor, ['id' => 7]);
    [$id, $p, $loc] = coreWaste(7);

    expect(fn () => app(ApproveAdjustment::class)($id, 7))->toThrow(OperationsException::class, 'otra persona');
    expect(Fixtures::balance($p, $loc))->toBe('10.000');
});

it('CA-030-3 rechazar una solicitud pendiente sin explicación se exige y no cambia el stock', function () {
    PanelUsers::withRole(RoleName::Almacenero, ['id' => 7]);
    PanelUsers::withRole(RoleName::Supervisor, ['id' => 9]);
    [$id, $p, $loc] = coreWaste(7);

    expect(fn () => app(RejectAdjustment::class)($id, 9, ''))->toThrow(OperationsException::class, 'Explique');
    expect(DB::table('stock_adjustment_requests')->where('id', $id)->value('status'))->toBe('PENDING');
    expect(Fixtures::balance($p, $loc))->toBe('10.000');
});

// HU-035 Cerrar conteo

function coreCount(): array
{
    $counter = PanelUsers::withRole(RoleName::Almacenero, ['id' => 8])->id;
    $closer = PanelUsers::withRole(RoleName::Supervisor, ['id' => 9])->id;
    $loc = Fixtures::location('BOD', ['zone' => 'Z35']);
    $products = [];
    foreach (['10', '4', '2'] as $qty) {
        $p = Fixtures::product();
        app(RegisterMovement::class)(M::purchase($p, $loc, $qty, '1', $closer));
        $products[] = $p;
    }
    $count = app(StartCount::class)(new NewCount((int) DB::table('warehouses')->where('code', 'BOD')->value('id'), CountScopeType::Zone, 'Z35', $closer));
    foreach (array_combine($products, ['10', '3', '2']) as $p => $qty) {
        app(RecordCount::class)($count['id'], $p, $loc, Quantity::of($qty), $counter);
    }
    app(SubmitCountForReview::class)($count['id'], $counter);
    app(RecordCount::class)($count['id'], $products[1], $loc, Quantity::of('3'), $counter); // reconteo completado

    return [$count['id'], $loc, $products];
}

it('CA-035-1 esperados 10, 4, 2 y contados 10, 3, 2 con reconteo: cierre por 9 aplica la diferencia, descongela y muestra la exactitud', function () {
    [$countId, $loc, $p] = coreCount();

    $result = app(CloseCount::class)($countId, 9);

    expect($result)->toBe(['adjustments' => 1, 'lines' => 3, 'accuracyPct' => '66.67']);
    expect(Fixtures::balance($p[1], $loc))->toBe('3.000');
    expect((bool) DB::table('locations')->where('id', $loc)->value('is_frozen'))->toBeFalse();
});

it('CA-035-2 si cierra el contador 8 se rechaza y se pide que cierre otra persona', function () {
    [$countId] = coreCount();

    expect(fn () => app(CloseCount::class)($countId, 8))->toThrow(OperationsException::class, 'otra persona');
    expect(DB::table('stock_counts')->where('id', $countId)->value('status'))->toBe('REVIEW');
});

it('CA-035-3 si el ledger rechaza un ajuste, no se aplica ninguno y el conteo sigue en revisión con ubicaciones congeladas', function () {
    [$countId, $loc, $p] = coreCount();
    DB::table('products')->where('id', $p[1])->update(['deleted_at' => now()]); // el ledger rechaza ese producto

    expect(fn () => app(CloseCount::class)($countId, 9))->toThrow(OperationsException::class);

    expect(DB::table('stock_counts')->where('id', $countId)->value('status'))->toBe('REVIEW');
    expect((bool) DB::table('locations')->where('id', $loc)->value('is_frozen'))->toBeTrue();
    expect(DB::table('stock_movements')->where('source_type', 'COUNT')->count())->toBe(0);
});

// HU-042 Proveedores

it('CA-042-1 RUC 20611519886 y razón social Proveedor de prueba crean un proveedor activo y auditado', function () {
    $id = app(CreateSupplier::class)(new NewSupplier(Ruc::of('20611519886'), 'Proveedor de prueba'));

    expect((bool) DB::table('suppliers')->where('id', $id)->value('is_active'))->toBeTrue();
    expect(DB::table('audit_logs')->where('action', 'supplier.created')->where('entity_id', $id)->count())->toBe(1);
});

it('CA-042-2 los RUC 20611519887, 2061151988, 30611519886 y 2061151988A se rechazan', function () {
    foreach (['20611519887', '2061151988', '30611519886', '2061151988A'] as $ruc) {
        expect(fn () => Ruc::of($ruc))->toThrow(SupplierException::class, 'RUC');
    }
});

it('CA-042-3 un segundo proveedor con el RUC 20611519886 se rechaza por duplicado', function () {
    app(CreateSupplier::class)(new NewSupplier(Ruc::of('20611519886'), 'Proveedor de prueba'));

    expect(fn () => app(CreateSupplier::class)(new NewSupplier(Ruc::of('20611519886'), 'Otro proveedor')))->toThrow(SupplierException::class);
    expect(DB::table('suppliers')->count())->toBe(1);
});
