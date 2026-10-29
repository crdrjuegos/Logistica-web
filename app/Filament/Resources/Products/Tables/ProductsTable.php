<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Tables;

use App\Models\Product;
use App\Modules\Catalog\Application\DeactivateProduct;
use App\Modules\Catalog\Application\LookupProduct;
use App\Modules\Catalog\Domain\CatalogException;
use App\Modules\Inventory\Application\GetKardex;
use App\Modules\Inventory\Domain\KardexPage;
use App\Modules\Inventory\Domain\KardexQuery;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class ProductsTable
{
    public const KARDEX_PAGE = 500;

    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sku')->label('SKU')->sortable()->copyable()
                    ->searchable(query: static fn (Builder $query, string $search): Builder => $query
                        ->orWhere('products.sku', 'ilike', "%{$search}%")
                        // también se encuentra escaneando/digitando el código de barras exacto
                        ->orWhereExists(static fn ($q) => $q->select(DB::raw(1))->from('product_barcodes')
                            ->whereColumn('product_barcodes.product_id', 'products.id')->where('product_barcodes.barcode', $search))),
                TextColumn::make('name')->label('Nombre')->searchable()->sortable()->wrap(),
                TextColumn::make('category.name')->label('Categoría')->sortable(),
                TextColumn::make('brand.name')->label('Marca')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('primary_barcode')->label('Código principal')->toggleable(),
                TextColumn::make('stock_total')->label('Stock total')->sortable()->badge()->alignEnd()
                    ->color(static fn ($state): string => (float) $state <= 0 ? 'danger' : 'success')
                    ->formatStateUsing(static fn ($state): string => rtrim(rtrim(number_format((float) $state, 3, '.', ','), '0'), '.') ?: '0'),
                TextColumn::make('cost_avg')->label('Costo promedio')->alignEnd()->toggleable()
                    ->formatStateUsing(static fn ($state): string => 'S/ '.number_format((float) $state, 2, '.', ',')),
                TextColumn::make('status')->label('Estado')->badge()
                    ->formatStateUsing(static fn (string $state): string => match ($state) {
                        'ACTIVE' => 'Activo', 'INACTIVE' => 'De baja', default => 'Descontinuado',
                    })
                    ->color(static fn (string $state): string => match ($state) {
                        'ACTIVE' => 'success', 'INACTIVE' => 'gray', default => 'warning',
                    }),
                TextColumn::make('updated_at')->label('Modificado')->dateTime('d/m/Y H:i', 'America/Lima')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('category_id')->label('Categoría')->relationship('category', 'name'),
                SelectFilter::make('status')->label('Estado')->options(['ACTIVE' => 'Activo', 'INACTIVE' => 'De baja', 'DISCONTINUED' => 'Descontinuado']),
                Filter::make('out_of_stock')->label('Sin stock')
                    ->query(static fn (Builder $query): Builder => $query->whereRaw(
                        '(SELECT COALESCE(SUM(sb.quantity), 0) FROM stock_balances sb WHERE sb.product_id = products.id) <= 0',
                    )),
            ])
            ->recordActions([
                Action::make('kardex')->label('Kardex')->icon('heroicon-o-clipboard-document-list')->color('gray')
                    ->visible(static fn (): bool => auth()->user()?->can('inventory.view') ?? false)
                    ->modalHeading(static fn (Product $record): string => 'Kardex · '.$record->sku)
                    ->modalWidth('7xl')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Cerrar')
                    ->modalContent(static fn (Product $record): View => view('filament.kardex', [
                        'lookup' => app(LookupProduct::class)((string) $record->sku),
                        'kardex' => self::latestKardexPage((int) $record->id),
                        'product' => $record,
                    ])),
                EditAction::make('edit')->label('Editar'),
                Action::make('deactivate')->label('Dar de baja')->icon('heroicon-o-archive-box-x-mark')->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Dar de baja el producto')
                    ->modalDescription('El producto dejará de poder operarse. Solo es posible si su stock es cero. El historial se conserva.')
                    ->visible(static fn (Product $record): bool => $record->status === 'ACTIVE' && (auth()->user()?->can('catalog.write') ?? false))
                    ->action(static function (Product $record): void {
                        try {
                            app(DeactivateProduct::class)($record->id, auth()->id() === null ? null : (int) auth()->id());
                            Notification::make()->title('Producto dado de baja')->success()->send();
                        } catch (CatalogException $e) {
                            Notification::make()->title('No se pudo dar de baja')->body($e->getMessage())->danger()->send();
                        }
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Aún no hay productos')
            ->emptyStateDescription('Cree el primero con el botón "Nuevo producto".');
    }

    /** Los 500 movimientos más recientes; la vista avisa si hay más (KardexPage::totalRows). */
    public static function latestKardexPage(int $productId): KardexPage
    {
        $first = app(GetKardex::class)(new KardexQuery($productId, limit: self::KARDEX_PAGE));

        return $first->hasMore()
            ? app(GetKardex::class)(new KardexQuery($productId, limit: self::KARDEX_PAGE, page: $first->lastPage()))
            : $first;
    }
}
