<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

/**
 * Puerto de persistencia del ledger. La implementación debe ejecutarse dentro de una transacción.
 */
interface StockLedger
{
    /** Bloquea la fila del producto (FOR UPDATE): serializa los movimientos del mismo producto. */
    public function lockProduct(int $productId): ?ProductSnapshot;

    /**
     * Estado de las ubicaciones (con bloqueo compartido para que un congelamiento concurrente espere).
     *
     * @param  list<int>  $locationIds
     * @return array<int, LocationState> indexado por id
     */
    public function locationStates(array $locationIds): array;

    public function findByIdempotencyKey(string $key): ?RecordedMovement;

    /** ¿Ya existe un SALDO_INICIAL para este producto en esta ubicación? */
    public function hasOpeningBalance(int $productId, int $locationId): bool;

    /** Saldo bloqueado (FOR UPDATE) de un producto en una ubicación; cero si no existe fila. */
    public function lockBalance(int $productId, int $locationId): Quantity;

    public function totalQuantity(int $productId): Quantity;

    /** Inserta en el ledger (append-only) y devuelve el id. */
    public function append(NewMovement $movement, UnitCost $recordedCost): int;

    public function increase(int $productId, int $locationId, Quantity $quantity): void;

    /** @return bool false si no había saldo suficiente (nada se modifica) */
    public function decrease(int $productId, int $locationId, Quantity $quantity): bool;

    public function updateProductCost(int $productId, UnitCost $average, ?UnitCost $lastCost): void;
}
