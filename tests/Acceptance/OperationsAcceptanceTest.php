<?php

declare(strict_types=1);

use App\Modules\Inventory\Application\RegisterMovement;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Inventory\Domain\UnitCost;
use App\Modules\Operations\Application\CancelReceipt;
use App\Modules\Operations\Application\CancelTransfer;
use App\Modules\Operations\Application\CompleteTransfer;
use App\Modules\Operations\Application\ConfirmReceipt;
use App\Modules\Operations\Application\CreateReceiptDraft;
use App\Modules\Operations\Application\CreateTransferDraft;
use App\Modules\Operations\Application\RecordDirectAdjustment;
use App\Modules\Operations\Application\ReplaceReceiptItems;
use App\Modules\Operations\Application\RequestAdjustment;
use App\Modules\Operations\Domain\AdjustmentKind;
use App\Modules\Operations\Domain\DocumentType;
use App\Modules\Operations\Domain\NewAdjustment;
use App\Modules\Operations\Domain\NewReceipt;
use App\Modules\Operations\Domain\NewTransfer;
use App\Modules\Operations\Domain\OperationsException;
use App\Modules\Operations\Domain\ReceiptLine;
use App\Modules\Operations\Domain\TransferLine;
use App\Modules\Suppliers\Application\ChangeSupplierState;
use App\Modules\Suppliers\Application\CreateSupplier;
use App\Modules\Suppliers\Domain\NewSupplier;
use App\Modules\Suppliers\Domain\Ruc;
use Illuminate\Support\Facades\DB;
use Tests\Support\Fixtures;
use Tests\Support\MovementFactory as M;

function accWarehouse(string $code): int
{
    return (int) DB::table('warehouses')->where('code', $code)->value('id');
}

function accSupplier(string $ruc = '20611519886'): int
{
    return app(CreateSupplier::class)(new NewSupplier(Ruc::of($ruc), 'Importadora Lima SAC'));
}

function accReceipt(int $supplier, int $actor, array $lines, string $number = 'F001-234', ?string $date = null, string $warehouse = 'BOD'): NewReceipt
{
    return new NewReceipt($supplier, accWarehouse($warehouse), DocumentType::Invoice, $number, $date ?? now('America/Lima')->format('Y-m-d'), $actor, $lines);
}

function accLine(int $product, int $loc, string $qty, string $cost = '2'): ReceiptLine
{
    return new ReceiptLine($product, $loc, Quantity::of($qty), UnitCost::of($cost));
}

function accReason(string $code): int
{
    return (int) DB::table('movement_reasons')->where('code', $code)->value('id');
}

// HU-024 Recepción en borrador

it('CA-024-1 proveedor activo, BOD, FACTURA F001-234 de hoy y 10 UND: REC correlativo en DRAFT sin stock', function () {
    $actor = Fixtures::user();
    $product = Fixtures::product();
    $loc = Fixtures::location('BOD');

    $draft = app(CreateReceiptDraft::class)(accReceipt(accSupplier(), $actor, [accLine($product, $loc, '10')]));

    expect($draft['code'])->toBe('REC-'.now('America/Lima')->format('Y').'-000001');
    $row = DB::table('purchase_receipts')->where('id', $draft['id'])->first();
    expect($row->status)->toBe('DRAFT');
    expect($row->doc_number)->toBe('F001-234');
    expect(DB::table('purchase_receipt_items')->where('receipt_id', $draft['id'])->count())->toBe(1);
    expect(Fixtures::balance($product, $loc))->toBe('0');
});

it('CA-024-2 proveedor inactivo, ubicación ajena, almacén inexistente o fecha futura: rechaza sin borrador parcial', function () {
    $actor = Fixtures::user();
    $product = Fixtures::product();
    $bod = Fixtures::location('BOD');
    $pis = Fixtures::location('PIS');
    $active = accSupplier();
    $inactive = accSupplier('20100070970');
    app(ChangeSupplierState::class)->deactivate($inactive, $actor);
    $create = app(CreateReceiptDraft::class);

    expect(fn () => $create(accReceipt($inactive, $actor, [accLine($product, $bod, '10')])))->toThrow(OperationsException::class);
    expect(fn () => $create(accReceipt($active, $actor, [accLine($product, $pis, '10')])))->toThrow(OperationsException::class);
    expect(fn () => $create(new NewReceipt($active, 9999, DocumentType::Invoice, 'F001-234', now('America/Lima')->format('Y-m-d'), $actor, [accLine($product, $bod, '10')])))
        ->toThrow(OperationsException::class);
    expect(fn () => $create(accReceipt($active, $actor, [accLine($product, $bod, '10')], date: now('America/Lima')->addDays(5)->format('Y-m-d'))))
        ->toThrow(OperationsException::class, 'futura');

    expect(DB::table('purchase_receipts')->count())->toBe(0);
    expect(DB::table('purchase_receipt_items')->count())->toBe(0);
});

