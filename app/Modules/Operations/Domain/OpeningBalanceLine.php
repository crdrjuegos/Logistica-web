<?php

declare(strict_types=1);

namespace App\Modules\Operations\Domain;

use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Inventory\Domain\UnitCost;

/** Una línea de la carga de apertura: stock real contado en una ubicación y su costo unitario (sin IGV). */
final readonly class OpeningBalanceLine
{
    public function __construct(
        public int $productId,
        public int $locationId,
        public Quantity $quantity,
        public UnitCost $unitCost,
    ) {
        if (! $quantity->isPositive()) {
            throw OperationsException::invalid('La cantidad de apertura debe ser mayor que cero.');
        }
    }
}
