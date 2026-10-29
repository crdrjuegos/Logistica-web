<?php

declare(strict_types=1);

use App\Modules\Iam\Domain\RoleName;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Inventory\Domain\UnitCost;
use App\Modules\Operations\Application\RecordOpeningBalances;
use App\Modules\Operations\Domain\OpeningBalanceLine;
use App\Modules\Operations\Domain\OperationsException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Fixtures;
use Tests\Support\PanelUsers;

beforeEach(fn () => PanelUsers::seedRoles());

// HU-053 Saldo inicial por ubicación

it('CA-053-1 el administrador carga producto1/ubicación10 sin apertura con 10 UND a S/2: SALDO_INICIAL auditable con referencia del lote', function () {
    $admin = PanelUsers::withRole(RoleName::Admin);
    $product = Fixtures::product();
    $loc = Fixtures::location('BOD');

    $result = app(RecordOpeningBalances::class)([new OpeningBalanceLine($product, $loc, Quantity::of('10'), UnitCost::of('2'))], $admin->id, 'APERTURA-2026-10');

    expect($result)->toBe(['lines' => 1, 'units' => '10.000']);
    $movement = DB::table('stock_movements')->where('product_id', $product)->first();
    expect([$movement->type, $movement->source_type, $movement->document_ref])->toBe(['SALDO_INICIAL', 'OPENING', 'APERTURA-2026-10']);
    expect(Fixtures::balance($product, $loc))->toBe('10.000');
    expect((string) DB::table('products')->where('id', $product)->value('cost_avg'))->toBe('2.0000');
    $audit = DB::table('audit_logs')->where('action', 'opening.recorded')->first();
    expect((int) $audit->actor_id)->toBe($admin->id);
    expect((string) $audit->new_values)->toContain('APERTURA-2026-10');
});

it('CA-053-2 una segunda apertura del mismo producto y ubicación con otra clave se rechaza y el lote no se aplica', function () {
    $admin = PanelUsers::withRole(RoleName::Admin);
    $product = Fixtures::product();
    $other = Fixtures::product();
    $loc = Fixtures::location('BOD');
    app(RecordOpeningBalances::class)([new OpeningBalanceLine($product, $loc, Quantity::of('10'), UnitCost::of('2'))], $admin->id, 'APERTURA-1');

    expect(fn () => app(RecordOpeningBalances::class)([
        new OpeningBalanceLine($other, $loc, Quantity::of('5'), UnitCost::of('1')),
        new OpeningBalanceLine($product, $loc, Quantity::of('10'), UnitCost::of('2')),
    ], $admin->id, 'APERTURA-2'))->toThrow(OperationsException::class, 'saldo inicial');

    expect(Fixtures::balance($product, $loc))->toBe('10.000');
    expect(Fixtures::balance($other, $loc))->toBe('0'); // todo o nada
    expect(DB::table('stock_movements')->where('type', 'SALDO_INICIAL')->count())->toBe(1);
});

it('CA-053-3 el supervisor no tiene inventory.opening_balance y una cantidad negativa es inválida; no se añade stock', function () {
    $supervisor = PanelUsers::withRole(RoleName::Supervisor);

    expect($supervisor->can('inventory.opening_balance'))->toBeFalse();
    expect(PanelUsers::withRole(RoleName::Admin)->can('inventory.opening_balance'))->toBeTrue();
    expect(fn () => new OpeningBalanceLine(Fixtures::product(), Fixtures::location('BOD'), Quantity::of('-10'), UnitCost::of('2')))
        ->toThrow(InventoryException::class, 'Cantidad inválida');

    expect(DB::table('stock_movements')->count())->toBe(0);
});