it('CA-024-3 F001-234 repetida del mismo proveedor se rechaza; tras anular la anterior se registra de nuevo', function () {
    $actor = Fixtures::user();
    $product = Fixtures::product();
    $loc = Fixtures::location('BOD');
    $supplier = accSupplier();
    $create = app(CreateReceiptDraft::class);
    $first = $create(accReceipt($supplier, $actor, [accLine($product, $loc, '10')]));

    expect(fn () => $create(accReceipt($supplier, $actor, [accLine($product, $loc, '10')])))->toThrow(OperationsException::class, 'Ya se registró');

    app(CancelReceipt::class)($first['id'], $actor);
    $again = $create(accReceipt($supplier, $actor, [accLine($product, $loc, '10')]));
    expect(DB::table('purchase_receipts')->where('id', $again['id'])->value('status'))->toBe('DRAFT');
});

// HU-026 Recepción por presentación

it('CA-026-2 factor 0 o -12 con 2 cajas se rechaza y no se confirma la recepción', function () {
    foreach (['0', '-12'] as $factor) {
        expect(fn () => ReceiptLine::fromPresentation(1, 1, 1, Quantity::of('2'), $factor, '24'))
            ->toThrow(OperationsException::class, 'factor');
    }
    expect(DB::table('stock_movements')->count())->toBe(0);
});

it('CA-026-3 1 caja de factor 3 a S/1 deja costo por unidad base 0.3333 (half-up a 4 decimales)', function () {
    $line = ReceiptLine::fromPresentation(1, 1, 1, Quantity::of('1'), '3', '1');

    expect($line->quantity->value)->toBe('3.000');
    expect($line->unitCost->value)->toBe('0.3333');
    expect(ReceiptLine::fromPresentation(1, 1, 1, Quantity::of('1'), '3', '2')->unitCost->value)->toBe('0.6667'); // control del half-up
});

// HU-027 Anular o corregir recepción

it('CA-027-1 anular un borrador de 2 líneas lo deja CANCELLED, auditado y sin movimientos', function () {
    $actor = Fixtures::user();
    $loc = Fixtures::location('BOD');
    $draft = app(CreateReceiptDraft::class)(accReceipt(accSupplier(), $actor, [
        accLine(Fixtures::product(), $loc, '10'), accLine(Fixtures::product(), $loc, '5'),
    ]));

    app(CancelReceipt::class)($draft['id'], $actor);

    expect(DB::table('purchase_receipts')->where('id', $draft['id'])->value('status'))->toBe('CANCELLED');
    expect(DB::table('audit_logs')->where('entity_type', 'receipt')->where('entity_id', $draft['id'])->where('action', 'like', '%cancel%')->count())->toBe(1);
    expect(DB::table('stock_movements')->count())->toBe(0);
});

it('CA-027-2 una recepción CONFIRMED no se anula: la corrección va por ajuste autorizado', function () {
    $actor = Fixtures::user();
    $product = Fixtures::product();
    $loc = Fixtures::location('BOD');
    $draft = app(CreateReceiptDraft::class)(accReceipt(accSupplier(), $actor, [accLine($product, $loc, '10')]));
    app(ConfirmReceipt::class)($draft['id'], $actor);

    expect(fn () => app(CancelReceipt::class)($draft['id'], $actor))->toThrow(OperationsException::class, 'ajuste');

    expect(DB::table('purchase_receipts')->where('id', $draft['id'])->value('status'))->toBe('CONFIRMED');
    expect(Fixtures::balance($product, $loc))->toBe('10.000');
});

it('CA-027-3 el borrador acepta reemplazo por 20 UND y rechaza la lista vacía manteniéndose consistente', function () {
    $actor = Fixtures::user();
    $product = Fixtures::product();
    $loc = Fixtures::location('BOD');
    $draft = app(CreateReceiptDraft::class)(accReceipt(accSupplier(), $actor, [accLine($product, $loc, '10')]));

    app(ReplaceReceiptItems::class)($draft['id'], [accLine($product, $loc, '20')]);
    expect(fn () => app(ReplaceReceiptItems::class)($draft['id'], []))->toThrow(OperationsException::class, 'entre 1');

    $items = DB::table('purchase_receipt_items')->where('receipt_id', $draft['id'])->get();
    expect($items)->toHaveCount(1);
    expect((string) $items[0]->qty_received)->toBe('20.000');
    expect(DB::table('purchase_receipts')->where('id', $draft['id'])->value('status'))->toBe('DRAFT');
});

// HU-028 Transferencias

