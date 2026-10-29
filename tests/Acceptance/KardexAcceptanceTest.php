<?php

declare(strict_types=1);

use App\Modules\Inventory\Application\GetKardex;
use App\Modules\Inventory\Application\RegisterMovement;
use App\Modules\Inventory\Domain\KardexQuery;
use App\Modules\Inventory\Domain\KardexRow;
use Illuminate\Support\Facades\DB;
use Tests\Support\Fixtures;
use Tests\Support\MovementFactory as M;

// HU-022 Kardex por producto y ubicación

it('CA-022-1 compra 20 y transferencia de 5: saldo inicial, orden determinista, saldo corrido y final 15 en origen', function () {
    $user = Fixtures::user();
    $product = Fixtures::product();
    $bod = Fixtures::location('BOD');
    $pis = Fixtures::location('PIS');
    $register = app(RegisterMovement::class);
    $purchase = $register(M::purchase($product, $bod, 20, '2', $user));
    $transfer = $register(M::transfer($product, $bod, $pis, 5, $user));

    $page = app(GetKardex::class)(new KardexQuery($product, locationId: $bod));

    expect($page->openingBalance)->toBe('0.000');
    expect(array_map(static fn (KardexRow $r): int => $r->movementId, $page->rows))->toBe([$purchase->movementId, $transfer->movementId]);
    expect(array_map(static fn (KardexRow $r): string => $r->delta, $page->rows))->toBe(['20.000', '-5.000']);
    expect(array_map(static fn (KardexRow $r): string => $r->runningBalance, $page->rows))->toBe(['20.000', '15.000']);
    expect($page->closingBalance)->toBe('15.000');
    expect($page->closingBalance)->toBe(Fixtures::balance($product, $bod));
});

it('CA-022-2 producto válido sin movimientos en el período: lista vacía y saldos inicial y final coherentes', function () {
    $user = Fixtures::user();
    $product = Fixtures::product();
    $bod = Fixtures::location('BOD');
    app(RegisterMovement::class)(M::purchase($product, $bod, 8, '1', $user));

    $future = new DateTimeImmutable('+1 day');
    $page = app(GetKardex::class)(new KardexQuery($product, locationId: $bod, from: $future, to: $future->modify('+1 day')));

    expect($page->rows)->toBe([]);
    expect($page->openingBalance)->toBe('8.000');
    expect($page->closingBalance)->toBe('8.000');
    expect($page->totalRows)->toBe(0);
});

it('CA-022-3 con más de 500 movimientos y filtro por fechas y ubicación, las páginas no pierden ni duplican y el saldo final es del filtro completo', function () {
    $user = Fixtures::user();
    $product = Fixtures::product();
    $bod = Fixtures::location('BOD');
    $other = Fixtures::location('BOD');
    $register = app(RegisterMovement::class);
    $from = new DateTimeImmutable('-1 minute');
    for ($i = 0; $i < 260; $i++) {
        $register(M::purchase($product, $bod, 2, '1', $user));
        $register(M::sale($product, $bod, 1, $user));
    }
    $register(M::purchase($product, $other, 50, '1', $user)); // otra ubicación: fuera del filtro
    $to = new DateTimeImmutable('+1 minute');

    $seen = [];
    $pageNumber = 1;
    do {
        $page = app(GetKardex::class)(new KardexQuery($product, locationId: $bod, from: $from, to: $to, limit: 500, page: $pageNumber));
        foreach ($page->rows as $row) {
            $seen[] = $row->movementId;
        }
        $last = $page;
        $pageNumber++;
    } while ($page->hasMore());

    expect($pageNumber - 1)->toBe(2);
    expect($last->totalRows)->toBe(520);
    expect(count($seen))->toBe(520);
    expect(count(array_unique($seen)))->toBe(520);
    expect($seen)->toBe(DB::table('stock_movements')->where('product_id', $product)
        ->where(static fn ($q) => $q->where('to_location_id', $bod)->orWhere('from_location_id', $bod))
        ->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all());
    expect($last->closingBalance)->toBe('260.000');
    expect($last->rows[count($last->rows) - 1]->runningBalance)->toBe('260.000');
});
