<?php

declare(strict_types=1);

namespace App\Modules\Operations\Infrastructure;

use App\Modules\Operations\Domain\AdjustmentRepository;
use App\Modules\Operations\Domain\CountLocations;
use App\Modules\Operations\Domain\CountRepository;
use App\Modules\Operations\Domain\CountScopeResolver;
use App\Modules\Operations\Domain\DocumentNumbers;
use App\Modules\Operations\Domain\ImportJobs;
use App\Modules\Operations\Domain\OpeningBalanceLookup;
use App\Modules\Operations\Domain\OperationsLookup;
use App\Modules\Operations\Domain\ReceiptRepository;
use App\Modules\Operations\Domain\SalesTargets;
use App\Modules\Operations\Domain\StockMovements;
use App\Modules\Operations\Domain\TransferRepository;
use Illuminate\Support\ServiceProvider;

final class OperationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ReceiptRepository::class, DatabaseReceiptRepository::class);
        $this->app->bind(TransferRepository::class, DatabaseTransferRepository::class);
        $this->app->bind(AdjustmentRepository::class, DatabaseAdjustmentRepository::class);
        $this->app->bind(OperationsLookup::class, DatabaseOperationsLookup::class);
        $this->app->bind(DocumentNumbers::class, DatabaseDocumentNumbers::class);
        $this->app->bind(StockMovements::class, InventoryStockMovements::class);
        $this->app->bind(CountRepository::class, DatabaseCountRepository::class);
        $this->app->bind(CountScopeResolver::class, DatabaseCountScopeResolver::class);
        $this->app->bind(CountLocations::class, LocationsCountLocations::class);
        $this->app->bind(SalesTargets::class, DatabaseSalesTargets::class);
        $this->app->bind(ImportJobs::class, DatabaseImportJobs::class);
        $this->app->bind(OpeningBalanceLookup::class, DatabaseOpeningBalanceLookup::class);
    }
}
