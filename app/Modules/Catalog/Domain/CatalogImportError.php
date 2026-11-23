<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

final readonly class CatalogImportError
{
    /** @param string $code valor de referencia de la fila (SKU, EAN o nombre) para ubicarla en el archivo */
    public function __construct(public int $line, public string $message, public string $code = '') {}
}