it('CA-028-1 transferir 5 UND de BOD a PIS con stock 20 la completa y deja saldos 15 y 5', function () {
    $actor = Fixtures::user();
    $product = Fixtures::product();
    $bod = Fixtures::location('BOD');
    $pis = Fixtures::location('PIS');
    app(RegisterMovement::class)(M::purchase($product, $bod, 20, '1', $actor));
    $draft = app(CreateTransferDraft::class)(new NewTransfer($actor, [new TransferLine($product, $bod, $pis, Quantity::of('5'))]));

    app(CompleteTransfer::class)($draft['id'], $actor);

    expect(DB::table('transfer_orders')->where('id', $draft['id'])->value('status'))->toBe('COMPLETED');
    $movementId = DB::table('transfer_order_items')->where('order_id', $draft['id'])->value('movement_id');
    expect(DB::table('stock_movements')->where('id', $movementId)->value('type'))->toBe('TRANSFERENCIA');
    expect([Fixtures::balance($product, $bod), Fixtures::balance($product, $pis)])->toBe(['15.000', '5.000']);
});

it('CA-028-2 si la segunda línea pide 9 con saldo 3, no se mueve ninguna y sigue en DRAFT', function () {
    $actor = Fixtures::user();
    $a = Fixtures::product();
    $b = Fixtures::product();
    $bod = Fixtures::location('BOD');
    $pis = Fixtures::location('PIS');
    app(RegisterMovement::class)(M::purchase($a, $bod, 20, '1', $actor));
    app(RegisterMovement::class)(M::purchase($b, $bod, 3, '1', $actor));
    $draft = app(CreateTransferDraft::class)(new NewTransfer($actor, [
        new TransferLine($a, $bod, $pis, Quantity::of('5')),
        new TransferLine($b, $bod, $pis, Quantity::of('9')),
    ]));

    expect(fn () => app(CompleteTransfer::class)($draft['id'], $actor))->toThrow(OperationsException::class, 'Stock insuficiente');

    expect(DB::table('stock_movements')->where('type', 'TRANSFERENCIA')->count())->toBe(0);
    expect([Fixtures::balance($a, $bod), Fixtures::balance($b, $bod)])->toBe(['20.000', '3.000']);
    expect(DB::table('transfer_orders')->where('id', $draft['id'])->value('status'))->toBe('DRAFT');
});

it('CA-028-3 completar de nuevo no duplica y anular una completada se rechaza', function () {
    $actor = Fixtures::user();
    $product = Fixtures::product();
    $bod = Fixtures::location('BOD');
    $pis = Fixtures::location('PIS');
    app(RegisterMovement::class)(M::purchase($product, $bod, 20, '1', $actor));
    $draft = app(CreateTransferDraft::class)(new NewTransfer($actor, [new TransferLine($product, $bod, $pis, Quantity::of('5'))]));
    app(CompleteTransfer::class)($draft['id'], $actor);

    app(CompleteTransfer::class)($draft['id'], $actor);
    expect(fn () => app(CancelTransfer::class)($draft['id'], $actor))->toThrow(OperationsException::class);

    expect(DB::table('stock_movements')->where('type', 'TRANSFERENCIA')->count())->toBe(1);
    expect(Fixtures::balance($product, $pis))->toBe('5.000');
    expect(DB::table('transfer_orders')->where('id', $draft['id'])->value('status'))->toBe('COMPLETED');
});

// HU-029 Solicitar ajuste o merma

it('CA-029-1 MERMA de 2 UND con motivo activo y "Se mojaron en bodega" crea solicitud PENDING sin descontar', function () {
    $actor = Fixtures::user();
    $product = Fixtures::product();
    $loc = Fixtures::location('BOD');
    app(RegisterMovement::class)(M::purchase($product, $loc, 10, '3', $actor));

    $request = app(RequestAdjustment::class)(new NewAdjustment(AdjustmentKind::WriteOff, $product, $loc, Quantity::of('2'), accReason('MERMA_DANIO'), 'Se mojaron en bodega', $actor));

    expect(DB::table('stock_adjustment_requests')->where('id', $request['id'])->value('status'))->toBe('PENDING');
    expect(DB::table('audit_logs')->where('action', 'adjustment.requested')->where('entity_id', $request['id'])->count())->toBe(1);
    expect(Fixtures::balance($product, $loc))->toBe('10.000');
});

