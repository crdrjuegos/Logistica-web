<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

final readonly class CatalogImportParse
{
    /**
     * @param  list<CatalogImportRow>  $rows
     * @param  list<CatalogImportError>  $errors
     */
    public function __construct(public array $rows, public int $totalRows, public array $errors = []) {}
}
