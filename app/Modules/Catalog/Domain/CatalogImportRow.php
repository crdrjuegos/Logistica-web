<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

/** Una fila del CSV de catálogo, tal como vino (sin validar). */
final readonly class CatalogImportRow
{
    public function __construct(
        /** Número de fila en el archivo (la fila 1 es el encabezado). */
        public int $line,
        public string $name,
        public string $category,
        public string $unit,
        public string $ean = '',
        public string $price = '',
        public string $cost = '',
        public string $sku = '',
    ) {}

    /** Dato que identifica la fila en el reporte de errores. */
    public function reference(): string
    {
        foreach ([$this->sku, $this->ean, $this->name] as $value) {
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }
}
