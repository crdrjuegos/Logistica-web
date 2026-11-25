<?php

declare(strict_types=1);

namespace App\Modules\Operations\Domain;

/** Resuelve lo que el usuario escribe en el CSV de saldos iniciales (SKU o código de barras, código de ubicación). */
interface OpeningBalanceLookup
{
    /** Producto activo por SKU o por código de barras. */
    public function productId(string $skuOrBarcode): ?int;

    /** Ubicación activa por código. */
    public function locationId(string $code): ?int;

    /** ¿Ya hay un SALDO_INICIAL de este producto en esa ubicación? */
    public function hasOpeningBalance(int $productId, int $locationId): bool;
}
