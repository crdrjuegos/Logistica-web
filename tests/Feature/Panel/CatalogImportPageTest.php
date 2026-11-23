<?php

declare(strict_types=1);

use App\Filament\Pages\CatalogImportPage;
use App\Modules\Iam\Domain\RoleName;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\PanelUsers;

beforeEach(function () {
    PanelUsers::seedRoles();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('el supervisor ve la pantalla de importar catálogo con sus dos acciones; los demás roles no entran', function () {
    $this->actingAs(PanelUsers::withRole(RoleName::Supervisor));
    expect(CatalogImportPage::canAccess())->toBeTrue();
    Livewire::test(CatalogImportPage::class)->assertOk()->assertActionVisible('simulate')->assertActionVisible('commit');

    foreach ([RoleName::Almacenero, RoleName::Compras, RoleName::Lectura] as $role) {
        $this->actingAs(PanelUsers::withRole($role));
        expect(CatalogImportPage::canAccess())->toBeFalse();
        Livewire::test(CatalogImportPage::class)->assertForbidden();
    }
});

it('el supervisor simula y luego confirma un CSV desde la pantalla', function () {
    Storage::fake('local');
    $this->actingAs(PanelUsers::withRole(RoleName::Supervisor));
    $before = DB::table('products')->count();
    $csv = "nombre;categoria;unidad;ean\nCuaderno rayado A5;LIB;UND;4006381333931\n";
    $file = UploadedFile::fake()->createWithContent('catalogo.csv', $csv);

    Livewire::test(CatalogImportPage::class)
        ->callAction('simulate', ['file' => $file])
        ->assertNotified();
    expect(DB::table('products')->count())->toBe($before);

    Livewire::test(CatalogImportPage::class)
        ->callAction('commit', ['file' => UploadedFile::fake()->createWithContent('catalogo.csv', $csv)])
        ->assertNotified();
    expect(DB::table('products')->count())->toBe($before + 1);
    expect(DB::table('product_barcodes')->where('barcode', '4006381333931')->exists())->toBeTrue();
});
