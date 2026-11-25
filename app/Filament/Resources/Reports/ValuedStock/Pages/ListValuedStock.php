<?php

declare(strict_types=1);

namespace App\Filament\Resources\Reports\ValuedStock\Pages;

use App\Filament\Resources\Reports\ValuedStock\ValuedStockResource;
use App\Filament\Support\CsvExport;
use App\Filament\Support\ReportExport;
use App\Models\ValuedStockRow;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListValuedStock extends ListRecords
{
    protected static string $resource = ValuedStockResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')->label('Descargar CSV (con los filtros aplicados)')->icon('heroicon-o-arrow-down-tray')
                ->visible(static fn (): bool => CsvExport::allowed())
                ->authorize(static fn (): bool => CsvExport::allowed())
                ->action(fn (): StreamedResponse => $this->exportResponse()),
            Action::make('exportPdf')->label('Descargar PDF (con los filtros aplicados)')->icon('heroicon-o-document-arrow-down')
                ->visible(static fn (): bool => CsvExport::allowed())
                ->authorize(static fn (): bool => CsvExport::allowed())
                ->action(fn (): StreamedResponse => $this->exportResponse('pdf')),
        ];
    }

    public function exportResponse(string $format = 'csv'): StreamedResponse
    {
        CsvExport::authorize();

        /** @var Builder<ValuedStockRow> $query */
        $query = $this->getFilteredSortedTableQuery() ?? ValuedStockRow::query();

        return ReportExport::download(
            $format,
            'inventario-valorizado',
            ['SKU', 'Producto', 'Categoría', 'Almacén', 'Unidades', 'Costo promedio', 'Valor'],
            $query->cursor()->map(static fn (ValuedStockRow $r): array => [
                $r->getAttribute('sku'), $r->getAttribute('product_name'), $r->getAttribute('category_name'), $r->getAttribute('warehouse_name'),
                $r->getAttribute('qty'), $r->getAttribute('cost_avg'), round((float) $r->getAttribute('value'), 2),
            ]),
        );
    }
}
