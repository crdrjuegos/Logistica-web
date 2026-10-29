<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Modules\Inventory\Domain\EventBus;
use App\Modules\Inventory\Domain\Events\StockChanged;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Modules\Inventory\Domain\LocationState;
use App\Modules\Inventory\Domain\MovementType;
use App\Modules\Inventory\Domain\NewMovement;
use App\Modules\Inventory\Domain\ProductSnapshot;
use App\Modules\Inventory\Domain\StockLedger;
use App\Modules\Inventory\Domain\Transaction;
use App\Modules\Inventory\Domain\UnitCost;
use App\Modules\Inventory\Domain\WeightedAverageCost;

/**
 * Único punto de entrada para modificar stock. Atómico, idempotente y sin stock negativo.
 */
final class RegisterMovement
{
    public function __construct(
        private readonly StockLedger $ledger,
        private readonly Transaction $transaction,
        private readonly EventBus $events,
    ) {}

    public function __invoke(NewMovement $movement): MovementRecorded
    {
        return $this->transaction->run(fn (): MovementRecorded => $this->execute($movement));
    }

    private function execute(NewMovement $m): MovementRecorded
    {
        // 1) El bloqueo del producto va PRIMERO: serializa reintentos concurrentes y el costo promedio.
        $product = $this->ledger->lockProduct($m->productId)
            ?? throw InventoryException::productNotFound($m->productId);

        // 2) Idempotencia.
        $existing = $this->ledger->findByIdempotencyKey($m->idempotencyKey);
        if ($existing !== null) {
            if ($existing->uuid !== $m->uuid) {
                throw InventoryException::idempotencyKeyReused($m->idempotencyKey);
            }

            if (! $existing->sameContentAs($m)) {
                throw InventoryException::idempotencyConflict($m->idempotencyKey);
            }

            return new MovementRecorded($existing->id, $existing->uuid, true);
        }

        // 3) Ubicaciones válidas.
        $locations = $this->resolveLocations($m);

        // 3b) Una sola apertura por producto y ubicación (el bloqueo del producto la serializa).
        if ($m->type === MovementType::OpeningBalance && $m->toLocationId !== null
            && $this->ledger->hasOpeningBalance($m->productId, $m->toLocationId)) {
            throw InventoryException::openingBalanceExists($locations[$m->toLocationId]->code);
        }

        // 4) Stock suficiente en el origen (salidas y transferencias).
        if ($m->fromLocationId !== null) {
            $available = $this->ledger->lockBalance($m->productId, $m->fromLocationId);
            if ($available->lessThan($m->quantity)) {
                throw InventoryException::insufficientStock($locations[$m->fromLocationId]->code, $available, $m->quantity);
            }
        }

        // 5) Valorización.
        $recordedCost = $this->valuate($m, $product);

        // 6) Ledger + saldos (misma transacción).
        $movementId = $this->ledger->append($m, $recordedCost);
        $this->applyToBalances($m, $locations);

        $this->events->publish(new StockChanged($movementId, $m->productId, $m->type, $m->locationIds()));

        return new MovementRecorded($movementId, $m->uuid, false);
    }

    /** @return array<int, LocationState> */
    private function resolveLocations(NewMovement $m): array
    {
        $states = $this->ledger->locationStates($m->locationIds());

        foreach ($m->locationIds() as $id) {
            $state = $states[$id] ?? throw InventoryException::locationNotFound($id);

            if (! $state->isActive) {
                throw InventoryException::locationInactive($state->code);
            }

            if ($state->isFrozen && ! $m->allowsFrozenLocations()) {
                throw InventoryException::locationFrozen($state->code);
            }
        }

        return $states;
    }

    private function valuate(NewMovement $m, ProductSnapshot $product): UnitCost
    {
        if (! $m->type->requiresCost()) {
            return $product->costAvg; // salidas, mermas, ajustes y devoluciones valen al promedio vigente
        }

        $cost = $m->unitCost ?? throw InventoryException::invalid('Falta el costo unitario.');
        $newAverage = WeightedAverageCost::next(
            $this->ledger->totalQuantity($m->productId),
            $product->costAvg,
            $m->quantity,
            $cost,
        );
        $this->ledger->updateProductCost(
            $m->productId,
            $newAverage,
            $m->type === MovementType::Purchase ? $cost : null,
        );

        return $cost;
    }

    /** @param array<int, LocationState> $locations */
    private function applyToBalances(NewMovement $m, array $locations): void
    {
        if ($m->fromLocationId !== null && ! $this->ledger->decrease($m->productId, $m->fromLocationId, $m->quantity)) {
            // Defensa en profundidad: el chequeo previo ya debería haberlo impedido.
            $available = $this->ledger->lockBalance($m->productId, $m->fromLocationId);
            throw InventoryException::insufficientStock($locations[$m->fromLocationId]->code, $available, $m->quantity);
        }

        if ($m->toLocationId !== null) {
            $this->ledger->increase($m->productId, $m->toLocationId, $m->quantity);
        }
    }
}
