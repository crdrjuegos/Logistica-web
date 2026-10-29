<?php

declare(strict_types=1);

namespace App\Modules\Operations\Infrastructure;

use App\Modules\Inventory\Application\RegisterMovement;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Modules\Inventory\Domain\MovementType;
use App\Modules\Inventory\Domain\NewMovement;
use App\Modules\Operations\Domain\DeterministicUuid;
use App\Modules\Operations\Domain\MovementKind;
use App\Modules\Operations\Domain\MovementRequest;
use App\Modules\Operations\Domain\OperationsException;
use App\Modules\Operations\Domain\StockMovements;

/** Adaptador: traduce pedidos de Operations a movimientos del ledger (único camino para tocar el stock). */
final class InventoryStockMovements implements StockMovements
{
    public function __construct(private readonly RegisterMovement $register) {}

    public function register(MovementRequest $request): int
    {
        try {
            $movement = new NewMovement(
                uuid: DeterministicUuid::fromKey($request->idempotencyKey),
                idempotencyKey: $request->idempotencyKey,
                type: match ($request->kind) {
                    MovementKind::Purchase => MovementType::Purchase,
                    MovementKind::Transfer => MovementType::Transfer,
                    MovementKind::AdjustmentIn => MovementType::AdjustmentIn,
                    MovementKind::AdjustmentOut => MovementType::AdjustmentOut,
                    MovementKind::WriteOff => MovementType::WriteOff,
                    MovementKind::Sale => MovementType::Sale,
                    MovementKind::OpeningBalance => MovementType::OpeningBalance,
                },
                productId: $request->productId,
                fromLocationId: $request->fromLocationId,
                toLocationId: $request->toLocationId,
                quantity: $request->quantity,
                userId: $request->userId,
                unitCost: $request->unitCost,
                reasonId: $request->reasonId,
                presentationId: $request->presentationId,
                presentationQty: $request->presentationQty,
                sourceType: $request->sourceType,
                sourceId: $request->sourceId,
                documentRef: $request->documentRef,
                notes: $request->notes,
                approvedBy: $request->approvedBy,
            );

            return ($this->register)($movement)->movementId;
        } catch (InventoryException $e) {
            throw OperationsException::stockRejected($e->getMessage(), $e->errorCode);
        }
    }
}
