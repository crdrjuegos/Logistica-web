<?php

declare(strict_types=1);

namespace App\Modules\Operations\Domain;

final readonly class ImportResult
{
    /** @param list<ImportError> $errors */
    public function __construct(
        public int $importId,
        public bool $committed,
        public int $totalRows,
        public int $okRows,
        public array $errors,
        /** Total de unidades descontadas (o que se descontarían en simulación). */
        public string $units,
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
