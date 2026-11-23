<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Modules\Catalog\Application\ImportCatalog;
use App\Modules\Catalog\Domain\CatalogException;
use App\Modules\Iam\Domain\Permissions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use UnitEnum;

/** Carga masiva de productos desde un CSV (HU-052): primero «Simular», luego «Confirmar importación». */
class CatalogImportPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static string|UnitEnum|null $navigationGroup = 'Catálogo';

    protected static ?int $navigationSort = 9;

    protected static ?string $navigationLabel = 'Importar catálogo';

    protected static ?string $title = 'Importar catálogo';

    protected static ?string $slug = 'importar-catalogo';

    protected string $view = 'filament.pages.catalog-import';

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permissions::CATALOG_IMPORT) ?? false;
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->importAction('simulate', 'Simular', false, 'heroicon-o-eye', 'gray'),
            $this->importAction('commit', 'Confirmar importación', true, 'heroicon-o-check-circle', 'success'),
        ];
    }

    private function importAction(string $name, string $label, bool $commit, string $icon, string $color): Action
    {
        return Action::make($name)->label($label)->icon($icon)->color($color)
            ->visible(static fn (): bool => self::canAccess())
            ->authorize(static fn (): bool => self::canAccess())
            ->modalHeading($commit ? 'Confirmar importación de productos' : 'Simular importación de productos')
            ->modalDescription($commit
                ? 'Se crearán los productos válidos. Las filas con error no se guardan y quedan en un reporte. El mismo archivo no se puede confirmar dos veces.'
                : 'Valida todas las filas SIN crear productos, para revisar los errores antes de confirmar.')
            ->schema([
                FileUpload::make('file')->label('Archivo CSV (columnas: nombre, categoria, unidad; opcionales: ean, precio, costo, sku)')->required()
                    ->disk('local')->directory('imports/tmp')->maxSize(10240)->storeFileNamesIn('original_name'),
            ])
            ->action(static function (array $data) use ($commit) {
                $path = (string) ($data['file'] ?? '');

                try {
                    $csv = Storage::disk('local')->get($path) ?? '';
                    $csv = mb_check_encoding($csv, 'UTF-8') ? $csv : (string) mb_convert_encoding($csv, 'UTF-8', 'ISO-8859-1'); // Excel en Windows

                    $result = app(ImportCatalog::class)($csv, (string) ($data['original_name'] ?? basename($path)), (int) auth()->id(), $commit);

                    Notification::make()
                        ->title(($commit ? 'Importación confirmada' : 'Simulación lista').': '.$result->okRows.' producto(s)')
                        ->body("Filas leídas: {$result->totalRows}. Con error: ".count($result->errors).($result->errors !== [] ? '. Se descargó el reporte de errores.' : '.'))
                        ->color($result->errors === [] ? 'success' : 'warning')->persistent()->send();

                    if ($result->errors !== []) {
                        $report = 'imports/errors/catalog-'.$result->importId.'.csv';
                        Storage::disk('local')->put($report, $result->errorReportCsv());
                        DB::table('import_jobs')->where('id', $result->importId)->update(['error_report_path' => $report]);

                        return Storage::disk('local')->download($report, 'errores-catalogo-'.$result->importId.'.csv');
                    }
                } catch (CatalogException $e) {
                    Notification::make()->title('No se pudo importar')->body($e->getMessage())->danger()->send();
                } finally {
                    if ($path !== '') {
                        Storage::disk('local')->delete($path);
                    }
                }

                return null;
            });
    }
}
