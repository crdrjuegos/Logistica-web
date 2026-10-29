<?php

declare(strict_types=1);

namespace App\Modules\Operations\Domain;

/** Lee el CSV de ventas del día que exporta la caja: columnas SKU (o código de barras) y cantidad. */
final class PosSalesParser
{
    public const MAX_ROWS = 20000;

    private const CODE_HEADERS = ['sku', 'codigo', 'cod', 'codigo_barras', 'barcode', 'ean', 'producto'];

    private const QTY_HEADERS = ['cantidad', 'cant', 'qty', 'unidades', 'quantity'];

    public static function parse(string $csv): PosParseResult
    {
        $csv = str_starts_with($csv, "\xEF\xBB\xBF") ? substr($csv, 3) : $csv;
        $lines = preg_split('/\r\n|\r|\n/', $csv) ?: [];

        $numbered = [];
        foreach ($lines as $i => $line) {
            if (trim($line) !== '') {
                $numbered[] = [$i + 1, $line];
            }
        }
        if ($numbered === []) {
            throw OperationsException::invalid('El archivo está vacío.');
        }

        [, $headerLine] = $numbered[0];
        $delimiter = self::detectDelimiter($headerLine);
        $headers = array_map([self::class, 'normalizeHeader'], str_getcsv($headerLine, $delimiter, '"', ''));

        $codeIndex = self::indexOf($headers, self::CODE_HEADERS);
        $qtyIndex = self::indexOf($headers, self::QTY_HEADERS);
        if ($codeIndex === null || $qtyIndex === null) {
            throw OperationsException::invalid('El archivo debe tener las columnas "sku" (o código) y "cantidad" en la primera fila.');
        }

        $data = array_slice($numbered, 1);
        if (count($data) > self::MAX_ROWS) {
            throw OperationsException::invalid('El archivo supera el máximo de '.self::MAX_ROWS.' filas.');
        }

        $rows = [];
        $errors = [];
        foreach ($data as [$lineNumber, $line]) {
            $cells = str_getcsv($line, $delimiter, '"', '');
            $code = trim((string) ($cells[$codeIndex] ?? ''));
            $rawQty = trim((string) ($cells[$qtyIndex] ?? ''));

            if ($code === '') {
                $errors[] = new ImportError($lineNumber, 'Falta el SKU o código del producto.');

                continue;
            }
            if ($delimiter !== ',' && str_contains($rawQty, ',') && ! str_contains($rawQty, '.')) {
                $rawQty = str_replace(',', '.', $rawQty); // coma decimal (Excel en español)
            }
            if (str_starts_with($rawQty, '-')) {
                $errors[] = new ImportError($lineNumber, 'Cantidad negativa: las devoluciones no se importan aquí.', $code);

                continue;
            }
            if (preg_match('/^\d{1,9}(\.\d{1,3})?$/', $rawQty) !== 1 || bccomp($rawQty, '0', 3) <= 0) {
                $errors[] = new ImportError($lineNumber, "Cantidad inválida: '{$rawQty}'.", $code);

                continue;
            }

            $rows[] = new PosSaleRow($lineNumber, $code, bcadd($rawQty, '0', 3));
        }

        return new PosParseResult($rows, $errors, count($data));
    }

    private static function detectDelimiter(string $header): string
    {
        $counts = [';' => substr_count($header, ';'), "\t" => substr_count($header, "\t"), ',' => substr_count($header, ',')];
        arsort($counts);

        return (string) array_key_first($counts);
    }

    private static function normalizeHeader(?string $header): string
    {
        $h = mb_strtolower(trim((string) $header));
        $h = strtr($h, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u']);

        return trim(str_replace([' ', '-'], '_', $h), "\"' ");
    }

    /**
     * @param  list<string>  $headers
     * @param  list<string>  $accepted
     */
    private static function indexOf(array $headers, array $accepted): ?int
    {
        foreach ($headers as $i => $h) {
            if (in_array($h, $accepted, true)) {
                return $i;
            }
        }

        return null;
    }
}
