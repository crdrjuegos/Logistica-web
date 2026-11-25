<?php

declare(strict_types=1);

namespace App\Modules\Operations\Domain;

final readonly class OpeningBalanceParse
{
    /** @param list<OpeningBalanceRow> $rows */
    public function __construct(public array $rows) {}
}
