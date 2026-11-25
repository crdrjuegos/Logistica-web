<?php

declare(strict_types=1);

namespace App\Modules\Operations\Domain;

/** Una fila del CSV de saldos iniciales, tal como vino (sin validar). */
final readonly class OpeningBalanceRow
{
    public function __construct(
        /** Número de fila en el archivo (la fila 1 es el encabezado). */
        public int $line,
        /** SKU o código de barras del producto. */
        public string $product,
        /** Código de la ubicación. */
        public string $location,
        public string $quantity,
        public string $cost,
    ) {}
}
