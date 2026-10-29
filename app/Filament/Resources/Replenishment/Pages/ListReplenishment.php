<?php

declare(strict_types=1);

namespace App\Filament\Resources\Replenishment\Pages;

use App\Filament\Resources\Replenishment\ReplenishmentResource;
use App\Filament\Support\CsvExport;
use App\Models\ReplenishmentSuggestion;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListReplenishment extends ListRecords
{
    protected static string $resource = ReplenishmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')->label('Descargar pedido sugerido (CSV)')->icon('heroicon-o-arrow-down-tray')
                ->visible(static fn (): bool => CsvExport::allowed())
                ->authorize(static fn (): bool => CsvExport::allowed())
                ->action(fn (): StreamedResponse => $this->exportResponse()),
        ];
    }

    /** Pedido sugerido por proveedor con los mismos filtros que la tabla (solo lo que hay que pedir). */
    public function exportResponse(): StreamedResponse
    {
        CsvExport::authorize();

        /** @var Builder<ReplenishmentSuggestion> $query */
        $query = $this->getFilteredTableQuery() ?? ReplenishmentSuggestion::query();

        return CsvExport::download(
            'pedido-sugerido',
            ['Proveedor', 'SKU', 'Producto', 'Almacén', 'Stock', 'Mínimo', 'Máximo', 'Pedir', 'Plazo (días)'],
            $query->where('suggested_qty', '>', 0)->reorder()
                ->orderByRaw("COALESCE(supplier_name, 'Sin proveedor')")->orderBy('product_name')->cursor()
                ->map(static fn (ReplenishmentSuggestion $r): array => [
                    $r->getAttribute('supplier_name') ?? 'Sin proveedor', $r->getAttribute('sku'), $r->getAttribute('product_name'),
                    $r->getAttribute('warehouse_name'), $r->getAttribute('stock'), $r->getAttribute('min_qty'), $r->getAttribute('max_qty'),
                    $r->getAttribute('suggested_qty'), $r->getAttribute('lead_time_days'),
                ]),
        );
    }
}
