<?php

declare(strict_types=1);

namespace App\Filament\Resources\Reports\Abc\Pages;

use App\Filament\Resources\Reports\Abc\AbcResource;
use App\Filament\Support\CsvExport;
use App\Models\AbcRow;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListAbc extends ListRecords
{
    protected static string $resource = AbcResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')->label('Descargar CSV (con los filtros aplicados)')->icon('heroicon-o-arrow-down-tray')
                ->visible(static fn (): bool => CsvExport::allowed())
                ->authorize(static fn (): bool => CsvExport::allowed())
                ->action(fn (): StreamedResponse => $this->exportResponse()),
        ];
    }

    public function exportResponse(): StreamedResponse
    {
        CsvExport::authorize();

        /** @var Builder<AbcRow> $query */
        $query = $this->getFilteredSortedTableQuery() ?? AbcRow::query();

        return CsvExport::download(
            'rotacion-abc',
            ['Clase', 'SKU', 'Producto', 'Categoría', 'Vendido 90 d', 'Valor vendido', '% del total', 'Stock', 'Cobertura (días)'],
            $query->cursor()->map(static fn (AbcRow $r): array => [
                $r->getAttribute('abc_class'), $r->getAttribute('sku'), $r->getAttribute('product_name'), $r->getAttribute('category_name'),
                $r->getAttribute('qty_90d'), round((float) $r->getAttribute('value_90d'), 2), $r->getAttribute('share_pct'),
                $r->getAttribute('stock'), $r->getAttribute('cover_days'),
            ]),
        );
    }
}
