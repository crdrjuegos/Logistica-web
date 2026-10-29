<?php

declare(strict_types=1);

use App\Modules\Inventory\Domain\Events\StockChanged;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Modules\Inventory\Domain\MovementType;
use Tests\Support\Ids;
use Tests\Support\MovementFactory as M;
use Tests\Support\Scenario;

it('una compra aumenta el saldo y recalcula el costo promedio', function () {
    $s = new Scenario;
    ($s->register)(M::purchase(Scenario::PRODUCT, Scenario::BODEGA, 100, '2.5'));
    ($s->register)(M::purchase(Scenario::PRODUCT, Scenario::BODEGA, 50, '3'));

    expect($s->balance(Scenario::BODEGA))->toBe('150.000');
    expect($s->ledger->productCosts[Scenario::PRODUCT])->toBe('2.6667');
    expect($s->ledger->movements)->toHaveCount(2);
});

it('una salida descuenta y se valoriza al costo promedio vigente', function () {
    $s = new Scenario;
    ($s->register)(M::purchase(Scenario::PRODUCT, Scenario::BODEGA, 10, '2.5'));
    ($s->register)(M::sale(Scenario::PRODUCT, Scenario::BODEGA, 4));

    expect($s->balance(Scenario::BODEGA))->toBe('6.000');
    expect($s->ledger->movements[1]['cost'])->toBe('2.5000');
    expect($s->ledger->productCosts[Scenario::PRODUCT])->toBe('2.5000'); // las salidas no cambian el promedio
});

it('rechaza stock insuficiente sin dejar rastro', function () {
    $s = new Scenario;
    ($s->register)(M::purchase(Scenario::PRODUCT, Scenario::BODEGA, 3, '1'));

    expect(fn () => ($s->register)(M::sale(Scenario::PRODUCT, Scenario::BODEGA, 5)))
        ->toThrow(InventoryException::class, 'Stock insuficiente en BOD-A: disponible 3.000, solicitado 5.000');

    expect($s->balance(Scenario::BODEGA))->toBe('3.000');
    expect($s->ledger->movements)->toHaveCount(1);
    expect($s->events->events)->toHaveCount(1); // solo el de la compra
});

it('permite vaciar exactamente el saldo', function () {
    $s = new Scenario;
    ($s->register)(M::purchase(Scenario::PRODUCT, Scenario::BODEGA, 3, '1'));
    ($s->register)(M::sale(Scenario::PRODUCT, Scenario::BODEGA, 3));

    expect($s->balance(Scenario::BODEGA))->toBe('0.000');
});

it('una transferencia mueve el stock entre ubicaciones', function () {
    $s = new Scenario;
    ($s->register)(M::purchase(Scenario::PRODUCT, Scenario::BODEGA, 20, '1'));
    ($s->register)(M::transfer(Scenario::PRODUCT, Scenario::BODEGA, Scenario::PISO, 5));

    expect($s->balance(Scenario::BODEGA))->toBe('15.000');
    expect($s->balance(Scenario::PISO))->toBe('5.000');
    expect($s->ledger->totalQuantity(Scenario::PRODUCT)->value)->toBe('20.000'); // el total no cambia
});

it('una transferencia que falla a medias se revierte por completo', function () {
    $s = new Scenario;
    ($s->register)(M::purchase(Scenario::PRODUCT, Scenario::BODEGA, 20, '1'));
    $s->ledger->failOnIncrease = true;

    expect(fn () => ($s->register)(M::transfer(Scenario::PRODUCT, Scenario::BODEGA, Scenario::PISO, 5)))
        ->toThrow(RuntimeException::class);

    expect($s->balance(Scenario::BODEGA))->toBe('20.000');
    expect($s->balance(Scenario::PISO))->toBe('0.000');
    expect($s->ledger->movements)->toHaveCount(1);
});

it('es idempotente: el mismo movimiento no se registra dos veces', function () {
    $s = new Scenario;
    $m = M::purchase(Scenario::PRODUCT, Scenario::BODEGA, 10, '2');

    $first = ($s->register)($m);
    $again = ($s->register)($m);

    expect($first->duplicate)->toBeFalse();
    expect($again->duplicate)->toBeTrue();
    expect($again->movementId)->toBe($first->movementId);
    expect($s->balance(Scenario::BODEGA))->toBe('10.000');
    expect($s->ledger->movements)->toHaveCount(1);
    expect($s->events->events)->toHaveCount(1);
});

it('rechaza reutilizar una clave de idempotencia con otro movimiento', function () {
    $s = new Scenario;
    $key = Ids::key();
    ($s->register)(M::purchase(Scenario::PRODUCT, Scenario::BODEGA, 10, '2', key: $key));

    expect(fn () => ($s->register)(M::purchase(Scenario::PRODUCT, Scenario::BODEGA, 99, '2', key: $key)))
        ->toThrow(InventoryException::class, 'ya se usó');
});

it('CA-019-4 rechaza el reintento con el mismo UUID y clave si cambia la cantidad (10 vs 99)', function () {
    $s = new Scenario;
    $uuid = Ids::uuid();
    $key = Ids::key();
    $original = ($s->register)(M::purchase(Scenario::PRODUCT, Scenario::BODEGA, 10, '2', uuid: $uuid, key: $key));

    try {
        ($s->register)(M::purchase(Scenario::PRODUCT, Scenario::BODEGA, 99, '2', uuid: $uuid, key: $key));
        $this->fail('Debió rechazar el conflicto de contenido.');
    } catch (InventoryException $e) {
        expect($e->errorCode)->toBe('IDEMPOTENCY_CONFLICT');
        expect($e->getMessage())->toContain('contenido distinto');
    }

    // Se conserva el resultado original.
    expect($original->duplicate)->toBeFalse();
    expect($s->balance(Scenario::BODEGA))->toBe('10.000');
    expect($s->ledger->movements)->toHaveCount(1);
    expect($s->ledger->movements[0]['qty'])->toBe('10.000');
});

