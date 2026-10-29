<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Schemas;

use App\Filament\Resources\Products\CatalogOptions;
use App\Modules\Catalog\Application\FindSimilarProducts;
use App\Modules\Catalog\Domain\CatalogException;
use App\Modules\Catalog\Domain\SimilarProduct;
use App\Modules\Catalog\Domain\Symbology;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Operation;

final class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        $symbologies = [];
        foreach (Symbology::cases() as $s) {
            $symbologies[$s->value] = $s->value;
        }

        return $schema->components([
            Section::make('Datos del producto')
                ->description('El SKU se genera solo al guardar (por ejemplo LIB-000231) y no se puede cambiar.')
                ->schema([
                    TextInput::make('sku')->label('SKU')->disabled()->dehydrated(false)->visibleOn(Operation::Edit),
                    TextInput::make('name')->label('Nombre')->required()->minLength(2)->maxLength(200)->columnSpanFull()
                        ->live(onBlur: true)
                        ->helperText(static fn (?string $state, string $operation): ?string => $operation === 'create' ? self::duplicateWarning($state) : null),
                    Select::make('category_id')->label('Categoría')->options(fn (): array => CatalogOptions::categories())->searchable()->required(),
                    Select::make('brand_id')->label('Marca')->options(fn (): array => CatalogOptions::brands())->searchable(),
                    Select::make('base_unit_id')->label('Unidad base')->options(fn (): array => CatalogOptions::units())->required()
                        ->disabled(static fn (string $operation): bool => $operation === 'edit')
                        ->dehydrated(static fn (string $operation): bool => $operation === 'create')
                        ->helperText('La unidad en la que se cuenta el stock (por ejemplo, Unidad). No se cambia después.'),
                    TextInput::make('reference_price')->label('Precio referencial')->numeric()->minValue(0)->prefix('S/'),
                    Textarea::make('description')->label('Descripción')->rows(3)->maxLength(2000)->columnSpanFull(),
                    Hidden::make('version'),
                ])->columns(2),

            Section::make('Presentaciones')
                ->description('Además de la unidad base, puede vender o comprar por docena, caja, paquete, etc. El factor indica cuántas unidades base trae.')
                ->schema([
                    Repeater::make('presentations')->hiddenLabel()
                        ->schema([
                            Select::make('unit_id')->label('Unidad')->options(fn (): array => CatalogOptions::units())->required(),
                            TextInput::make('factor')->label('Unidades base que trae')->numeric()->minValue(0.001)->required(),
                            Toggle::make('is_purchase_default')->label('Presentación de compra'),
                        ])->columns(3)->defaultItems(0)->addActionLabel('Agregar presentación'),
                ])->visibleOn(Operation::Create),

            Section::make('Códigos de barras')
                ->description('Cada código es único en todo el catálogo. El código de una caja o docena identifica esa presentación.')
                ->schema([
                    Repeater::make('barcodes')->hiddenLabel()
                        ->schema([
                            Select::make('symbology')->label('Tipo')->options($symbologies)->required(),
                            TextInput::make('barcode')->label('Código')->required()->maxLength(40),
                            Select::make('presentation_unit_id')->label('Presentación')->options(fn (): array => CatalogOptions::units())
                                ->placeholder('Unidad base'),
                            Toggle::make('is_primary')->label('Principal'),
                        ])->columns(4)->defaultItems(0)->addActionLabel('Agregar código'),
                ])->visibleOn(Operation::Create),

            Section::make('Política de stock')
                ->description('Mínimo, máximo y cantidad de reposición por almacén. Con esto el sistema avisará cuando haga falta reponer.')
                ->schema([
                    Repeater::make('policies')->hiddenLabel()
                        ->schema([
                            Select::make('warehouse_id')->label('Almacén')->options(fn (): array => CatalogOptions::warehouses())->required(),
                            TextInput::make('min_qty')->label('Mínimo')->numeric()->minValue(0)->default(0)->required(),
                            TextInput::make('max_qty')->label('Máximo')->numeric()->minValue(0),
                            TextInput::make('reorder_qty')->label('Cantidad a reponer')->numeric()->minValue(0.001),
                        ])->columns(4)->defaultItems(0)->addActionLabel('Agregar política'),
                ])->visibleOn(Operation::Create),
        ]);
    }

    /** HU-013: avisa antes de guardar si el nombre se parece a un producto ya registrado. */
    private static function duplicateWarning(?string $name): ?string
    {
        try {
            $matches = app(FindSimilarProducts::class)((string) $name, 3);
        } catch (CatalogException) {
            return null; // nombre vacío o demasiado corto: aún no hay nada que comparar
        }

        if ($matches === []) {
            return null;
        }

        return 'Posible duplicado: '.implode('; ', array_map(
            static fn (SimilarProduct $p): string => sprintf('%s %s (%d %% parecido)', $p->sku, $p->name, (int) round($p->similarity * 100)),
            $matches,
        )).'. Revise el catálogo antes de guardar.';
    }
}
