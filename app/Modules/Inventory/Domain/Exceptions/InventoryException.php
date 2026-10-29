<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Exceptions;

use App\Modules\Inventory\Domain\Quantity;

/**
 * Única excepción de dominio del módulo. El `errorCode` es estable y lo usa la API (problem+json).
 */
final class InventoryException extends \DomainException
{
    /** @param array<string, scalar|null> $context */
    private function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    public static function invalid(string $message): self
    {
        return new self($message, 'INVALID_MOVEMENT');
    }

    public static function productNotFound(int $productId): self
    {
        return new self("El producto {$productId} no existe o fue eliminado.", 'PRODUCT_NOT_FOUND', ['product_id' => $productId]);
    }

    public static function locationNotFound(int $locationId): self
    {
        return new self("La ubicación {$locationId} no existe.", 'LOCATION_NOT_FOUND', ['location_id' => $locationId]);
    }

    public static function locationInactive(string $code): self
    {
        return new self("La ubicación {$code} está desactivada.", 'LOCATION_INACTIVE', ['location' => $code]);
    }

    public static function locationFrozen(string $code): self
    {
        return new self("La ubicación {$code} está congelada por un conteo en curso.", 'LOCATION_FROZEN', ['location' => $code]);
    }

    public static function insufficientStock(string $code, Quantity $available, Quantity $requested): self
    {
        return new self(
            "Stock insuficiente en {$code}: disponible {$available}, solicitado {$requested}.",
            'INSUFFICIENT_STOCK',
            ['location' => $code, 'available' => $available->value, 'requested' => $requested->value],
        );
    }

    public static function idempotencyKeyReused(string $key): self
    {
        return new self(
            "La clave de idempotencia '{$key}' ya se usó con otro movimiento.",
            'IDEMPOTENCY_KEY_REUSED',
            ['key' => $key],
        );
    }

    public static function openingBalanceExists(string $code): self
    {
        return new self(
            "El producto ya tiene saldo inicial en {$code}; corrija con un ajuste, no con otra apertura.",
            'OPENING_BALANCE_EXISTS',
            ['location' => $code],
        );
    }

    public static function idempotencyConflict(string $key): self
    {
        return new self(
            "El reintento con la clave '{$key}' trae contenido distinto al movimiento original; se conserva el original.",
            'IDEMPOTENCY_CONFLICT',
            ['key' => $key],
        );
    }
}
