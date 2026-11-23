<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure;

use App\Modules\Catalog\Domain\CatalogException;
use App\Modules\Catalog\Domain\CatalogImportJobs;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class DatabaseCatalogImportJobs implements CatalogImportJobs
{
    private const KIND = 'PRODUCTS';

    public function committedBefore(string $sha256): bool
    {
        return DB::table('import_jobs')->where('kind', self::KIND)->where('file_sha256', $sha256)
            ->where('mode', 'COMMIT')->where('status', '<>', 'FAILED')->exists();
    }

    public function record(string $fileName, string $sha256, bool $commit, int $totalRows, int $okRows, int $errorRows, int $userId): int
    {
        try {
            return (int) DB::table('import_jobs')->insertGetId([
                'kind' => self::KIND,
                'file_path' => $fileName,
                'file_sha256' => $sha256,
                'mode' => $commit ? 'COMMIT' : 'DRY_RUN',
                'status' => 'DONE',
                'total_rows' => $totalRows,
                'ok_rows' => $okRows,
                'error_rows' => $errorRows,
                'created_by' => $userId,
                'finished_at' => now(),
            ], 'id');
        } catch (UniqueConstraintViolationException) {
            // Dos usuarios subieron el mismo archivo a la vez: la BD deja pasar solo uno.
            throw CatalogException::invalid('Este archivo ya se importó antes.');
        }
    }
}
