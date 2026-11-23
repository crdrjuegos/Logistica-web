<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Cómo preparar el archivo</x-slot>

        <div class="space-y-2 text-sm">
            <p>Suba un archivo CSV (separado por punto y coma o por coma) con una fila por producto. La primera fila es el encabezado.</p>
            <p><strong>Columnas obligatorias:</strong> nombre, categoria (código como LIB o el nombre de la categoría) y unidad (código como UND).</p>
            <p><strong>Columnas opcionales:</strong> ean (8, 12 o 13 dígitos con dígito verificador válido), precio (referencial, máximo 2 decimales), costo (se valida, pero se registra con el saldo inicial) y sku (el sistema genera el SKU; si indica uno que ya existe, la fila se rechaza).</p>
            <p>Use primero <strong>Simular</strong> para revisar los errores. Con <strong>Confirmar importación</strong> se crean los productos válidos; las filas con error quedan en un reporte con su número de fila y la causa. El mismo archivo no se puede confirmar dos veces: si faltó alguna fila, súbala en un archivo nuevo solo con esas filas.</p>
        </div>
    </x-filament::section>
</x-filament-panels::page>
