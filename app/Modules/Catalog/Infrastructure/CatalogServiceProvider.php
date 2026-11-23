<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure;

use App\Modules\Catalog\Domain\CatalogCodes;
use App\Modules\Catalog\Domain\CatalogImportJobs;
use App\Modules\Catalog\Domain\CatalogRepository;
use App\Modules\Catalog\Domain\SkuSequence;
use Illuminate\Support\ServiceProvider;

final class CatalogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CatalogRepository::class, DatabaseCatalogRepository::class);
        $this->app->bind(SkuSequence::class, DatabaseSkuSequence::class);
        $this->app->bind(CatalogCodes::class, DatabaseCatalogCodes::class);
        $this->app->bind(CatalogImportJobs::class, DatabaseCatalogImportJobs::class);
    }
}
