<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Infrastructure;

use App\Modules\Inventory\Domain\LocationState;
use App\Modules\Inventory\Domain\MovementType;
use App\Modules\Inventory\Domain\NewMovement;
use App\Modules\Inventory\Domain\ProductSnapshot;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Inventory\Domain\RecordedMovement;
use App\Modules\Inventory\Domain\StockLedger;
use App\Modules\Inventory\Domain\UnitCost;
use Illuminate\Support\Facades\DB;

/**
 * Implementación PostgreSQL del ledger. Debe invocarse dentro de DB::transaction().
 */
final class PostgresStockLedger implements StockLedger
{
    public function lockProduct(int $productId): ?ProductSnapshot
    {
        $row = DB::table('products')
            ->where('id', $productId)
            ->whereNull('deleted_at')
            ->lockForUpdate()
            ->first(['id', 'cost_avg']);

        return $row === null
            ? null
            : new ProductSnapshot((int) $row->id, UnitCost::of((string) $row->cost_avg));
    }

    public function locationStates(array $locationIds): array
    {
        if ($locationIds === []) {
            return [];
        }

        $rows = DB::table('locations')
            ->whereIn('id', $locationIds)
            ->orderBy('id')      // orden determinista de bloqueo
            ->sharedLock()
            ->get(['id', 'code', 'is_active', 'is_frozen']);

        $states = [];
        foreach ($rows as $row) {
            $states[(int) $row->id] = new LocationState(
                (int) $row->id,
                (string) $row->code,
                filter_var($row->is_active, FILTER_VALIDATE_BOOLEAN),
                filter_var($row->is_frozen, FILTER_VALIDATE_BOOLEAN),
            );
        }

        return $states;
    }

    public function findByIdempotencyKey(string $key): ?RecordedMovement
    {
        $row = DB::table('stock_movements')->where('idempotency_key', $key)->first([
            'id', 'uuid', 'type', 'product_id', 'from_location_id', 'to_location_id', 'quantity', 'unit_cost',
        ]);

        return $row === null ? null : new RecordedMovement(
            (int) $row->id,
            strtolower((string) $row->uuid),
            MovementType::from((string) $row->type),
            (int) $row->product_id,
            $row->from_location_id === null ? null : (int) $row->from_location_id,
            $row->to_location_id === null ? null : (int) $row->to_location_id,
            Quantity::of((string) $row->quantity),
            UnitCost::of((string) ($row->unit_cost ?? '0')),
        );
    }

    public function hasOpeningBalance(int $productId, int $locationId): bool
    {
        return DB::table('stock_movements')
            ->where('type', MovementType::OpeningBalance->value)
            ->where('product_id', $productId)
            ->where('to_location_id', $locationId)
            ->exists();
    }

    public function lockBalance(int $productId, int $locationId): Quantity
    {
        $value = DB::table('stock_balances')
            ->where('product_id', $productId)
            ->where('location_id', $locationId)
            ->lockForUpdate()
            ->value('quantity');

        return $value === null ? Quantity::zero() : Quantity::of((string) $value);
    }

    public function totalQuantity(int $productId): Quantity
    {
        $row = DB::selectOne(
            'SELECT COALESCE(SUM(quantity), 0)::text AS total FROM stock_balances WHERE product_id = ?',
            [$productId],
        );

        return Quantity::of((string) ($row->total ?? '0'));
    }

    public function append(NewMovement $movement, UnitCost $recordedCost): int
    {
        return (int) DB::table('stock_movements')->insertGetId([
            'uuid' => $movement->uuid,
            'type' => $movement->type->value,
            'product_id' => $movement->productId,
            'from_location_id' => $movement->fromLocationId,
            'to_location_id' => $movement->toLocationId,
            'quantity' => $movement->quantity->value,
            'presentation_id' => $movement->presentationId,
            'presentation_qty' => $movement->presentationQty?->value,
            'unit_cost' => $recordedCost->value,
            'reason_id' => $movement->reasonId,
            'source_type' => $movement->sourceType,
            'source_id' => $movement->sourceId,
            'document_ref' => $movement->documentRef,
            'notes' => $movement->notes,
            'user_id' => $movement->userId,
            'approved_by' => $movement->approvedBy,
            'idempotency_key' => $movement->idempotencyKey,
            'client_created_at' => $movement->clientCreatedAt?->format(\DATE_ATOM),
        ], 'id');
    }

    public function increase(int $productId, int $locationId, Quantity $quantity): void
    {
        DB::statement(
            'INSERT INTO stock_balances (product_id, location_id, quantity, updated_at)
             VALUES (?, ?, ?, now())
             ON CONFLICT (product_id, location_id)
             DO UPDATE SET quantity = stock_balances.quantity + EXCLUDED.quantity, updated_at = now()',
            [$productId, $locationId, $quantity->value],
        );
    }

    public function decrease(int $productId, int $locationId, Quantity $quantity): bool
    {
        $affected = DB::update(
            'UPDATE stock_balances SET quantity = quantity - ?, updated_at = now()
              WHERE product_id = ? AND location_id = ? AND quantity >= ?',
            [$quantity->value, $productId, $locationId, $quantity->value],
        );

        return $affected === 1;
    }

    public function updateProductCost(int $productId, UnitCost $average, ?UnitCost $lastCost): void
    {
        DB::table('products')->where('id', $productId)->update(array_filter([
            'cost_avg' => $average->value,
            'last_cost' => $lastCost?->value,
            'updated_at' => now(),
        ], static fn (mixed $v): bool => $v !== null));
    }
}
