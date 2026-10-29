<?php

declare(strict_types=1);

namespace App\Filament\Resources\Reports\Waste\Pages;

use App\Filament\Resources\Reports\Waste\WasteResource;
use App\Filament\Support\CsvExport;
use App\Models\WasteRow;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListWaste extends ListRecords
{
    protected static string $resource = WasteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')->label('Descargar CSV (con los filtros aplicados)')->icon('heroicon-o-arrow-down-tray')
                ->visible(static fn (): bool => CsvExport::allowed())
                ->authorize(static fn (): bool => CsvExport::allowed())
                ->action(fn (): StreamedResponse => $this->exportResponse()),
        ];
    }

    /** Usa los filtros de la tabla (período, tipo y motivo). */
    public function exportResponse(): StreamedResponse
    {
        CsvExport::authorize();

        /** @var Builder<WasteRow> $query */
        $query = $this->getFilteredTableQuery() ?? WasteRow::query();

        return CsvExport::download(
            'mermas',
            ['Fecha', 'Tipo', 'SKU', 'Producto', 'Ubicación', 'Motivo', 'Cantidad', 'Costo unit.', 'Valor', 'Registró', 'Aprobó', 'Documento', 'Explicación'],
            $query->reorder()->orderBy('created_at')->orderBy('id')->cursor()->map(static fn (WasteRow $r): array => [
                substr((string) $r->getRawOriginal('created_at'), 0, 16), $r->getAttribute('type') === 'MERMA' ? 'Merma' : 'Ajuste (-)',
                $r->getAttribute('sku'), $r->getAttribute('product_name'), $r->getAttribute('location_code'), $r->getAttribute('reason_name'),
                $r->getAttribute('quantity'), $r->getAttribute('unit_cost'), round((float) $r->getAttribute('value'), 2),
                $r->getAttribute('user_name'), $r->getAttribute('approver_name'), $r->getAttribute('document_ref'), $r->getAttribute('notes'),
            ]),
        );
    }
}