it('CA-029-2 explicación vacía, motivo inactivo o tipo incompatible se rechazan sin crear solicitud', function () {
    $actor = Fixtures::user();
    $product = Fixtures::product();
    $loc = Fixtures::location('BOD');
    $inactive = accReason('MERMA_ROBO');
    DB::table('movement_reasons')->where('id', $inactive)->update(['is_active' => false]);
    $make = static fn (AdjustmentKind $kind, int $reason, string $notes) => app(RequestAdjustment::class)(new NewAdjustment($kind, $product, $loc, Quantity::of('2'), $reason, $notes, $actor));

    expect(fn () => $make(AdjustmentKind::WriteOff, accReason('MERMA_DANIO'), ''))->toThrow(OperationsException::class, 'Explique');
    expect(fn () => $make(AdjustmentKind::WriteOff, $inactive, 'Se mojaron en bodega'))->toThrow(OperationsException::class, 'desactivado');
    expect(fn () => $make(AdjustmentKind::WriteOff, accReason('AJU_HALLAZGO'), 'Se mojaron en bodega'))->toThrow(OperationsException::class, 'no corresponde');

    expect(DB::table('stock_adjustment_requests')->count())->toBe(0);
});

it('CA-029-3 producto 999 o ubicación 999 se rechazan como referencia no encontrada sin movimientos', function () {
    $actor = Fixtures::user();
    $product = Fixtures::product();
    $loc = Fixtures::location('BOD');
    $make = static fn (int $p, int $l) => app(RequestAdjustment::class)(new NewAdjustment(AdjustmentKind::WriteOff, $p, $l, Quantity::of('2'), accReason('MERMA_DANIO'), 'Se mojaron en bodega', $actor));

    expect(fn () => $make(999, $loc))->toThrow(OperationsException::class, 'producto');
    expect(fn () => $make($product, 999))->toThrow(OperationsException::class, 'ubicación');

    expect(DB::table('stock_adjustment_requests')->count())->toBe(0);
    expect(DB::table('stock_movements')->count())->toBe(0);
});

// HU-031 Ajuste directo con límite

function accDirectFixture(string $limit, string $qty, string $cost): array
{
    $actor = Fixtures::user();
    $product = Fixtures::product();
    $loc = Fixtures::location('BOD');
    app(RegisterMovement::class)(M::purchase($product, $loc, 100, $cost, $actor));
    DB::table('settings')->where('key', 'adjustment.auto_approve_limit_pen')->update(['value' => $limit]);

    return [new NewAdjustment(AdjustmentKind::Negative, $product, $loc, Quantity::of($qty), accReason('AJU_ERROR_CONTEO'), 'Diferencia en el conteo', $actor), $product, $loc, $actor];
}

it('CA-031-1 con límite S/10 un ajuste valorizado en S/8 se registra directo y trazable', function () {
    [$adjustment, $product, $loc, $actor] = accDirectFixture('10', '4', '2'); // 4 x 2 = S/8

    $movementId = app(RecordDirectAdjustment::class)($adjustment, 'CA-031-1-DIRECT');

    $movement = DB::table('stock_movements')->where('id', $movementId)->first();
    expect($movement->type)->toBe('AJUSTE_NEGATIVO');
    expect((int) $movement->user_id)->toBe($actor);
    expect((int) $movement->reason_id)->toBe(accReason('AJU_ERROR_CONTEO'));
    expect(Fixtures::balance($product, $loc))->toBe('96.000');
    expect(DB::table('audit_logs')->where('action', 'adjustment.direct')->count())->toBe(1);
});

it('CA-031-2 con límite 0 o un ajuste de S/10.01 con límite S/10 se rechaza sin tocar stock', function () {
    [$disabled, $product, $loc] = accDirectFixture('0', '1', '2');
    expect(fn () => app(RecordDirectAdjustment::class)($disabled, 'CA-031-2-ZERO'))->toThrow(OperationsException::class, 'límite');

    DB::table('settings')->where('key', 'adjustment.auto_approve_limit_pen')->update(['value' => '10']);
    DB::table('products')->where('id', $product)->update(['cost_avg' => '10.01']); // 1 x 10.01 = S/10.01
    expect(fn () => app(RecordDirectAdjustment::class)($disabled, 'CA-031-2-OVER'))->toThrow(OperationsException::class, 'límite');

    expect(Fixtures::balance($product, $loc))->toBe('100.000');
    expect(DB::table('stock_movements')->where('type', 'AJUSTE_NEGATIVO')->count())->toBe(0);
});

it('CA-031-3 con límite S/10 y valorización exacta S/10 se permite (el límite es inclusivo) y conserva autor y motivo', function () {
    [$adjustment, $product, $loc, $actor] = accDirectFixture('10', '5', '2'); // 5 x 2 = S/10

    $movementId = app(RecordDirectAdjustment::class)($adjustment, 'CA-031-3-EXACT');

    $movement = DB::table('stock_movements')->where('id', $movementId)->first();
    expect((int) $movement->user_id)->toBe($actor);
    expect((int) $movement->approved_by)->toBe($actor);
    expect((int) $movement->reason_id)->toBe(accReason('AJU_ERROR_CONTEO'));
    expect(Fixtures::balance($product, $loc))->toBe('95.000');
});
