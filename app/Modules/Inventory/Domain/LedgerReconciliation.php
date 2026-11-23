<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

/** Puerto de la reconciliación nocturna: compara saldos con ledger, avisa y deja constancia. Nunca corrige saldos. */
interface LedgerReconciliation
{
    /**
     * @return list<LedgerDiscrepancy> vacío si los saldos coinciden con el ledger
     *
     * @throws \Throwable si la base de datos no responde
     */
    public function discrepancies(): array;

    /**
     * Abre una alerta crítica por cada diferencia (una sola alerta activa por producto y almacén).
     *
     * @param  list<LedgerDiscrepancy>  $differences
     * @return int alertas nuevas abiertas
     */
    public function openAlerts(array $differences): int;

    /** Guarda el resultado de la última corrida (hora de control incluida). */
    public function recordRun(ReconciliationReport $report): void;
}
