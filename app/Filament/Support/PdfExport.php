<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

/** Descarga PDF de un reporte en tabla (mismos datos y filtros que el CSV). */
final class PdfExport
{
    /**
     * @param  list<string>  $headers
     * @param  iterable<list<scalar|null>>  $rows
     */
    public static function download(string $baseName, array $headers, iterable $rows): StreamedResponse
    {
        $title = ucfirst(str_replace('-', ' ', $baseName));
        $who = (string) (auth()->user()?->getAttribute('name') ?? '');
        $generated = now('America/Lima')->format('d/m/Y H:i');
        $pdf = SimplePdf::table($title, "Generado el {$generated} (hora de Lima)".($who !== '' ? " por {$who}" : ''), $headers, $rows);

        return response()->streamDownload(static function () use ($pdf): void {
            echo $pdf;
        }, $baseName.'-'.now('America/Lima')->format('Y-m-d').'.pdf', ['Content-Type' => 'application/pdf']);
    }
}
