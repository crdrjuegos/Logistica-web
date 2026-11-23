<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Modules\Audit\Domain\AuditEntry;
use App\Modules\Audit\Domain\AuditTrail;
use App\Modules\Catalog\Domain\Barcode;
use App\Modules\Catalog\Domain\BarcodeInput;
use App\Modules\Catalog\Domain\CatalogCodes;
use App\Modules\Catalog\Domain\CatalogCsvParser;
use App\Modules\Catalog\Domain\CatalogException;
use App\Modules\Catalog\Domain\CatalogImportError;
use App\Modules\Catalog\Domain\CatalogImportJobs;
use App\Modules\Catalog\Domain\CatalogImportResult;
use App\Modules\Catalog\Domain\CatalogImportRow;
use App\Modules\Catalog\Domain\CatalogRepository;
use App\Modules\Catalog\Domain\NewProduct;
use App\Modules\Catalog\Domain\Symbology;
use App\Shared\Domain\Clock;
use App\Shared\Domain\Transaction;

/**
 * Carga masiva de productos desde un CSV (HU-052). Valida fila por fila con las mismas reglas del formulario
 * (categoría y unidad existentes, EAN con dígito verificador, sin SKU ni código de barras repetidos, precio y costo
 * no negativos). Las filas con error van al reporte con su número de fila y causa; las válidas se crean.
 * El mismo archivo no se confirma dos veces. La simulación (commit = false) valida todo sin insertar nada.
 * El SKU lo genera el sistema: si el CSV trae uno que ya existe, la fila se rechaza. El costo se valida pero no se
 * guarda: el costo entra con el saldo inicial (HU-053).
 */
final class ImportCatalog
{
    public function __construct(
        private readonly CatalogCodes $codes,
        private readonly CatalogRepository $catalog,
        private readonly CreateProduct $createProduct,
        private readonly CatalogImportJobs $jobs,
        private readonly Transaction $transaction,
        private readonly AuditTrail $audit,
        private readonly Clock $clock,
    ) {}

    public function __invoke(string $csv, string $fileName, int $userId, bool $commit): CatalogImportResult
    {
        $name = mb_substr(basename(str_replace('\\', '/', trim($fileName))), 0, 200);
        if ($name === '' || $name === '.') {
            throw CatalogException::invalid('El archivo no tiene nombre.');
        }
        $sha = hash('sha256', $csv);
        $parsed = CatalogCsvParser::parse($csv);

        return $this->transaction->run(function () use ($parsed, $name, $sha, $userId, $commit): CatalogImportResult {
            if ($commit && $this->jobs->committedBefore($sha)) {
                throw CatalogException::invalid('Este archivo ya se importó antes. Si faltó alguna fila, súbala en un archivo nuevo solo con esas filas.');
            }

            $errors = $parsed->errors;
            $skus = [];
            $ok = 0;
            $seenEans = [];
            $seenSkus = [];

            foreach ($parsed->rows as $row) {
                [$product, $problems] = $this->check($row, $userId, $seenEans, $seenSkus);

                if ($product === null) {
                    $errors[] = new CatalogImportError($row->line, implode(' ', $problems), $row->reference());

                    continue;
                }

                if ($commit) {
                    try {
                        $created = ($this->createProduct)($product);
                        $skus[] = $created['sku'];
                    } catch (CatalogException $e) {
                        $errors[] = new CatalogImportError($row->line, $e->getMessage(), $row->reference());

                        continue;
                    }
                }

                if ($row->ean !== '') {
                    $seenEans[$row->ean] = $row->line;
                }
                if ($row->sku !== '') {
                    $seenSkus[strtoupper($row->sku)] = $row->line;
                }
                $ok++;
            }

            usort($errors, static fn (CatalogImportError $a, CatalogImportError $b): int => $a->line <=> $b->line);
            $importId = $this->jobs->record($name, $sha, $commit, $parsed->totalRows, $ok, count($errors), $userId);

            $this->audit->record(new AuditEntry($commit ? 'catalog.imported' : 'catalog.simulated', 'import', $importId, $userId, null, [
                'file' => $name, 'rows' => $parsed->totalRows, 'products' => $ok, 'errors' => count($errors),
                'at' => $this->clock->now()->format('c'),
            ]));

            return new CatalogImportResult($importId, $commit, $parsed->totalRows, $ok, $errors, $skus);
        });
    }

