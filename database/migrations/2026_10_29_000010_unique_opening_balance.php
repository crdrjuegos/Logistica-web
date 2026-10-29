<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * D02 / CA-053-2: un solo SALDO_INICIAL por producto y ubicación, aunque cambie la clave de idempotencia.
 * Defensa en profundidad: el caso de uso RegisterMovement ya lo valida antes de insertar.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
        CREATE UNIQUE INDEX ux_mov_opening_balance
            ON stock_movements (product_id, to_location_id)
            WHERE type = 'SALDO_INICIAL'
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ux_mov_opening_balance');
    }
};
