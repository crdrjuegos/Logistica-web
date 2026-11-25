<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

/** Elige el formato de descarga de un reporte: CSV (por defecto) o PDF. */
final class ReportExport
{
    /**
     * @param  list<string>  $headers
     * @param  iterable<list<scalar|null>>  $rows
     */
    public static function download(string $format, string $baseName, array $headers, iterable $rows): StreamedResponse
    {
        return $format === 'pdf'
            ? PdfExport::download($baseName, $headers, $rows)
            : CsvExport::download($baseName, $headers, $rows);
    }
}
