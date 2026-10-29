<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

use App\Modules\Inventory\Domain\Exceptions\InventoryException;

final readonly class KardexQuery
{
    public const MAX_ROWS = 5000;

    public function __construct(
        public int $productId,
        public ?int $warehouseId = null,
        public ?int $locationId = null,
        /** Inicio inclusivo. */
        public ?\DateTimeImmutable $from = null,
        /** Fin exclusivo. */
        public ?\DateTimeImmutable $to = null,
        public int $limit = 1000,
        /** Página 1-based de tamaño $limit, en el orden del ledger. */
        public int $page = 1,
    ) {
        if ($page < 1) {
            throw InventoryException::invalid('La página del kardex debe ser 1 o mayor.');
        }
        if ($limit < 1 || $limit > self::MAX_ROWS) {
            throw InventoryException::invalid('El límite del kardex debe estar entre 1 y '.self::MAX_ROWS.'.');
        }
        if ($from !== null && $to !== null && $from >= $to) {
            throw InventoryException::invalid('El rango de fechas del kardex es inválido.');
        }
    }
}
