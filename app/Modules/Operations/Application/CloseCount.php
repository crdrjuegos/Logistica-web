<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application;

use App\Modules\Audit\Domain\AuditEntry;
use App\Modules\Audit\Domain\AuditTrail;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Operations\Domain\AdjustmentKind;
use App\Modules\Operations\Domain\AdjustmentRepository;
use App\Modules\Operations\Domain\CountLineRecord;
use App\Modules\Operations\Domain\CountLocations;
use App\Modules\Operations\Domain\CountRepository;
use App\Modules\Operations\Domain\CountStatus;
use App\Modules\Operations\Domain\DocumentNumbers;
use App\Modules\Operations\Domain\MovementRequest;
use App\Modules\Operations\Domain\NewAdjustment;
use App\Modules\Operations\Domain\OperationsException;
use App\Modules\Operations\Domain\OperationsLookup;
use App\Modules\Operations\Domain\StockMovements;
use App\Shared\Domain\Clock;
use App\Shared\Domain\Transaction;

/**
 * Cierra el conteo: aplica las diferencias al ledger (ajustes que quedan enlazados al conteo), descongela y
 * calcula la exactitud del inventario. Quien cierra NO puede haber contado las líneas con diferencia.
 */
final class CloseCount
{
    public function __construct(
        private readonly CountRepository $counts,
        private readonly AdjustmentRepository $adjustments,
        private readonly StockMovements $movements,
        private readonly CountLocations $locations,
        private readonly OperationsLookup $lookup,
        private readonly DocumentNumbers $numbers,
        private readonly Transaction $transaction,
        private readonly AuditTrail $audit,
        private readonly Clock $clock,
    ) {}

    /** @return array{adjustments: int, lines: int, accuracyPct: string} */
    public function __invoke(int $countId, int $closerId): array
    {
        return $this->transaction->run(function () use ($countId, $closerId): array {
            $count = $this->counts->lockCount($countId) ?? throw OperationsException::notFound('El conteo', $countId);
            $lines = $this->counts->countLines($countId);

            if ($count->status === CountStatus::Closed) {
                return $this->summary($lines); // idempotente
            }
            if ($count->status !== CountStatus::Review) {
                throw OperationsException::wrongState('Solo un conteo en revisión se puede cerrar.');
            }

            $pendingRecounts = count(array_filter($lines, static fn (CountLineRecord $l): bool => $l->recountRequested));
            if ($pendingRecounts > 0) {
                throw OperationsException::wrongState("Faltan {$pendingRecounts} líneas por recontar antes de cerrar.");
            }

            $different = array_values(array_filter($lines, static fn (CountLineRecord $l): bool => $l->hasDifference()));
            foreach ($different as $line) {
                if ($line->countedBy === $closerId) {
                    throw OperationsException::separationOfDuties(); // otra persona debe cerrar si usted contó diferencias
                }
            }

            $favor = $this->lookup->reasonIdByCode('AJU_CONTEO_FAVOR');
            $error = $this->lookup->reasonIdByCode('AJU_ERROR_CONTEO');
            if ($different !== [] && ($favor === null || $error === null)) {
                throw OperationsException::invalid('Faltan los motivos de ajuste por conteo en el catálogo de motivos.');
            }

            usort($different, static fn (CountLineRecord $a, CountLineRecord $b): int => [$a->productId, $a->locationId] <=> [$b->productId, $b->locationId]);
            foreach ($different as $line) {
                $this->applyDifference($count->code, $countId, $line, $closerId, (int) $favor, (int) $error);
            }

            $this->locations->unfreeze($count->locationIds, $closerId, $count->code);
            $this->counts->setCountStatus($countId, CountStatus::Closed, $closerId, $this->clock->now());

            $result = $this->summary($this->counts->countLines($countId));
            $this->audit->record(new AuditEntry('count.closed', 'count', $countId, $closerId, ['status' => 'REVIEW'], [
                'status' => 'CLOSED', 'code' => $count->code, 'adjustments' => count($different),
                'lines' => $result['lines'], 'accuracy_pct' => $result['accuracyPct'],
            ]));

            return $result;
        });
    }

    private function applyDifference(string $countCode, int $countId, CountLineRecord $line, int $closerId, int $favorReason, int $errorReason): void
    {
        $difference = (string) $line->difference();
        $positive = bccomp($difference, '0', 3) > 0;
        $quantity = Quantity::of(ltrim($difference, '-'));
        $kind = $positive ? AdjustmentKind::Positive : AdjustmentKind::Negative;
        $requestedBy = (int) $line->countedBy;
        $notes = "Conteo {$countCode}: esperado {$line->expected->value}, contado ".($line->counted->value ?? '0');

        $adjustment = new NewAdjustment($kind, $line->productId, $line->locationId, $quantity, $positive ? $favorReason : $errorReason, $notes, $requestedBy);
        $adjustmentCode = $this->numbers->next('AJU');
        $adjustmentId = $this->adjustments->insertAdjustment($adjustment, $adjustmentCode);

        $movementId = $this->movements->register(new MovementRequest(
            kind: $kind->movementKind(),
            productId: $line->productId,
            quantity: $quantity,
            userId: $requestedBy,
            idempotencyKey: $countCode.':L'.$line->id,
            fromLocationId: $positive ? null : $line->locationId,
            toLocationId: $positive ? $line->locationId : null,
            reasonId: $positive ? $favorReason : $errorReason,
            sourceType: 'COUNT', // permitido sobre ubicaciones congeladas por este conteo
            sourceId: $countId,
            documentRef: $countCode,
            notes: $notes,
            approvedBy: $closerId,
        ));

        $this->adjustments->markApproved($adjustmentId, $closerId, $movementId, "Aplicado al cerrar el conteo {$countCode}");
        $this->counts->setLineAdjustment($line->id, $adjustmentId);
    }

    /**
     * @param  list<CountLineRecord>  $lines
     * @return array{adjustments: int, lines: int, accuracyPct: string}
     */
    private function summary(array $lines): array
    {
        $total = count($lines);
        $withDifference = count(array_filter($lines, static fn (CountLineRecord $l): bool => $l->hasDifference()));
        // Porcentaje con 2 decimales, redondeo half-up (2/3 = 66.67, no 66.66).
        $accuracy = $total === 0 ? '100.00' : bcadd(bcdiv(bcmul((string) ($total - $withDifference), '100', 6), (string) $total, 6), '0.005', 2);

        return ['adjustments' => $withDifference, 'lines' => $total, 'accuracyPct' => $accuracy];
    }
}
