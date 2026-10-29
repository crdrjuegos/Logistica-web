<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Inventory\Domain\LocationState;
use App\Modules\Inventory\Domain\MovementType;
use App\Modules\Inventory\Domain\NewMovement;
use App\Modules\Inventory\Domain\ProductSnapshot;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Inventory\Domain\RecordedMovement;
use App\Modules\Inventory\Domain\StockLedger;
use App\Modules\Inventory\Domain\UnitCost;

/** Doble en memoria del puerto StockLedger: permite probar el caso de uso sin base de datos. */
final class InMemoryStockLedger implements StockLedger
{
    /** @var array<int, string> id producto => costo promedio */
    public array $productCosts = [];

    /** @var array<int, LocationState> */
    public array $locations = [];

    /** @var array<string, string> "producto:ubicación" => cantidad */
    public array $balances = [];

    /** @var list<array{id: int, uuid: string, key: string, type: string, productId: int, from: ?int, to: ?int, cost: string, qty: string}> */
    public array $movements = [];

    public bool $failOnIncrease = false;

    public function lockProduct(int $productId): ?ProductSnapshot
    {
        return isset($this->productCosts[$productId])
            ? new ProductSnapshot($productId, UnitCost::of($this->productCosts[$productId]))
            : null;
    }

    public function locationStates(array $locationIds): array
    {
        $out = [];
        foreach ($locationIds as $id) {
            if (isset($this->locations[$id])) {
                $out[$id] = $this->locations[$id];
            }
        }

        return $out;
    }

    public function findByIdempotencyKey(string $key): ?RecordedMovement
    {
        foreach ($this->movements as $m) {
            if ($m['key'] === $key) {
                return new RecordedMovement(
                    $m['id'], $m['uuid'], MovementType::from($m['type']), $m['productId'],
                    $m['from'], $m['to'], Quantity::of($m['qty']), UnitCost::of($m['cost']),
                );
            }
        }

        return null;
    }

    public function hasOpeningBalance(int $productId, int $locationId): bool
    {
        foreach ($this->movements as $m) {
            if ($m['type'] === MovementType::OpeningBalance->value && $m['productId'] === $productId && $m['to'] === $locationId) {
                return true;
            }
        }

        return false;
    }

    public function lockBalance(int $productId, int $locationId): Quantity
    {
        return Quantity::of($this->balances["{$productId}:{$locationId}"] ?? '0');
    }

    public function totalQuantity(int $productId): Quantity
    {
        $total = Quantity::zero();
        foreach ($this->balances as $k => $q) {
            if (str_starts_with($k, "{$productId}:")) {
                $total = $total->add(Quantity::of($q));
            }
        }

        return $total;
    }

    public function append(NewMovement $movement, UnitCost $recordedCost): int
    {
        $id = count($this->movements) + 1;
        $this->movements[] = [
            'id' => $id, 'uuid' => $movement->uuid, 'key' => $movement->idempotencyKey,
            'type' => $movement->type->value, 'productId' => $movement->productId,
            'from' => $movement->fromLocationId, 'to' => $movement->toLocationId,
            'cost' => $recordedCost->value, 'qty' => $movement->quantity->value,
        ];

        return $id;
    }

    public function increase(int $productId, int $locationId, Quantity $quantity): void
    {
        if ($this->failOnIncrease) {
            throw new \RuntimeException('fallo simulado al incrementar');
        }
        $this->balances["{$productId}:{$locationId}"] = $this->lockBalance($productId, $locationId)->add($quantity)->value;
    }

    public function decrease(int $productId, int $locationId, Quantity $quantity): bool
    {
        $current = $this->lockBalance($productId, $locationId);
        if ($current->lessThan($quantity)) {
            return false;
        }
        $this->balances["{$productId}:{$locationId}"] = $current->subtract($quantity)->value;

        return true;
    }

    public function updateProductCost(int $productId, UnitCost $average, ?UnitCost $lastCost): void
    {
        $this->productCosts[$productId] = $average->value;
    }

    /** @return array{0: array<int, string>, 1: array<string, string>, 2: list<array<string, mixed>>} */
    public function snapshot(): array
    {
        return [$this->productCosts, $this->balances, $this->movements];
    }

    /** @param array{0: array<int, string>, 1: array<string, string>, 2: list<array<string, mixed>>} $state */
    public function restore(array $state): void
    {
        [$this->productCosts, $this->balances, $this->movements] = $state;
    }
}
