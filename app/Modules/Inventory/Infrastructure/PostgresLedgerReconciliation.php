<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Infrastructure;

use App\Modules\Inventory\Domain\LedgerDiscrepancy;
use App\Modules\Inventory\Domain\LedgerReconciliation;
use App\Modules\Inventory\Domain\ReconciliationReport;
use Illuminate\Support\Facades\DB;

final class PostgresLedgerReconciliation implements LedgerReconciliation
{
    public const SETTING_KEY = 'reconciliation.last_run';

    public function discrepancies(): array
    {
        $rows = DB::select(
            'SELECT p_product_id, p_location_id, p_balance, p_ledger, p_diff FROM fn_reconcile_ledger() ORDER BY p_product_id, p_location_id',
        );

        return array_map(static fn (object $r): LedgerDiscrepancy => new LedgerDiscrepancy(
            (int) $r->p_product_id,
            (int) $r->p_location_id,
            bcadd((string) $r->p_balance, '0', 3),
            bcadd((string) $r->p_ledger, '0', 3),
            bcadd((string) $r->p_diff, '0', 3),
        ), $rows);
    }

    public function openAlerts(array $differences): int
    {
        $opened = 0;
        foreach ($differences as $d) {
            $warehouseId = DB::table('locations')->where('id', $d->locationId)->value('warehouse_id');

            // La BD permite una sola alerta activa por tipo, producto y almacén: si ya existe, no se duplica.
            $opened += DB::affectingStatement(
                "INSERT INTO alerts (type, severity, product_id, warehouse_id, payload)
                 VALUES ('RECONCILIATION_DIFF', 'CRITICAL', ?, ?, ?::jsonb)
                 ON CONFLICT DO NOTHING",
                [$d->productId, $warehouseId, json_encode($d->toArray(), JSON_THROW_ON_ERROR)],
            );
        }

        return $opened;
    }

    public function recordRun(ReconciliationReport $report): void
    {
        DB::statement(
            'INSERT INTO settings (key, value, updated_at) VALUES (?, ?::jsonb, now())
             ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = now()',
            [self::SETTING_KEY, json_encode($report->toArray(), JSON_THROW_ON_ERROR)],
        );
    }
}
