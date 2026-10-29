<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

/**
 * Movimiento ya guardado en el ledger, con los datos necesarios para comparar un reintento.
 */
final readonly class RecordedMovement
{
    public function __construct(
        public int $id,
        public string $uuid,
        public MovementType $type,
        public int $productId,
        public ?int $fromLocationId,
        public ?int $toLocationId,
        public Quantity $quantity,
        public UnitCost $unitCost,
    ) {}

    /**
     * Un reintento legítimo debe traer exactamente el mismo contenido que el original.
     * El costo solo se compara en las entradas con costo: en las salidas lo fija el sistema.
     */
    public function sameContentAs(NewMovement $m): bool
    {
        return $this->uuid === $m->uuid
            && $this->type === $m->type
            && $this->productId === $m->productId
            && $this->fromLocationId === $m->fromLocationId
            && $this->toLocationId === $m->toLocationId
            && $this->quantity->equals($m->quantity)
            && (! $m->type->requiresCost() || ($m->unitCost !== null && $this->unitCost->equals($m->unitCost)));
    }
}