it('rechaza el reintento con mismo UUID y clave si cambia producto, ubicación, tipo o costo', function () {
    $s = new Scenario;
    $uuid = Ids::uuid();
    $key = Ids::key();
    ($s->register)(M::purchase(Scenario::PRODUCT, Scenario::BODEGA, 10, '2', uuid: $uuid, key: $key));

    $conflicts = [
        'costo' => M::purchase(Scenario::PRODUCT, Scenario::BODEGA, 10, '3', uuid: $uuid, key: $key),
        'ubicación' => M::purchase(Scenario::PRODUCT, Scenario::PISO, 10, '2', uuid: $uuid, key: $key),
        'tipo' => M::sale(Scenario::PRODUCT, Scenario::BODEGA, 10, uuid: $uuid, key: $key),
    ];

    foreach ($conflicts as $label => $movement) {
        expect(fn () => ($s->register)($movement))
            ->toThrow(InventoryException::class, 'contenido distinto');
    }
    expect($s->ledger->movements)->toHaveCount(1);
});

it('el reintento idéntico de una salida sigue siendo idempotente aunque el promedio haya cambiado', function () {
    $s = new Scenario;
    ($s->register)(M::purchase(Scenario::PRODUCT, Scenario::BODEGA, 10, '2'));
    $sale = M::sale(Scenario::PRODUCT, Scenario::BODEGA, 4);
    ($s->register)($sale);
    ($s->register)(M::purchase(Scenario::PRODUCT, Scenario::BODEGA, 10, '8')); // cambia el promedio

    expect(($s->register)($sale)->duplicate)->toBeTrue();
    expect($s->balance(Scenario::BODEGA))->toBe('16.000');
});

it('CA-053-2 rechaza una segunda apertura del mismo producto y ubicación aunque cambie la clave', function () {
    $s = new Scenario;
    ($s->register)(M::opening(Scenario::PRODUCT, Scenario::BODEGA, 10, '2'));

    try {
        ($s->register)(M::opening(Scenario::PRODUCT, Scenario::BODEGA, 10, '2'));
        $this->fail('Debió rechazar la segunda apertura.');
    } catch (InventoryException $e) {
        expect($e->errorCode)->toBe('OPENING_BALANCE_EXISTS');
    }

    expect($s->balance(Scenario::BODEGA))->toBe('10.000');
    expect($s->ledger->movements)->toHaveCount(1);
});

it('permite aperturas del mismo producto en ubicaciones distintas y reintentos idénticos', function () {
    $s = new Scenario;
    $opening = M::opening(Scenario::PRODUCT, Scenario::BODEGA, 10, '2');
    ($s->register)($opening);
    ($s->register)(M::opening(Scenario::PRODUCT, Scenario::PISO, 5, '2'));

    expect(($s->register)($opening)->duplicate)->toBeTrue();
    expect($s->balance(Scenario::BODEGA))->toBe('10.000');
    expect($s->balance(Scenario::PISO))->toBe('5.000');
});

it('rechaza productos inexistentes', function () {
    $s = new Scenario;

    expect(fn () => ($s->register)(M::purchase(999, Scenario::BODEGA, 1, '1')))
        ->toThrow(InventoryException::class, 'no existe');
});

it('rechaza ubicaciones inexistentes, desactivadas o congeladas', function () {
    $s = new Scenario;

    expect(fn () => ($s->register)(M::purchase(Scenario::PRODUCT, 777, 1, '1')))->toThrow(InventoryException::class, 'no existe');
    expect(fn () => ($s->register)(M::purchase(Scenario::PRODUCT, Scenario::INACTIVE, 1, '1')))->toThrow(InventoryException::class, 'desactivada');
    expect(fn () => ($s->register)(M::purchase(Scenario::PRODUCT, Scenario::FROZEN, 1, '1')))->toThrow(InventoryException::class, 'congelada');
    expect($s->ledger->movements)->toHaveCount(0);
});

it('los ajustes de un conteo sí pueden operar sobre ubicaciones congeladas', function () {
    $s = new Scenario;
    ($s->register)(M::purchase(Scenario::PRODUCT, Scenario::FROZEN, 5, '1', sourceType: 'COUNT'));
    ($s->register)(M::adjustmentOut(Scenario::PRODUCT, Scenario::FROZEN, 2, sourceType: 'COUNT'));

    expect($s->balance(Scenario::FROZEN))->toBe('3.000');
});

it('publica StockChanged una sola vez por movimiento y solo si tuvo éxito', function () {
    $s = new Scenario;
    ($s->register)(M::purchase(Scenario::PRODUCT, Scenario::BODEGA, 10, '1'));
    ($s->register)(M::transfer(Scenario::PRODUCT, Scenario::BODEGA, Scenario::PISO, 4));

    expect($s->events->events)->toHaveCount(2);
    $event = $s->events->events[1];
    expect($event)->toBeInstanceOf(StockChanged::class);
    expect($event->type)->toBe(MovementType::Transfer);
    expect($event->locationIds)->toBe([Scenario::BODEGA, Scenario::PISO]);
});

it('una merma se valoriza al promedio vigente y descuenta', function () {
    $s = new Scenario;
    ($s->register)(M::purchase(Scenario::PRODUCT, Scenario::BODEGA, 10, '4'));
    ($s->register)(M::writeOff(Scenario::PRODUCT, Scenario::BODEGA, 2));

    expect($s->balance(Scenario::BODEGA))->toBe('8.000');
    expect($s->ledger->movements[1]['cost'])->toBe('4.0000');
});
