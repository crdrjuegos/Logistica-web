<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application;

use App\Modules\Audit\Domain\AuditEntry;
use App\Modules\Audit\Domain\AuditTrail;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Operations\Domain\ImportError;
use App\Modules\Operations\Domain\ImportJobs;
use App\Modules\Operations\Domain\ImportResult;
use App\Modules\Operations\Domain\MovementKind;
use App\Modules\Operations\Domain\MovementRequest;
use App\Modules\Operations\Domain\OperationsException;
use App\Modules\Operations\Domain\PosSalesParser;
use App\Modules\Operations\Domain\SalesTargets;
use App\Modules\Operations\Domain\StockMovements;
use App\Shared\Domain\Clock;
use App\Shared\Domain\Transaction;

/**
 * Descuenta del piso de venta las ventas del día que exporta la caja (CSV: sku, cantidad).
 * Las filas con error van al reporte; las válidas se aplican. El mismo archivo no se confirma dos veces.
 * La simulación (commit = false) calcula todo sin tocar el stock.
 */
final class ImportPosSales
{
    private const KIND = 'POS_SALES';

    public function __construct(
        private readonly SalesTargets $targets,
        private readonly StockMovements $movements,
        private readonly ImportJobs $jobs,
        private readonly Transaction $transaction,
        private readonly AuditTrail $audit,
        private readonly Clock $clock,
    ) {}

    public function __invoke(string $csv, string $fileName, string $businessDate, int $userId, bool $commit): ImportResult
    {
        $this->assertDate($businessDate);
        $name = mb_substr(basename(str_replace('\\', '/', trim($fileName))), 0, 200);
        if ($name === '' || $name === '.') {
            throw OperationsException::invalid('El archivo no tiene nombre.');
        }
        $sha = hash('sha256', $csv);
        $parsed = PosSalesParser::parse($csv);

        return $this->transaction->run(function () use ($parsed, $name, $sha, $businessDate, $userId, $commit): ImportResult {
            if ($commit && $this->jobs->committedBefore(self::KIND, $sha)) {
                throw OperationsException::wrongState('Este archivo ya se importó antes. Si faltó alguna fila, súbala en un archivo nuevo solo con esas filas.');
            }

            $errors = $parsed->errors;

            // Agrupa por producto + ubicación (la caja puede repetir un mismo SKU en varias filas).
            $groups = [];
            foreach ($parsed->rows as $row) {
                $target = $this->targets->resolve($row->code);
                if ($target === null) {
                    $errors[] = new ImportError($row->line, "No se encontró el producto '{$row->code}' o no tiene ubicación en el piso de venta.", $row->code);

                    continue;
                }
                $key = $target->productId.':'.$target->locationId;
                $groups[$key] ??= ['target' => $target, 'line' => $row->line, 'code' => $row->code, 'quantity' => '0.000'];
                $groups[$key]['quantity'] = bcadd($groups[$key]['quantity'], $row->quantity, 3);
            }
            uasort($groups, static fn (array $a, array $b): int => [$a['target']->productId, $a['target']->locationId] <=> [$b['target']->productId, $b['target']->locationId]);

            $ok = 0;
            $units = '0.000';
            $failedGroups = 0;
            foreach ($groups as $group) {
                $target = $group['target'];

                if (bccomp($target->available, $group['quantity'], 3) < 0) {
                    $errors[] = new ImportError($group['line'], "Stock insuficiente de {$target->sku} en {$target->locationCode}: disponible {$target->available}, vendido {$group['quantity']}.", $group['code']);
                    $failedGroups++;

                    continue;
                }
                if ($commit) {
                    try {
                        $this->movements->register(new MovementRequest(
                            kind: MovementKind::Sale,
                            productId: $target->productId,
                            quantity: Quantity::of($group['quantity']),
                            userId: $userId,
                            idempotencyKey: 'POS-'.substr($sha, 0, 16).'-'.$target->productId.'-'.$target->locationId,
                            fromLocationId: $target->locationId,
                            sourceType: 'POS_IMPORT',
                            documentRef: 'POS '.$businessDate,
                            notes: 'Importación '.mb_substr($name, 0, 60),
                        ));
                    } catch (OperationsException $e) {
                        $errors[] = new ImportError($group['line'], $e->getMessage(), $group['code']);
                        $failedGroups++;

                        continue;
                    }
                }
                $ok++;
                $units = bcadd($units, $group['quantity'], 3);
            }

            usort($errors, static fn (ImportError $a, ImportError $b): int => $a->line <=> $b->line);
            $importId = $this->jobs->record(self::KIND, $name, $sha, $commit, $parsed->totalRows, $ok, count($errors), $userId);

            $this->audit->record(new AuditEntry($commit ? 'pos.imported' : 'pos.simulated', 'import', $importId, $userId, null, [
                'file' => $name, 'business_date' => $businessDate, 'rows' => $parsed->totalRows,
                'products_applied' => $ok, 'errors' => count($errors), 'units' => $units, 'at' => $this->clock->now()->format('c'),
            ]));

            return new ImportResult($importId, $commit, $parsed->totalRows, $ok, $errors, $units);
        });
    }

    private function assertDate(string $date): void
    {
        $parts = explode('-', $date);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || ! checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0])) {
            throw OperationsException::invalid('La fecha de las ventas no es válida (use AAAA-MM-DD).');
        }
        if ($date > $this->clock->now()->modify('+1 day')->format('Y-m-d')) {
            throw OperationsException::invalid('La fecha de las ventas no puede ser futura.');
        }
    }
}
