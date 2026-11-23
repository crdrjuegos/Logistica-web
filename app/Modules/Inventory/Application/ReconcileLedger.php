<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Modules\Audit\Domain\AuditEntry;
use App\Modules\Audit\Domain\AuditTrail;
use App\Modules\Inventory\Domain\LedgerDiscrepancy;
use App\Modules\Inventory\Domain\LedgerReconciliation;
use App\Modules\Inventory\Domain\ReconciliationReport;
use App\Shared\Domain\Clock;
use App\Shared\Domain\Transaction;

/**
 * Control nocturno del ledger (HU-023). Compara los saldos guardados con la suma de los movimientos.
 * Si hay diferencias abre una alerta crítica y deja auditoría, pero NUNCA corrige un saldo por su cuenta.
 * Si algo falla (por ejemplo la base no responde) devuelve un reporte FAILED: nunca informa un cierre correcto
 * que no ocurrió, y se puede reintentar sin efectos secundarios.
 */
final class ReconcileLedger
{
    private const MAX_ITEMS_IN_AUDIT = 20;

    public function __construct(
        private readonly LedgerReconciliation $reconciliation,
        private readonly Transaction $transaction,
        private readonly AuditTrail $audit,
        private readonly Clock $clock,
    ) {}

    public function __invoke(): ReconciliationReport
    {
        $ranAt = $this->clock->now()->format('c');
        /** @var list<LedgerDiscrepancy> $differences */
        $differences = [];

        try {
            $differences = $this->reconciliation->discrepancies();

            if ($differences === []) {
                $report = new ReconciliationReport(ReconciliationReport::OK, $ranAt);
                $this->reconciliation->recordRun($report);

                return $report;
            }

            $report = new ReconciliationReport(ReconciliationReport::DIFFERENCES, $ranAt, $differences);
            $this->transaction->run(function () use ($report, $differences, $ranAt): void {
                $this->reconciliation->openAlerts($differences);
                $this->audit->record(new AuditEntry('ledger.reconciliation_diff', 'ledger', null, null, null, [
                    'differences' => count($differences),
                    'items' => array_map(
                        static fn (LedgerDiscrepancy $d): array => $d->toArray(),
                        array_slice($differences, 0, self::MAX_ITEMS_IN_AUDIT),
                    ),
                    'at' => $ranAt,
                ]));
                $this->reconciliation->recordRun($report);
            });

            return $report;
        } catch (\Throwable $e) {
            $failed = new ReconciliationReport(ReconciliationReport::FAILED, $ranAt, $differences, mb_substr($e->getMessage(), 0, 300));
            $this->tryRecord($failed);

            return $failed;
        }
    }

    private function tryRecord(ReconciliationReport $report): void
    {
        try {
            $this->reconciliation->recordRun($report);
        } catch (\Throwable) {
            // Sin base de datos no hay dónde registrar: el comando deja el fallo en el log.
        }
    }
}