    /**
     * @param  array<string, int>  $seenEans
     * @param  array<string, int>  $seenSkus
     * @return array{0: ?NewProduct, 1: list<string>}
     */
    private function check(CatalogImportRow $row, int $userId, array $seenEans, array $seenSkus): array
    {
        $problems = [];

        $name = null;
        try {
            $name = NewProduct::normalizeName($row->name);
        } catch (CatalogException $e) {
            $problems[] = $e->getMessage();
        }

        $categoryId = $row->category === '' ? null : $this->codes->categoryId($row->category);
        if ($categoryId === null) {
            $problems[] = $row->category === '' ? 'Falta la categoría.' : "La categoría '{$row->category}' no existe o está desactivada.";
        }

        $unitId = $row->unit === '' ? null : $this->codes->unitId($row->unit);
        if ($unitId === null) {
            $problems[] = $row->unit === '' ? 'Falta la unidad.' : "La unidad '{$row->unit}' no existe.";
        }

        $barcode = null;
        if ($row->ean !== '') {
            $symbology = match (strlen($row->ean)) {
                13 => Symbology::Ean13,
                12 => Symbology::UpcA,
                8 => Symbology::Ean8,
                default => null,
            };
            if ($symbology === null) {
                $problems[] = "El código de barras '{$row->ean}' debe tener 8, 12 o 13 dígitos.";
            } else {
                try {
                    $barcode = Barcode::of($row->ean, $symbology);
                } catch (CatalogException $e) {
                    $problems[] = $e->getMessage();
                }
            }
            if ($barcode !== null && isset($seenEans[$row->ean])) {
                $problems[] = "El código de barras {$row->ean} está repetido en el archivo (fila {$seenEans[$row->ean]}).";
            } elseif ($barcode !== null && $this->catalog->barcodeOwner($row->ean) !== null) {
                $problems[] = "El código de barras {$row->ean} ya pertenece a otro producto.";
            }
        }

        if ($row->sku !== '') {
            $key = strtoupper($row->sku);
            $existing = $this->catalog->lookup($row->sku);
            if (isset($seenSkus[$key])) {
                $problems[] = "El SKU {$row->sku} está repetido en el archivo (fila {$seenSkus[$key]}).";
            } elseif ($existing !== null && strtoupper($existing->sku) === $key) {
                $problems[] = "El SKU {$row->sku} ya existe en el catálogo.";
            }
        }

        $price = self::decimal($row->price);
        if ($price !== '' && ! self::isNonNegative($price)) {
            $problems[] = str_starts_with($price, '-') ? 'El precio no puede ser negativo.' : 'El precio no es válido (máximo 2 decimales).';
        } elseif ($price !== '' && preg_match('/^\d{1,10}(\.\d{1,2})?$/', $price) !== 1) {
            $problems[] = 'El precio no es válido (máximo 2 decimales).';
        }

        $cost = self::decimal($row->cost);
        if ($cost !== '' && preg_match('/^\d{1,10}(\.\d{1,4})?$/', $cost) !== 1) {
            $problems[] = str_starts_with($cost, '-') ? 'El costo no puede ser negativo.' : 'El costo no es válido (máximo 4 decimales).';
        }

        if ($problems !== [] || $name === null || $categoryId === null || $unitId === null) {
            return [null, $problems];
        }

        try {
            return [new NewProduct(
                name: $name,
                categoryId: $categoryId,
                baseUnitId: $unitId,
                actorId: $userId,
                referencePrice: $price !== '' ? $price : null,
                barcodes: $barcode !== null ? [new BarcodeInput($barcode, null, true)] : [],
            ), []];
        } catch (CatalogException $e) {
            return [null, [$e->getMessage()]];
        }
    }

    /** Acepta la coma decimal que escribe Excel en Windows en español. */
    private static function decimal(string $value): string
    {
        $v = trim($value);

        return str_contains($v, ',') && ! str_contains($v, '.') ? str_replace(',', '.', $v) : $v;
    }

    private static function isNonNegative(string $value): bool
    {
        return ! str_starts_with($value, '-');
    }
}
