<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Inventory\Application\ReconcileLedger;
use App\Modules\Inventory\Domain\LedgerDiscrepancy;
use App\Modules\Inventory\Domain\ReconciliationReport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class WmsReconcile extends Command
{
    protected $signature = 'wms:reconcile';

    protected $description = 'Compara los saldos con el ledger (fn_reconcile_ledger), abre alerta crítica si hay diferencias y registra la hora de control';

    public function handle(ReconcileLedger $reconcile): int
    {
        $report = $reconcile();
        $hora = (new \DateTimeImmutable($report->ranAt))->setTimezone(new \DateTimeZone('America/Lima'))->format('d/m/Y H:i');

        if ($report->status === ReconciliationReport::OK) {
            $this->info("Reconciliación de las {$hora}: sin diferencias entre el ledger y los saldos.");

            return self::SUCCESS;
        }

        if ($report->status === ReconciliationReport::DIFFERENCES) {
            $this->table(
                ['Producto', 'Ubicación', 'Saldo guardado', 'Según ledger', 'Diferencia'],
                array_map(static fn (LedgerDiscrepancy $d): array => [$d->productId, $d->locationId, $d->balance, $d->ledger, $d->difference], $report->discrepancies),
            );
            $this->error('Reconciliación de las '.$hora.': '.count($report->discrepancies).' diferencia(s). Se abrió una alerta crítica; los saldos NO se corrigieron.');
            Log::critical('Reconciliación del ledger con diferencias', $report->toArray());

            return self::FAILURE;
        }

        $this->error("La reconciliación de las {$hora} no se pudo completar: ".($report->error ?? 'error desconocido').'. Reintente con: php artisan wms:reconcile');
        Log::error('La reconciliación del ledger falló', $report->toArray());

        return self::FAILURE;
    }
}
