<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure;

use App\Modules\Catalog\Domain\CatalogCodes;
use Illuminate\Support\Facades\DB;

final class DatabaseCatalogCodes implements CatalogCodes
{
    public function categoryId(string $codeOrName): ?int
    {
        $value = trim($codeOrName);
        if ($value === '') {
            return null;
        }

        $id = DB::table('categories')
            ->where('is_active', true)
            ->where(static function ($query) use ($value): void {
                $query->where(static fn ($root) => $root->whereNull('parent_id')->whereRaw('upper(code) = upper(?)', [$value]))
                    ->orWhereRaw('upper(name) = upper(?)', [$value]);
            })
            ->orderBy('depth')->orderBy('id')
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    public function unitId(string $code): ?int
    {
        $value = trim($code);
        if ($value === '') {
            return null;
        }

        $id = DB::table('units')->whereRaw('upper(code) = upper(?)', [$value])->value('id');

        return $id === null ? null : (int) $id;
    }
}
