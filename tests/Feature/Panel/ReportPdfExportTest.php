<?php

declare(strict_types=1);

use App\Filament\Resources\Replenishment\Pages\ListReplenishment;
use App\Filament\Resources\Reports\Abc\Pages\ListAbc;
use App\Filament\Resources\Reports\ValuedStock\Pages\ListValuedStock;
use App\Filament\Resources\Reports\Waste\Pages\ListWaste;
use App\Modules\Iam\Domain\RoleName;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Inventory\Domain\UnitCost;
use App\Modules\Operations\Application\RecordOpeningBalances;
use App\Modules\Operations\Domain\OpeningBalanceLine;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\Fixtures;
use Tests\Support\PanelUsers;
use Tests\Support\PdfAssert;

beforeEach(fn () => PanelUsers::seedRoles());

// HU-051 Exportación a PDF (además del CSV)

function pdfReportDownload(StreamedResponse $response): string
{
    ob_start();
    $response->sendContent();

    return (string) ob_get_clean();
}

it('HU-051 el supervisor descarga el inventario valorizado en PDF con las tildes y los datos de la tabla', function () {
    $supervisor = PanelUsers::withRole(RoleName::Supervisor);
    $this->actingAs($supervisor);
    $product = Fixtures::product();
    DB::table('products')->where('id', $product)->update(['name' => 'Cuaderno (Rayado) Niño A4']);
    app(RecordOpeningBalances::class)([new OpeningBalanceLine($product, Fixtures::location('BOD'), Quantity::of('10'), UnitCost::of('2'))], $supervisor->id, 'APERTURA-PDF');

    $response = Livewire::test(ListValuedStock::class)->instance()->exportResponse('pdf');
    $pdf = pdfReportDownload($response);

    expect($response->headers->get('Content-Type'))->toBe('application/pdf');
    expect((string) $response->headers->get('Content-Disposition'))->toContain('inventario-valorizado-')->toContain('.pdf');
    expect(str_starts_with($pdf, "%PDF-1.4\n"))->toBeTrue();
    expect($pdf)->toContain('Inventario valorizado')->toContain("Cuaderno \\(Rayado\\) Ni\xF1o A4")->toContain('20');
    expect(PdfAssert::xrefIsValid($pdf))->toBeTrue();
});

it('HU-051 el CSV sigue siendo el formato por defecto', function () {
    $this->actingAs(PanelUsers::withRole(RoleName::Supervisor));

    $response = Livewire::test(ListValuedStock::class)->instance()->exportResponse();

    expect(str_starts_with(pdfReportDownload($response), "\xEF\xBB\xBF"))->toBeTrue();
});

it('HU-051 el PDF exige reports.export: el rol lectura no ve la acción y el servidor lo rechaza', function () {
    $this->actingAs(PanelUsers::withRole(RoleName::Lectura));

    Livewire::test(ListValuedStock::class)->assertActionHidden('exportPdf');
    expect(fn () => Livewire::test(ListValuedStock::class)->instance()->exportResponse('pdf'))->toThrow(HttpException::class);
});

it('HU-051 los cuatro reportes ofrecen la descarga en PDF al supervisor', function () {
    $this->actingAs(PanelUsers::withRole(RoleName::Supervisor));

    foreach ([ListValuedStock::class, ListAbc::class, ListWaste::class, ListReplenishment::class] as $page) {
        Livewire::test($page)->assertActionVisible('exportPdf');
        expect(str_starts_with(pdfReportDownload(Livewire::test($page)->instance()->exportResponse('pdf')), "%PDF-1.4\n"))->toBeTrue();
    }
});
