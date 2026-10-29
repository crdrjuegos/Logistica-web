<?php

declare(strict_types=1);

namespace App\Modules\Operations\Domain;

final readonly class ImportError
{
    /** @param string $code código o SKU tal como venía en la fila (vacío si faltaba) */
    public function __construct(public int $line, public string $message, public string $code = '') {}
}
