<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Cómo preparar el archivo</x-slot>

        <div class="space-y-2 text-sm">
            <p>Suba un archivo CSV (separado por punto y coma o por coma) con una fila por producto y ubicación. La primera fila es el encabezado.</p>
            <p><strong>Columnas obligatorias:</strong> sku (o código de barras del producto), ubicacion (código, por ejemplo BOD-Z1-P01-A01-N1), cantidad (mayor que cero, hasta 3 decimales) y costo (costo unitario sin IGV, hasta 4 decimales).</p>
            <p>Use primero <strong>Simular</strong> para revisar los errores. La carga es <strong>todo o nada</strong>: con <strong>Confirmar carga</strong> se registra el saldo inicial de todas las filas, y si una sola tiene error no se carga ninguna y se descarga un reporte con el número de fila y la causa.</p>
            <p>Un producto solo puede tener un saldo inicial por ubicación, y el mismo archivo no se puede cargar dos veces. Después de la apertura, el stock cambia solo con movimientos (compras, ventas, ajustes y conteos).</p>
        </div>
    </x-filament::section>
</x-filament-panels::page>
