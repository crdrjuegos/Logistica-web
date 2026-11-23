<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Infrastructure;

use App\Modules\Inventory\Domain\EventBus;
use App\Modules\Inventory\Domain\KardexReader;
use App\Modules\Inventory\Domain\LedgerReconciliation;
use App\Modules\Inventory\Domain\StockLedger;
use App\Modules\Inventory\Domain\Transaction;
use Illuminate\Support\ServiceProvider;

final class InventoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(StockLedger::class, PostgresStockLedger::class);
        $this->app->bind(Transaction::class, LaravelTransaction::class);
        $this->app->bind(EventBus::class, LaravelEventBus::class);
        $this->app->bind(KardexReader::class, DatabaseKardexReader::class);
        $this->app->bind(LedgerReconciliation::class, PostgresLedgerReconciliation::class);
    }
}
