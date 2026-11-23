<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

/**
 * Lee el CSV de catálogo (separador ; o ,). Columnas: nombre, categoria y unidad son obligatorias;
 * ean, precio, costo y sku son opcionales. Acepta el encabezado con o sin tildes y mayúsculas.
 */
final class CatalogCsvParser
{
    public const MAX_ROWS = 5000;

    private const ALIASES = [
        'nombre' => 'name', 'producto' => 'name', 'name' => 'name',
        'categoria' => 'category', 'category' => 'category',
        'unidad' => 'unit', 'unidad_base' => 'unit', 'unit' => 'unit',
        'ean' => 'ean', 'codigo' => 'ean', 'codigo_barras' => 'ean', 'barcode' => 'ean',
        'precio' => 'price', 'precio_referencial' => 'price', 'price' => 'price',
        'costo' => 'cost', 'cost' => 'cost',
        'sku' => 'sku',
    ];

    public static function parse(string $csv): CatalogImportParse
    {
        $csv = (string) preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        $csv = str_replace(["\r\n", "\r"], "\n", $csv);
        if (trim($csv) === '') {
            throw CatalogException::invalid('El archivo está vacío.');
        }

        $firstLine = explode("\n", ltrim($csv, "\n"), 2)[0];
        $delimiter = substr_count($firstLine, ';') >= substr_count($firstLine, ',') ? ';' : ',';

        $handle = fopen('php://memory', 'r+');
        if ($handle === false) {
            throw CatalogException::invalid('No se pudo leer el archivo.');
        }
        fwrite($handle, $csv);
        rewind($handle);

        try {
            $header = fgetcsv($handle, 0, $delimiter, '"', '');
            while ($header !== false && self::isBlank($header)) {
                $header = fgetcsv($handle, 0, $delimiter, '"', '');
            }
            if ($header === false) {
                throw CatalogException::invalid('El archivo no tiene encabezado.');
            }

            $columns = self::mapHeader($header);
            $missing = array_diff(['name', 'category', 'unit'], array_values($columns));
            if ($missing !== []) {
                $labels = ['name' => 'nombre', 'category' => 'categoria', 'unit' => 'unidad'];
                throw CatalogException::invalid('Faltan columnas obligatorias en el encabezado: '.implode(', ', array_map(static fn (string $m): string => $labels[$m], array_values($missing))).'.');
            }

            $rows = [];
            $total = 0;
            $line = 1;
            while (($record = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
                $line++;
                if (self::isBlank($record)) {
                    continue;
                }
                if (++$total > self::MAX_ROWS) {
                    throw CatalogException::invalid('El archivo supera el máximo de '.self::MAX_ROWS.' filas. Divídalo en varios archivos.');
                }
                $values = [];
                foreach ($columns as $index => $field) {
                    $values[$field] = trim((string) ($record[$index] ?? ''));
                }
                $rows[] = new CatalogImportRow(
                    $line,
                    $values['name'] ?? '',
                    $values['category'] ?? '',
                    $values['unit'] ?? '',
                    $values['ean'] ?? '',
                    $values['price'] ?? '',
                    $values['cost'] ?? '',
                    $values['sku'] ?? '',
                );
            }
        } finally {
            fclose($handle);
        }

        return new CatalogImportParse($rows, $total);
    }

    /**
     * @param  array<int, string|null>  $header
     * @return array<int, string> índice de columna => campo
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
