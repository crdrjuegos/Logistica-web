<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

interface CatalogImportJobs
{
    /** ¿Ya se confirmó un archivo con este mismo contenido? */
    public function committedBefore(string $sha256): bool;

    public function record(string $fileName, string $sha256, bool $commit, int $totalRows, int $okRows, int $errorRows, int $userId): int;
}
