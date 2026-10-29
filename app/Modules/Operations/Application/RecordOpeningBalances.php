<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application;

use App\Modules\Audit\Domain\AuditEntry;
use App\Modules\Audit\Domain\AuditTrail;
use App\Modules\Operations\Domain\MovementKind;
use App\Modules\Operations\Domain\MovementRequest;
use App\Modules\Operations\Domain\OpeningBalanceLine;
use App\Modules\Operations\Domain\OperationsException;
use App\Modules\Operations\Domain\StockMovements;
use App\Shared\Domain\Transaction;

/**
 * HU-053: registra el saldo inicial (SALDO_INICIAL) de un lote de ubicaciones, todo o nada.
 * Una sola apertura por producto y ubicación: el ledger rechaza la segunda aunque cambie la clave.
 * El permiso inventory.opening_balance lo exige quien invoca (panel o comando).
 */
final class RecordOpeningBalances
{
    public const MAX_LINES = 5000;

    public function __construct(
        private readonly StockMovements $movements,
        private readonly Transaction $transaction,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * @param  list<OpeningBalanceLine>  $lines
     * @return array{lines: int, units: string}
     */
    public function __invoke(array $lines, int $userId, string $batchRef): array
    {
        $ref = strtoupper(trim($batchRef));
        if (preg_match('/^[A-Z0-9][A-Z0-9\-_.\/]{2,39}$/', $ref) !== 1) {
            throw OperationsException::invalid('La referencia del lote de apertura no es válida (3 a 40 letras, números, guion o punto).');
        }
        if ($lines === [] || count($lines) > self::MAX_LINES) {
            throw OperationsException::invalid('La apertura debe tener entre 1 y '.self::MAX_LINES.' líneas.');
        }

        return $this->transaction->run(function () use ($lines, $userId, $ref): array {
            $units = '0.000';
            $seen = [];
            foreach ($lines as $line) {
                $pair = $line->productId.':'.$line->locationId;
                if (isset($seen[$pair])) {
                    throw OperationsException::invalid("El lote repite el producto {$line->productId} en la misma ubicación.");
                }
                $seen[$pair] = true;

                $this->movements->register(new MovementRequest(
                    kind: MovementKind::OpeningBalance,
                    productId: $line->productId,
                    quantity: $line->quantity,
                    userId: $userId,
                    idempotencyKey: 'OPEN-'.substr(hash('sha256', $ref), 0, 16).'-'.$line->productId.'-'.$line->locationId,
                    toLocationId: $line->locationId,
                    unitCost: $line->unitCost,
                    sourceType: 'OPENING',
                    documentRef: $ref,
                ));
                $units = bcadd($units, $line->quantity->value, 3);
            }

            $this->audit->record(new AuditEntry('opening.recorded', 'opening', null, $userId, null, [
                'reference' => $ref, 'lines' => count($lines), 'units' => $units,
            ]));

            return ['lines' => count($lines), 'units' => $units];
        });
    }
}
