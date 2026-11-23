<?php

declare(strict_types=1);

use App\Modules\Iam\Domain\RoleName;
use App\Modules\Inventory\Application\ReconcileLedger;
use App\Modules\Inventory\Domain\LedgerReconciliation;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Inventory\Domain\ReconciliationReport;
use App\Modules\Inventory\Domain\UnitCost;
use App\Modules\Inventory\Infrastructure\PostgresLedgerReconciliation;
use App\Modules\Operations\Application\RecordOpeningBalances;
use App\Modules\Operations\Domain\OpeningBalanceLine;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\Fixtures;
use Tests\Support\PanelUsers;

beforeEach(fn () => PanelUsers::seedRoles());

// HU-023 Reconciliación programada del ledger

/** @return array<string, mixed> */
function reconciliationLast(): array
{
    return json_decode((string) DB::table('settings')->where('key', 'reconciliation.last_run')->value('value'), true, 512, JSON_THROW_ON_ERROR);
}

/** @return array{0: int, 1: int} producto y ubicación con 10 UND cargadas por el ledger */
function reconciliationStock(): array
{
    $admin = PanelUsers::withRole(RoleName::Admin);
    $product = Fixtures::product();
    $loc = Fixtures::location('BOD');
    app(RecordOpeningBalances::class)([new OpeningBalanceLine($product, $loc, Quantity::of('10'), UnitCost::of('2'))], $admin->id, 'APERTURA-RECON');

    return [$product, $loc];
}

it('CA-023-1 con ledger y saldos consistentes wms:reconcile ejecuta fn_reconcile_ledger, no encuentra diferencias, registra la hora de control y está programada cada noche', function () {
    reconciliationStock();

    $this->artisan('wms:reconcile')->assertExitCode(0);

    $last = reconciliationLast();
    expect($last['status'])->toBe('OK');
    expect($last['discrepancies'])->toBe([]);
    expect(strtotime((string) $last['ran_at']))->toBeGreaterThan(time() - 300);
    expect(DB::table('alerts')->where('type', 'RECONCILIATION_DIFF')->count())->toBe(0);
    expect(DB::select('SELECT * FROM fn_reconcile_ledger()'))->toHaveCount(0);

    Artisan::call('schedule:list'); // carga las tareas programadas
    $task = collect(app(Schedule::class)->events())->first(static fn ($event): bool => str_contains((string) $event->command, 'wms:reconcile'));
    expect($task)->not->toBeNull();
    expect([$task->expression, (string) $task->timezone])->toBe(['0 2 * * *', 'America/Lima']);
});

it('CA-023-2 con un saldo alterado en 1 unidad devuelve la diferencia, abre una alerta crítica y no la corrige en silencio', function () {
    [$product, $loc] = reconciliationStock();
    DB::table('stock_balances')->where('product_id', $product)->where('location_id', $loc)->update(['quantity' => DB::raw('quantity + 1')]);

    $this->artisan('wms:reconcile')->assertExitCode(1);

    $last = reconciliationLast();
    expect($last['status'])->toBe('DIFFERENCES');
    expect($last['discrepancies'])->toHaveCount(1);
    expect($last['discrepancies'][0])->toMatchArray([
        'product_id' => $product, 'location_id' => $loc, 'balance' => '11.000', 'ledger' => '10.000', 'difference' => '1.000',
    ]);
    $alert = DB::table('alerts')->where('type', 'RECONCILIATION_DIFF')->where('product_id', $product)->first();
    expect([$alert->severity, $alert->status])->toBe(['CRITICAL', 'OPEN']);
    expect(DB::table('audit_logs')->where('action', 'ledger.reconciliation_diff')->count())->toBe(1);
    expect(Fixtures::balance($product, $loc))->toBe('11.000'); // el saldo alterado sigue igual: no hay corrección silenciosa

    // Una segunda corrida sigue avisando, pero no duplica la alerta activa.
    $this->artisan('wms:reconcile')->assertExitCode(1);
    expect(DB::table('alerts')->where('type', 'RECONCILIATION_DIFF')->where('product_id', $product)->count())->toBe(1);
});

it('CA-023-3 si la base no responde la tarea registra el fallo, permite reintento y no reporta un cierre correcto', function () {
    reconciliationStock();
    $real = app(PostgresLedgerReconciliation::class);

    // Fallo de la consulta con la base todavía capaz de guardar: el fallo queda registrado.
    app()->instance(LedgerReconciliation::class, new class($real) implements LedgerReconciliation
    {
        public function __construct(private readonly PostgresLedgerReconciliation $real) {}

        public function discrepancies(): array
        {
            throw new PDOException('SQLSTATE[08006] [7] connection to server failed');
        }

        public function openAlerts(array $differences): int
        {
            return $this->real->openAlerts($differences);
        }

        public function recordRun(ReconciliationReport $report): void
        {
            $this->real->recordRun($report);
        }
    });

    $report = app(ReconcileLedger::class)();
    expect($report->status)->toBe(ReconciliationReport::FAILED);
    expect($report->isClean())->toBeFalse();
    $this->artisan('wms:reconcile')->expectsOutputToContain('no se pudo completar')->assertExitCode(1);
    $last = reconciliationLast();
    expect($last['status'])->toBe('FAILED');
    expect((string) $last['error'])->toContain('connection');

    // Caída total (ni siquiera se puede escribir): no se registra ningún cierre correcto.
    app()->instance(LedgerReconciliation::class, new class implements LedgerReconciliation
    {
        public function discrepancies(): array
        {
            throw new PDOException('SQLSTATE[08006] [7] connection to server failed');
        }

        public function openAlerts(array $differences): int
        {
            throw new PDOException('connection to server failed');
        }

        public function recordRun(ReconciliationReport $report): void
        {
            throw new PDOException('connection to server failed');
        }
    });
    expect(app(ReconcileLedger::class)()->status)->toBe(ReconciliationReport::FAILED);
    $this->artisan('wms:reconcile')->assertExitCode(1);
    expect(reconciliationLast()['status'])->not->toBe('OK');

    // La base vuelve: el reintento cierra bien y reemplaza el fallo.
    app()->instance(LedgerReconciliation::class, $real);
    $this->artisan('wms:reconcile')->assertExitCode(0);
    expect(reconciliationLast()['status'])->toBe('OK');
});
