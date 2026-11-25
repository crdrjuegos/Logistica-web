<?php

declare(strict_types=1);

use App\Filament\Pages\OpeningBalanceImportPage;
use App\Modules\Iam\Domain\RoleName;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\Fixtures;
use Tests\Support\PanelUsers;

beforeEach(function () {
    PanelUsers::seedRoles();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('el administrador ve la pantalla de saldos iniciales con sus dos acciones; el supervisor no entra', function () {
    $this->actingAs(PanelUsers::withRole(RoleName::Admin));
    Livewire::test(OpeningBalanceImportPage::class)->assertOk()->assertActionVisible('simulate')->assertActionVisible('commit');

    $this->actingAs(PanelUsers::withRole(RoleName::Supervisor));
    Livewire::test(OpeningBalanceImportPage::class)->assertForbidden();
});

it('el administrador simula y confirma la carga desde la pantalla', function () {
    Storage::fake('local');
    $this->actingAs(PanelUsers::withRole(RoleName::Admin));
    $product = Fixtures::product();
    $location = Fixtures::location('BOD');
    $csv = "sku;ubicacion;cantidad;costo\n".DB::table('products')->where('id', $product)->value('sku').';'.DB::table('locations')->where('id', $location)->value('code').";7;2\n";

    Livewire::test(OpeningBalanceImportPage::class)
        ->callAction('simulate', ['reference' => 'APERTURA-UI', 'file' => UploadedFile::fake()->createWithContent('apertura.csv', $csv)])
        ->assertNotified();
    expect(DB::table('stock_movements')->where('type', 'SALDO_INICIAL')->count())->toBe(0);

    Livewire::test(OpeningBalanceImportPage::class)
        ->callAction('commit', ['reference' => 'APERTURA-UI', 'file' => UploadedFile::fake()->createWithContent('apertura.csv', $csv)])
        ->assertNotified();
    expect(Fixtures::balance($product, $location))->toBe('7.000');
});
