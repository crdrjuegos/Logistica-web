<?php

declare(strict_types=1);

namespace App\Modules\Operations\Domain;

/**
 * Lee el CSV de saldos iniciales (separador ; o ,). Columnas obligatorias: sku (o código de barras), ubicacion,
 * cantidad y costo. Acepta el encabezado con o sin tildes y mayúsculas.
 */
final class OpeningBalanceCsvParser
{
    private const ALIASES = [
        'sku' => 'product', 'codigo' => 'product', 'ean' => 'product', 'codigo_barras' => 'product', 'producto' => 'product',
        'ubicacion' => 'location', 'codigo_ubicacion' => 'location', 'location' => 'location',
        'cantidad' => 'quantity', 'stock' => 'quantity', 'qty' => 'quantity',
        'costo' => 'cost', 'costo_unitario' => 'cost', 'costo_unit' => 'cost', 'cost' => 'cost',
    ];

    public static function parse(string $csv): OpeningBalanceParse
    {
        $csv = (string) preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        $csv = str_replace(["\r\n", "\r"], "\n", $csv);
        if (trim($csv) === '') {
            throw OperationsException::invalid('El archivo está vacío.');
        }

        $firstLine = explode("\n", ltrim($csv, "\n"), 2)[0];
        $delimiter = substr_count($firstLine, ';') >= substr_count($firstLine, ',') ? ';' : ',';

        $handle = fopen('php://memory', 'r+');
        if ($handle === false) {
            throw OperationsException::invalid('No se pudo leer el archivo.');
        }
        fwrite($handle, $csv);
        rewind($handle);

        try {
            $header = fgetcsv($handle, 0, $delimiter, '"', '');
            while ($header !== false && self::isBlank($header)) {
                $header = fgetcsv($handle, 0, $delimiter, '"', '');
            }
            if ($header === false) {
                throw OperationsException::invalid('El archivo no tiene encabezado.');
            }

            $columns = self::mapHeader($header);
            $labels = ['product' => 'sku', 'location' => 'ubicacion', 'quantity' => 'cantidad', 'cost' => 'costo'];
            $missing = array_diff(array_keys($labels), array_values($columns));
            if ($missing !== []) {
                throw OperationsException::invalid('Faltan columnas obligatorias en el encabezado: '.implode(', ', array_map(static fn (string $m): string => $labels[$m], array_values($missing))).'.');
            }

            $rows = [];
            $line = 1;
            while (($record = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
                $line++;
                if (self::isBlank($record)) {
                    continue;
                }
                if (count($rows) >= 5000) {
                    throw OperationsException::invalid('El archivo supera el máximo de 5000 filas. Divídalo en varios archivos.');
                }
                $values = [];
                foreach ($columns as $index => $field) {
                    $values[$field] = trim((string) ($record[$index] ?? ''));
                }
                $rows[] = new OpeningBalanceRow($line, $values['product'] ?? '', $values['location'] ?? '', $values['quantity'] ?? '', $values['cost'] ?? '');
            }
        } finally {
            fclose($handle);
        }

        if ($rows === []) {
            throw OperationsException::invalid('El archivo no tiene filas de datos.');
        }

        return new OpeningBalanceParse($rows);
    }

    /**
     * @param  array<int, string|null>  $header
     * @return array<int, string>
     */
    private static function mapHeader(array $header): array
    {
        $map = [];
        foreach ($header as $index => $label) {
            $key = strtr(mb_strtolower(trim((string) $label)), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', ' ' => '_', '-' => '_']);
            if (isset(self::ALIASES[$key]) && ! in_array(self::ALIASES[$key], $map, true)) {
                $map[$index] = self::ALIASES[$key];
            }
        }

        return $map;
    }

    /** @param array<int, string|null> $record */
    private static function isBlank(array $record): bool
    {
        foreach ($record as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }
}
