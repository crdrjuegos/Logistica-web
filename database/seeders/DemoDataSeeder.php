<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Catalog\Application\CreateProduct;
use App\Modules\Catalog\Domain\Barcode;
use App\Modules\Catalog\Domain\BarcodeInput;
use App\Modules\Catalog\Domain\Factor;
use App\Modules\Catalog\Domain\NewProduct;
use App\Modules\Catalog\Domain\PresentationInput;
use App\Modules\Catalog\Domain\StockPolicy;
use App\Modules\Catalog\Domain\Symbology;
use App\Modules\Iam\Application\CreateUser;
use App\Modules\Iam\Domain\NewUser;
use App\Modules\Iam\Domain\RoleName;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Inventory\Domain\UnitCost;
use App\Modules\Locations\Application\GenerateLocations;
use App\Modules\Locations\Domain\LocationBatch;
use App\Modules\Operations\Application\CompleteTransfer;
use App\Modules\Operations\Application\ConfirmReceipt;
use App\Modules\Operations\Application\CreateReceiptDraft;
use App\Modules\Operations\Application\CreateTransferDraft;
use App\Modules\Operations\Application\ImportPosSales;
use App\Modules\Operations\Application\RecordCount;
use App\Modules\Operations\Application\RecordOpeningBalances;
use App\Modules\Operations\Application\RequestAdjustment;
use App\Modules\Operations\Application\StartCount;
use App\Modules\Operations\Application\SubmitCountForReview;
use App\Modules\Operations\Domain\AdjustmentKind;
use App\Modules\Operations\Domain\CountScopeType;
use App\Modules\Operations\Domain\DocumentType;
use App\Modules\Operations\Domain\NewAdjustment;
use App\Modules\Operations\Domain\NewCount;
use App\Modules\Operations\Domain\NewReceipt;
use App\Modules\Operations\Domain\NewTransfer;
use App\Modules\Operations\Domain\OpeningBalanceLine;
use App\Modules\Operations\Domain\ReceiptLine;
use App\Modules\Operations\Domain\TransferLine;
use App\Modules\Suppliers\Application\CreateSupplier;
use App\Modules\Suppliers\Application\LinkProductToSupplier;
use App\Modules\Suppliers\Domain\NewSupplier;
use App\Modules\Suppliers\Domain\Ruc;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Datos de DEMOSTRACIÓN para presentar el sistema y tomar evidencias. Solo para entornos de prueba.
 *   docker compose run --rm tools php artisan db:seed --class=DemoDataSeeder --force
 * Usuarios de demostración (clave común: DEMO_PASSWORD): supervisor.demo, almacenero.demo, compras.demo, lectura.demo.
 * No se ejecuta dos veces: si ya existe el producto demo, no hace nada.
 */
final class DemoDataSeeder extends Seeder
{
    public const DEMO_PASSWORD = 'caballo-azul-lluvia-77';

    public function run(): void
    {
        if (DB::table('users')->where('username', 'supervisor.demo')->exists()) {
            $this->command?->info('Los datos de demostración ya existen.');

            return;
        }
        (new RolesAndPermissionsSeeder)->run();
        DB::transaction(fn () => $this->seedDemo()); // todo o nada
        $this->command?->info('Datos de demostración creados. Usuarios *.demo con la clave '.self::DEMO_PASSWORD);
    }

