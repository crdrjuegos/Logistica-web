<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

final readonly class CatalogImportResult
{
    /**
     * @param  list<CatalogImportError>  $errors
     * @param  list<string>  $skus  SKU de los productos creados (vacío en simulación)
     */
    public function __construct(
        public int $importId,
        public bool $committed,
        public int $totalRows,
        public int $okRows,
        public array $errors,
        public array $skus = [],
    ) {}

    /** Reporte descargable de errores: fila;codigo;error (con BOM para que Excel lo abra bien). */
    public function errorReportCsv(): string
    {
        $quote = static fn (string $v): string => '"'.str_replace('"', '""', $v).'"';
        $out = "\xEF\xBB\xBFfila;codigo;error\r\n";
        foreach ($this->errors as $e) {
            $out .= $e->line.';'.$quote($e->code).';'.$quote($e->message)."\r\n";
        }

        return $out;
    }
}
