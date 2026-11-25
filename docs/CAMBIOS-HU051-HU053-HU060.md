# Cambios: HU-051 (PDF), HU-053 (carga masiva de saldos) y HU-060 (respaldo automático)

Descomprimir en la raíz del proyecto (donde está `docker-compose.yml`). **Reemplaza 6 archivos** (abajo); el resto son archivos nuevos.

## Archivos reemplazados (guarde copia si los modificó)
- `app/Filament/Resources/Reports/ValuedStock/Pages/ListValuedStock.php`
- `app/Filament/Resources/Reports/Abc/Pages/ListAbc.php`
- `app/Filament/Resources/Reports/Waste/Pages/ListWaste.php`
- `app/Filament/Resources/Replenishment/Pages/ListReplenishment.php` (los cuatro: botón «Descargar PDF» y `exportResponse($format = 'csv')`; ~10 líneas cada uno)
- `app/Modules/Operations/Infrastructure/OperationsServiceProvider.php` (un `bind`)
- `tests/Acceptance/StockControlAcceptanceTest.php` (**solo la prueba CA-051-3**, ver abajo)

## HU-051 — Descargar reportes en PDF
- Cada reporte (inventario valorizado, rotación ABC, mermas, pedido sugerido) tiene «Descargar PDF» con **los mismos filtros** que el CSV y el mismo permiso `reports.export`. El CSV sigue igual y es el formato por defecto.
- PDF A4 horizontal, con tildes y ñ, números alineados, encabezado repetido en cada hoja y «Página X de Y». Máximo 5000 filas (indica que use el CSV para el total).
- **Sin librerías nuevas:** el PDF lo escribe `app/Filament/Support/SimplePdf.php` (PDF estándar con Helvetica). No hay que tocar `composer.json` ni la imagen, y no hay licencias que revisar.
- **Decisión a confirmar:** el criterio CA-051-3 decía «el PDF no se anuncia como disponible *mientras no esté implementado*». Como ya lo está, cambié esa prueba: ahora verifica que el PDF se ofrece y que respeta el filtro BOD (no trae filas de otro almacén). Si prefiere dejar CA-051-3 como estaba, quite el botón «exportPdf» de los cuatro reportes.

## HU-053 — Cargar saldos iniciales desde un CSV
- Pantalla nueva: Almacén > Cargar saldos iniciales (permiso `inventory.opening_balance`): «Simular» y «Confirmar carga».
- Columnas: `sku` (o código de barras), `ubicacion`, `cantidad`, `costo`. Separador `;` o `,`; acepta coma decimal.
- **Todo o nada** (igual que `RecordOpeningBalances`): si una fila tiene error no se carga nada y se descarga el reporte con fila y causa. Detecta producto o ubicación inexistentes, cantidad o costo inválidos, producto repetido en la misma ubicación y apertura ya cargada.
- El mismo archivo no se carga dos veces (hash SHA-256). Referencia de lote editable (por defecto `APERTURA-AAAA-MM`).

## HU-060 — Respaldo diario automático
- `docker-compose.backup.yml` agrega el servicio `backup` **sin tocar** `docker-compose.yml`. Guía completa en `docs/respaldo.md`.
- Respaldo diario 03:00 (hora de Lima) con `pg_dump`, verificación de integridad, suma SHA-256, retención de 14 días, copia externa cifrada (AES-256) opcional y registro en `respaldos/ultimo-respaldo.json` sin credenciales.
- `restore-test.sh` restaura en una base aislada, compara los conteos y reconcilia el ledger. `selftest.sh` es la prueba de aceptación de CA-060-1 a 3 (destino inaccesible y copia dañada incluidos).
- Un respaldo fallido nunca queda marcado como correcto.

## Verificar (en CMD, sin mostrar salida; revise los .txt)
```
docker compose run --rm -T tools vendor/bin/pest --colors=never > v1-suite.txt 2>&1 < nul
docker compose run --rm -T tools vendor/bin/pint --test --no-ansi > v2-pint.txt 2>&1 < nul
docker compose run --rm -T tools vendor/bin/phpstan analyse --memory-limit=1G --no-ansi > v3-phpstan.txt 2>&1 < nul
docker compose run --rm -T tools vendor/bin/deptrac analyse --no-ansi > v4-deptrac.txt 2>&1 < nul
docker compose -f docker-compose.yml -f docker-compose.backup.yml run --rm --entrypoint bash backup /scripts/selftest.sh > v5-respaldo.txt 2>&1 < nul
```