    private function seedDemo(): void
    {

        $users = [];
        foreach ([
            ['Sofía Supervisora', 'supervisor.demo', RoleName::Supervisor],
            ['Álvaro Almacenero', 'almacenero.demo', RoleName::Almacenero],
            ['Carla Compras', 'compras.demo', RoleName::Compras],
            ['Luis Lectura', 'lectura.demo', RoleName::Lectura],
        ] as [$name, $username, $role]) {
            $users[$username] = app(CreateUser::class)(new NewUser($name, $username, "{$username}@cityofertas.test", self::DEMO_PASSWORD, $role));
        }
        $sup = $users['supervisor.demo'];
        $alm = $users['almacenero.demo'];

        $bod = $this->warehouse('BOD');
        $pis = $this->warehouse('PIS');
        app(GenerateLocations::class)(new LocationBatch($bod, 1, 1, 2, 1, 2, 1, 2, $sup));
        app(GenerateLocations::class)(new LocationBatch($pis, 1, 1, 1, 1, 2, 1, 1, $sup));
        $loc = fn (string $code): int => (int) DB::table('locations')->where('code', $code)->value('id');

        $brand = (int) DB::table('brands')->insertGetId(['name' => 'Standford', 'created_at' => now(), 'updated_at' => now()], 'id');
        $und = $this->unit('UND');
        $doc = $this->unit('DOC');
        $lib = $this->category('LIB');
        $jug = $this->category('JUG');
        $product = fn (string $name, int $cat, string $ean, array $policy, array $presentations = []): int => app(CreateProduct::class)(new NewProduct(
            $name, $cat, $und, $sup, null, $brand, null, $presentations,
            [new BarcodeInput(Barcode::of($ean, Symbology::Ean13))],
            [new StockPolicy($bod, ...$policy)],
        ))['id'];

        $cuaderno = $product('Cuaderno A4 100 hojas', $lib, '4006381333931', [10, 100, 50],
            [new PresentationInput($doc, Factor::of(12))]);
        $lapicero = $product('Lapicero azul punta fina', $lib, '7501031311309', [20, 200, 100]);
        $pelota = $product('Pelota de fútbol número 5', $jug, '7702004003508', [5, 30, 10]);
        $plumon = $product('Plumón indeleble negro', $lib, '7750082001237', [10, 40, 20]);
        DB::table('product_barcodes')->insert(['product_id' => $cuaderno, 'barcode' => 'DOCENA-0001', 'symbology' => 'INTERNAL', 'is_primary' => false,
            'presentation_id' => DB::table('product_units')->where('product_id', $cuaderno)->where('unit_id', $doc)->value('id')]);

        $supplier = app(CreateSupplier::class)(new NewSupplier(Ruc::of('20611519886'), 'Importadora Lima SAC', 'Rosa Quispe', '987654321', 'ventas@importadoralima.pe'));
        foreach ([$cuaderno, $lapicero, $pelota] as $p) {
            app(LinkProductToSupplier::class)($p, $supplier, null, null, true, $sup);
        }

        // Saldo inicial y recepción confirmada.
        $admin = (int) (DB::table('users')->where('username', 'admin')->value('id') ?? $sup);
        app(RecordOpeningBalances::class)([
            new OpeningBalanceLine($plumon, $loc('BOD-Z1-P01-A01-N1'), Quantity::of('6'), UnitCost::of('3.5')),
        ], $admin, 'APERTURA-DEMO');
        $receipt = app(CreateReceiptDraft::class)(new NewReceipt($supplier, $bod, DocumentType::Invoice, 'F001-234', now('America/Lima')->format('Y-m-d'), $alm, [
            new ReceiptLine($cuaderno, $loc('BOD-Z1-P01-A01-N1'), Quantity::of('120'), UnitCost::of('2.5')),
            new ReceiptLine($lapicero, $loc('BOD-Z1-P01-A02-N1'), Quantity::of('15'), UnitCost::of('0.8')),
            new ReceiptLine($pelota, $loc('BOD-Z1-P02-A01-N1'), Quantity::of('40'), UnitCost::of('18')),
        ]));
        app(ConfirmReceipt::class)($receipt['id'], $alm);
        app(CreateReceiptDraft::class)(new NewReceipt($supplier, $bod, DocumentType::Invoice, 'F001-240', now('America/Lima')->format('Y-m-d'), $alm, [
            new ReceiptLine($pelota, $loc('BOD-Z1-P02-A01-N1'), Quantity::of('10'), UnitCost::of('18')),
        ]));

        // Transferencia al piso de venta y ventas del POS.
        $floor = $loc('PIS-Z1-P01-A01-N1');
        foreach ([$cuaderno, $pelota] as $p) {
            DB::table('product_locations')->insert(['product_id' => $p, 'location_id' => $floor, 'is_primary' => true]);
        }
        $transfer = app(CreateTransferDraft::class)(new NewTransfer($alm, [
            new TransferLine($cuaderno, $loc('BOD-Z1-P01-A01-N1'), $floor, Quantity::of('30')),
            new TransferLine($pelota, $loc('BOD-Z1-P02-A01-N1'), $floor, Quantity::of('12')),
        ]));
        app(CompleteTransfer::class)($transfer['id'], $alm);
        $skuCuaderno = (string) DB::table('products')->where('id', $cuaderno)->value('sku');
        $skuPelota = (string) DB::table('products')->where('id', $pelota)->value('sku');
        app(ImportPosSales::class)("sku,cantidad\n{$skuCuaderno},4\n{$skuPelota},2\nNO-EXISTE,1\n{$skuCuaderno},abc\n", 'ventas-demo.csv', now('America/Lima')->format('Y-m-d'), $sup, true);

        // Merma pendiente de aprobación y conteo en revisión.
        app(RequestAdjustment::class)(new NewAdjustment(AdjustmentKind::WriteOff, $pelota, $loc('BOD-Z1-P02-A01-N1'), Quantity::of('2'),
            (int) DB::table('movement_reasons')->where('code', 'MERMA_DANIO')->value('id'), 'Se mojaron en bodega por una gotera', $alm));
        $count = app(StartCount::class)(new NewCount($bod, CountScopeType::Location, 'BOD-Z1-P01-A02-N1', $sup));
        app(RecordCount::class)($count['id'], $lapicero, $loc('BOD-Z1-P01-A02-N1'), Quantity::of('14'), $alm);
        app(SubmitCountForReview::class)($count['id'], $alm);
    }

    private function warehouse(string $code): int
    {
        return (int) DB::table('warehouses')->where('code', $code)->value('id');
    }

    private function unit(string $code): int
    {
        return (int) DB::table('units')->where('code', $code)->value('id');
    }

    private function category(string $code): int
    {
        return (int) DB::table('categories')->whereNull('parent_id')->where('code', $code)->value('id');
    }
}
