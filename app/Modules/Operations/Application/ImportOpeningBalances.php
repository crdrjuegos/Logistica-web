<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application;

use App\Modules\Audit\Domain\AuditEntry;
use App\Modules\Audit\Domain\AuditTrail;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Inventory\Domain\UnitCost;
use App\Modules\Operations\Domain\ImportError;
use App\Modules\Operations\Domain\ImportJobs;
use App\Modules\Operations\Domain\OpeningBalanceCsvParser;
use App\Modules\Operations\Domain\OpeningBalanceLine;
use App\Modules\Operations\Domain\OpeningBalanceLookup;
use App\Modules\Operations\Domain\OpeningBalanceRow;
use App\Modules\Operations\Domain\OpeningImportResult;
use App\Modules\Operations\Domain\OperationsException;
use App\Shared\Domain\Clock;
use App\Shared\Domain\Transaction;

/**
 * Carga masiva de saldos iniciales desde un CSV (HU-053). Es TODO O NADA, igual que RecordOpeningBalances: si una sola
 * fila tiene error (producto o ubicación inexistente, cantidad o costo inválidos, producto repetido en la misma
 * ubicación o apertura ya cargada) no se carga nada y se devuelve el reporte con fila y causa. «Simular» valida sin
 * registrar. El mismo archivo no se carga dos veces. El permiso inventory.opening_balance lo exige quien invoca.
 */
final class ImportOpeningBalances
{
    private const KIND = 'OPENING_STOCK';

    public function __construct(
        private readonly OpeningBalanceLookup $lookup,
        private readonly RecordOpeningBalances $record,
        private readonly ImportJobs $jobs,
        private readonly Transaction $transaction,
        private readonly AuditTrail $audit,
        private readonly Clock $clock,
    ) {}

    public function __invoke(string $csv, string $fileName, int $userId, string $batchRef, bool $commit): OpeningImportResult
    {
        $ref = strtoupper(trim($batchRef));
        if (preg_match('/^[A-Z0-9][A-Z0-9\-_.\/]{2,39}$/', $ref) !== 1) {
            throw OperationsException::invalid('La referencia del lote de apertura no es válida (3 a 40 letras, números, guion o punto).');
        }
        $name = mb_substr(basename(str_replace('\\', '/', trim($fileName))), 0, 200);
        if ($name === '' || $name === '.') {
            throw OperationsException::invalid('El archivo no tiene nombre.');
        }
        $sha = hash('sha256', $csv);
        $parsed = OpeningBalanceCsvParser::parse($csv);

        return $this->transaction->run(function () use ($parsed, $name, $sha, $ref, $userId, $commit): OpeningImportResult {
            if ($commit && $this->jobs->committedBefore(self::KIND, $sha)) {
                throw OperationsException::invalid('Este archivo ya se cargó antes. Si faltó alguna fila, súbala en un archivo nuevo solo con esas filas.');
            }

            $errors = [];
            $lines = [];
            $seen = [];
            $units = '0.000';

            foreach ($parsed->rows as $row) {
                [$line, $problems] = $this->check($row, $seen);
                if ($line === null) {
                    $errors[] = new ImportError($row->line, implode(' ', $problems), $row->product);

                    continue;
                }
                $seen[$line->productId.':'.$line->locationId] = $row->line;
                $lines[] = $line;
                $units = bcadd($units, $line->quantity->value, 3);
            }

            // Todo o nada: solo se carga si no hay ni un error.
            $apply = $commit && $errors === [];
            if ($apply) {
                ($this->record)($lines, $userId, $ref);
            }

            $total = count($parsed->rows);
            $importId = $this->jobs->record(self::KIND, $name, $sha, $apply, $total, count($lines), count($errors), $userId);
            $this->audit->record(new AuditEntry('opening.import', 'import', $importId, $userId, null, [
                'file' => $name, 'reference' => $ref, 'rows' => $total, 'valid' => count($lines), 'errors' => count($errors),
                'mode' => $apply ? 'COMMIT' : ($commit ? 'BLOCKED' : 'DRY_RUN'), 'at' => $this->clock->now()->format('c'),
            ]));

            return new OpeningImportResult($importId, $apply, $total, count($lines), $errors, $units);
        });
    }

    /**
     * @param  array<string, int>  $seen  producto:ubicación ya vistos en el archivo → fila
     * @return array{0: ?OpeningBalanceLine, 1: list<string>}
     */
    private function check(OpeningBalanceRow $row, array $seen): array
    {
        $problems = [];

        $productId = $row->product === '' ? null : $this->lookup->productId($row->product);
        if ($productId === null) {
            $problems[] = $row->product === '' ? 'Falta el SKU o el código de barras.' : "El producto '{$row->product}' no existe o está inactivo.";
        }

        $locationId = $row->location === '' ? null : $this->lookup->locationId($row->location);
        if ($locationId === null) {
            $problems[] = $row->location === '' ? 'Falta la ubicación.' : "La ubicación '{$row->location}' no existe o está desactivada.";
        }

        $quantity = null;
        $rawQuantity = self::decimal($row->quantity);
        if ($rawQuantity === '') {
            $problems[] = 'Falta la cantidad.';
        } elseif (str_starts_with($rawQuantity, '-')) {
            $problems[] = 'La cantidad no puede ser negativa.';
        } else {
            try {
                $quantity = Quantity::of($rawQuantity);
                if (! $quantity->isPositive()) {
                    $quantity = null;
                    $problems[] = 'La cantidad debe ser mayor que cero.';
                }
            } catch (InventoryException $e) {
                $problems[] = $e->getMessage();
            }
        }

        $cost = null;
        $rawCost = self::decimal($row->cost);
        if ($rawCost === '') {
            $problems[] = 'Falta el costo unitario.';
        } elseif (str_starts_with($rawCost, '-')) {
            $problems[] = 'El costo no puede ser negativo.';
        } else {
            try {
                $cost = UnitCost::of($rawCost);
            } catch (InventoryException $e) {
                $problems[] = $e->getMessage();
            }
        }

        if ($productId !== null && $locationId !== null) {
            $pair = $productId.':'.$locationId;
            if (isset($seen[$pair])) {
                $problems[] = "El producto ya aparece en la fila {$seen[$pair]} para la misma ubicación.";
            } elseif ($this->lookup->hasOpeningBalance($productId, $locationId)) {
                $problems[] = 'Ya existe un saldo inicial de este producto en esa ubicación.';
            }
        }

        if ($problems !== [] || $productId === null || $locationId === null || $quantity === null || $cost === null) {
            return [null, $problems];
        }

        return [new OpeningBalanceLine($productId, $locationId, $quantity, $cost), []];
    }

    /** Acepta la coma decimal que escribe Excel en Windows en español. */
    private static function decimal(string $value): string
    {
        $v = trim($value);

        return str_contains($v, ',') && ! str_contains($v, '.') ? str_replace(',', '.', $v) : $v;
    }
}
