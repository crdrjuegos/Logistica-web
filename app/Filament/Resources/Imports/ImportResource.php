<?php

declare(strict_types=1);

namespace App\Filament\Resources\Imports;

use App\Filament\Resources\Imports\Pages\ListImports;
use App\Models\ImportJob;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use UnitEnum;

class ImportResource extends Resource
{
    protected static ?string $model = ImportJob::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static string|UnitEnum|null $navigationGroup = 'Almacén';

    protected static ?int $navigationSort = 6;

    protected static ?string $navigationLabel = 'Ventas del POS';

    protected static ?string $modelLabel = 'importación';

    protected static ?string $pluralModelLabel = 'importaciones de ventas';

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Fecha')->dateTime('d/m/Y H:i', 'America/Lima')->sortable(),
                TextColumn::make('file_path')->label('Archivo')->searchable(),
                TextColumn::make('mode')->label('Tipo')->badge()
                    ->formatStateUsing(static fn (string $state): string => $state === 'COMMIT' ? 'Confirmada' : 'Simulación')
                    ->color(static fn (string $state): string => $state === 'COMMIT' ? 'success' : 'gray'),
                TextColumn::make('total_rows')->label('Filas')->alignEnd(),
                TextColumn::make('ok_rows')->label('Productos aplicados')->alignEnd(),
                TextColumn::make('error_rows')->label('Errores')->alignEnd()->badge()
                    ->color(static fn ($state): string => (int) $state > 0 ? 'danger' : 'success'),
                TextColumn::make('creator.name')->label('Subió'),
            ])
            ->recordActions([
                Action::make('errors')->label('Descargar errores')->icon('heroicon-o-arrow-down-tray')->color('danger')
                    ->visible(static fn (ImportJob $record): bool => self::canDownloadErrors($record))
                    ->authorize(static fn (ImportJob $record): bool => self::canDownloadErrors($record))
                    ->action(static fn (ImportJob $record) => Storage::disk('local')->download((string) $record->error_report_path, 'errores-ventas-'.$record->id.'.csv')),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Aún no se han importado ventas')
            ->emptyStateDescription('Suba el CSV de ventas del día que exporta la caja (columnas sku y cantidad). Primero "Simular", luego "Confirmar".');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('import_jobs.kind', 'POS_SALES')->with('creator');
    }

    public static function getPages(): array
    {
        return ['index' => ListImports::route('/')];
    }

    /** Solo con pos.import y solo reportes generados por el sistema (sin rutas arbitrarias). CA-038-3. */
    public static function canDownloadErrors(ImportJob $record): bool
    {
        $path = (string) $record->error_report_path;

        return (auth()->user()?->can('pos.import') ?? false)
            && preg_match('#^imports/errors/pos-\d+\.csv$#', $path) === 1
            && Storage::disk('local')->exists($path);
    }
}
