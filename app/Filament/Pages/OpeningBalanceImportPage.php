<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Modules\Iam\Domain\Permissions;
use App\Modules\Operations\Application\ImportOpeningBalances;
use App\Modules\Operations\Domain\OperationsException;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use UnitEnum;

/** Carga masiva de saldos iniciales desde un CSV (HU-053): primero «Simular», luego «Confirmar carga». Todo o nada. */
class OpeningBalanceImportPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-archive-box-arrow-down';

    protected static string|UnitEnum|null $navigationGroup = 'Almacén';

    protected static ?int $navigationSort = 90;

    protected static ?string $navigationLabel = 'Cargar saldos iniciales';

    protected static ?string $title = 'Cargar saldos iniciales';

    protected static ?string $slug = 'saldos-iniciales';

    protected string $view = 'filament.pages.opening-balance-import';

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permissions::INVENTORY_OPENING_BALANCE) ?? false;
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->importAction('simulate', 'Simular', false, 'heroicon-o-eye', 'gray'),
            $this->importAction('commit', 'Confirmar carga', true, 'heroicon-o-check-circle', 'success'),
        ];
    }

    private function importAction(string $name, string $label, bool $commit, string $icon, string $color): Action
    {
        return Action::make($name)->label($label)->icon($icon)->color($color)
            ->visible(static fn (): bool => self::canAccess())
            ->authorize(static fn (): bool => self::canAccess())
            ->modalHeading($commit ? 'Confirmar la carga de saldos iniciales' : 'Simular la carga de saldos iniciales')
            ->modalDescription($commit
                ? 'Se cargará el saldo de TODAS las filas o de ninguna: si una fila tiene error, no se carga nada y se descarga el reporte. El mismo archivo no se puede cargar dos veces.'
                : 'Valida todas las filas SIN registrar saldos, para revisar los errores antes de confirmar.')
            ->schema([
                TextInput::make('reference')->label('Referencia del lote (3 a 40 letras, números, guion o punto)')
                    ->default(static fn (): string => 'APERTURA-'.now('America/Lima')->format('Y-m'))->required()->maxLength(40),
                FileUpload::make('file')->label('Archivo CSV (columnas: sku, ubicacion, cantidad, costo)')->required()
                    ->disk('local')->directory('imports/tmp')->maxSize(10240)->storeFileNamesIn('original_name'),
            ])
            ->action(static function (array $data) use ($commit) {
                $path = (string) ($data['file'] ?? '');

                try {
                    $csv = Storage::disk('local')->get($path) ?? '';
                    $csv = mb_check_encoding($csv, 'UTF-8') ? $csv : (string) mb_convert_encoding($csv, 'UTF-8', 'ISO-8859-1'); // Excel en Windows

                    $result = app(ImportOpeningBalances::class)($csv, (string) ($data['original_name'] ?? basename($path)), (int) auth()->id(), (string) ($data['reference'] ?? ''), $commit);

                    $title = match (true) {
                        $result->committed => 'Saldos iniciales cargados',
                        $commit => 'No se cargó nada: corrija los errores y vuelva a subir el archivo',
                        default => 'Simulación lista',
                    };
                    Notification::make()->title($title)
                        ->body("Filas leídas: {$result->totalRows}. Válidas: {$result->okRows} ({$result->units} unidades). Con error: ".count($result->errors).($result->errors !== [] ? '. Se descargó el reporte de errores.' : '.'))
                        ->color($result->errors === [] ? 'success' : 'warning')->persistent()->send();

                    if ($result->errors !== []) {
                        $report = 'imports/errors/opening-'.$result->importId.'.csv';
                        Storage::disk('local')->put($report, $result->errorReportCsv());
                        DB::table('import_jobs')->where('id', $result->importId)->update(['error_report_path' => $report]);

                        return Storage::disk('local')->download($report, 'errores-saldos-iniciales-'.$result->importId.'.csv');
                    }
                } catch (OperationsException $e) {
                    Notification::make()->title('No se pudo cargar')->body($e->getMessage())->danger()->send();
                } finally {
                    if ($path !== '') {
                        Storage::disk('local')->delete($path);
                    }
                }

                return null;
            });
    }
}
