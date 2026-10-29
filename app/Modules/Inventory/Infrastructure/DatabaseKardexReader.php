<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Infrastructure;

use App\Modules\Inventory\Domain\KardexPage;
use App\Modules\Inventory\Domain\KardexQuery;
use App\Modules\Inventory\Domain\KardexReader;
use App\Modules\Inventory\Domain\KardexRow;
use Illuminate\Support\Facades\DB;

final class DatabaseKardexReader implements KardexReader
{
    public function read(KardexQuery $q): KardexPage
    {
        [$scopeSql, $scopeBindings] = $this->scope($q);

        $opening = $q->from === null ? '0' : (string) DB::selectOne(
            "WITH scoped AS ({$scopeSql}) SELECT COALESCE(SUM(delta), 0)::text AS total FROM scoped WHERE created_at < ?",
            [...$scopeBindings, $this->ts($q->from)],
        )->total;

        $closing = (string) DB::selectOne(
            "WITH scoped AS ({$scopeSql}) SELECT COALESCE(SUM(delta), 0)::text AS total FROM scoped".($q->to === null ? '' : ' WHERE created_at < ?'),
            $q->to === null ? $scopeBindings : [...$scopeBindings, $this->ts($q->to)],
        )->total;

        $filters = [];
        $bindings = $scopeBindings;
        if ($q->from !== null) {
            $filters[] = 'r.created_at >= ?';
            $bindings[] = $this->ts($q->from);
        }
        if ($q->to !== null) {
            $filters[] = 'r.created_at < ?';
            $bindings[] = $this->ts($q->to);
        }
        $where = $filters === [] ? '' : 'WHERE '.implode(' AND ', $filters);
        $total = (int) DB::selectOne("WITH scoped AS ({$scopeSql}) SELECT COUNT(*) AS n FROM scoped r {$where}", $bindings)->n;
        $bindings[] = $q->limit;
        $bindings[] = ($q->page - 1) * $q->limit;

        // El orden real es el del id del movimiento (se asigna dentro del bloqueo del producto), no la hora.
        $rows = DB::select(
            "WITH scoped AS ({$scopeSql}),
                  running AS (SELECT s.*, SUM(s.delta) OVER (ORDER BY s.movement_id, s.location_id) AS running_balance FROM scoped s)
             SELECT r.movement_id, r.created_at, r.type, r.delta::text AS delta, r.running_balance::text AS running_balance,
                    l.code AS location_code, m.document_ref, m.notes, m.unit_cost::text AS unit_cost, u.name AS user_name
               FROM running r
               JOIN locations l ON l.id = r.location_id
               JOIN stock_movements m ON m.id = r.movement_id
               JOIN users u ON u.id = m.user_id
              {$where}
              ORDER BY r.movement_id, r.location_id
              LIMIT ? OFFSET ?",
            $bindings,
        );

        return new KardexPage(
            bcadd($opening, '0', 3),
            array_map(static fn (object $r): KardexRow => new KardexRow(
                (int) $r->movement_id, (string) $r->created_at, (string) $r->type,
                bcadd((string) $r->delta, '0', 3), bcadd((string) $r->running_balance, '0', 3),
                (string) $r->location_code, $r->document_ref, $r->notes,
                $r->unit_cost === null ? null : bcadd((string) $r->unit_cost, '0', 4), (string) $r->user_name,
            ), $rows),
            bcadd($closing, '0', 3),
            $total,
            $q->page,
            $q->limit,
        );
    }

    /** @return array{0: string, 1: list<mixed>} */
    private function scope(KardexQuery $q): array
    {
        $sql = 'SELECT e.movement_id, e.created_at, e.type, e.delta, e.location_id
                  FROM v_movement_effects e JOIN locations l ON l.id = e.location_id
                 WHERE e.product_id = ?';
        $bindings = [$q->productId];

        if ($q->warehouseId !== null) {
            $sql .= ' AND l.warehouse_id = ?';
            $bindings[] = $q->warehouseId;
        }
        if ($q->locationId !== null) {
            $sql .= ' AND e.location_id = ?';
            $bindings[] = $q->locationId;
        }

        return [$sql, $bindings];
    }

    private function ts(\DateTimeImmutable $at): string
    {
        return $at->format('Y-m-d H:i:sP');
    }
}
