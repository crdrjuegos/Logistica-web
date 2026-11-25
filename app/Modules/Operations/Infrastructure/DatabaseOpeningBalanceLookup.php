<?php

declare(strict_types=1);

namespace App\Modules\Operations\Infrastructure;

use App\Modules\Operations\Domain\OpeningBalanceLookup;
use Illuminate\Support\Facades\DB;

final class DatabaseOpeningBalanceLookup implements OpeningBalanceLookup
{
    public function productId(string $skuOrBarcode): ?int
    {
        $code = trim($skuOrBarcode);
        if ($code === '') {
            return null;
        }

        $id = DB::table('products')->whereNull('deleted_at')->where('status', 'ACTIVE')
            ->whereRaw('upper(sku) = upper(?)', [$code])->value('id');

        if ($id === null) {
            $id = DB::table('product_barcodes as b')->join('products as p', 'p.id', '=', 'b.product_id')
                ->where('b.barcode', $code)->whereNull('p.deleted_at')->where('p.status', 'ACTIVE')->value('p.id');
        }

        return $id === null ? null : (int) $id;
    }

    public function locationId(string $code): ?int
    {
        $value = trim($code);
        if ($value === '') {
            return null;
        }

        $id = DB::table('locations')->where('is_active', true)->whereRaw('upper(code) = upper(?)', [$value])->value('id');

        return $id === null ? null : (int) $id;
    }

    public function hasOpeningBalance(int $productId, int $locationId): bool
    {
        return DB::table('stock_movements')->where('type', 'SALDO_INICIAL')
            ->where('product_id', $productId)->where('to_location_id', $locationId)->exists();
    }
}
