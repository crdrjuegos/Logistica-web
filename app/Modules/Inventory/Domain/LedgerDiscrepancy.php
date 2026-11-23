<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

/** Una diferencia entre el saldo guardado y lo que dice el ledger para un producto en una ubicación. */
final readonly class LedgerDiscrepancy
{
    public function __construct(
        public int $productId,
        public int $locationId,
        /** Saldo guardado (stock_balances), decimal en texto. */
        public string $balance,
        /** Saldo que resulta de sumar los movimientos del ledger. */
        public string $ledger,
        /** balance - ledger. */
        public string $difference,
    ) {}

    /** @return array{product_id: int, location_id: int, balance: string, ledger: string, difference: string} */
    public function toArray(): array
    {
        return [
            'product_id' => $this->productId,
            'location_id' => $this->locationId,
            'balance' => $this->balance,
            'ledger' => $this->ledger,
            'difference' => $this->difference,
        ];
    }
}
