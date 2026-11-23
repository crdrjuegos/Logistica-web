<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

/** Resultado de una corrida de reconciliación: sin diferencias, con diferencias o fallida. */
final readonly class ReconciliationReport
{
    public const OK = 'OK';

    public const DIFFERENCES = 'DIFFERENCES';

    public const FAILED = 'FAILED';

    /** @param list<LedgerDiscrepancy> $discrepancies */
    public function __construct(
        public string $status,
        /** Hora de control en formato ISO 8601. */
        public string $ranAt,
        public array $discrepancies = [],
        public ?string $error = null,
    ) {}

    public function isClean(): bool
    {
        return $this->status === self::OK;
    }

    /** @return array{status: string, ran_at: string, discrepancies: list<array<string, int|string>>, error: ?string} */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'ran_at' => $this->ranAt,
            'discrepancies' => array_map(static fn (LedgerDiscrepancy $d): array => $d->toArray(), $this->discrepancies),
            'error' => $this->error,
        ];
    }
}
