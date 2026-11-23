<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

/** Resuelve los códigos que el usuario escribe en el CSV (categoría y unidad) a sus ids. */
interface CatalogCodes
{
    /** Categoría activa por código raíz (LIB, JUG...) o por nombre. */
    public function categoryId(string $codeOrName): ?int;

    public function unitId(string $code): ?int;
}
