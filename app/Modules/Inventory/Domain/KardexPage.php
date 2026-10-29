<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

final readonly class KardexPage
{
    /**
     * El saldo final corresponde siempre al filtro completo, no solo a las filas de esta página.
     *
     * @param  list<KardexRow>  $rows
     */
    public function __construct(
        public string $openingBalance,
        public array $rows,
        public string $closingBalance,
        public int $totalRows = 0,
        public int $page = 1,
        public int $perPage = 1000,
    ) {}

    public function hasMore(): bool
    {
        return $this->page * $this->perPage < $this->totalRows;
    }

    public function lastPage(): int
    {
        return max(1, (int) ceil($this->totalRows / max(1, $this->perPage)));
    }
}
