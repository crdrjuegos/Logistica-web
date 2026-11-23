# Cambios: HU-023 (reconciliación programada) y HU-052 (importar catálogo)

Descomprimir en la raíz del proyecto (donde está `docker-compose.yml`). **Reemplaza 3 archivos** (ver abajo); el resto son archivos nuevos.

## Archivos reemplazados (guarde copia si los modificó)
- `routes/console.php` (agrega la tarea nocturna `wms:reconcile` a las 02:00 hora de Lima)
- `app/Modules/Inventory/Infrastructure/InventoryServiceProvider.php` (agrega un `bind`)
- `app/Modules/Catalog/Infrastructure/CatalogServiceProvider.php` (agrega dos `bind`)

## HU-023 — Reconciliación programada
- `php artisan wms:reconcile` ejecuta `fn_reconcile_ledger()`.
- Sin diferencias: guarda resultado `OK` y hora de control en `settings` (clave `reconciliation.last_run`).
- Con diferencias: abre alerta CRÍTICA `RECONCILIATION_DIFF` (una activa por producto y almacén), deja auditoría y log; **no corrige saldos**. Sale con código 1.
- Si la base falla: guarda `FAILED` (si puede), sale con código 1 y se puede reintentar. Nunca registra un cierre correcto falso.
- Pruebas: `tests/Acceptance/ReconciliationAcceptanceTest.php` (CA-023-1 a 3).

## HU-052 — Importar catálogo desde CSV
- Pantalla nueva: Catálogo > Importar catálogo (permiso `catalog.import`): «Simular» y «Confirmar importación».
- Columnas: `nombre`, `categoria`, `unidad` (obligatorias); `ean`, `precio`, `costo`, `sku` (opcionales). Separador `;` o `,`.
- Valida fila por fila (categoría y unidad existentes, EAN con dígito verificador, EAN y SKU sin repetir, precio y costo no negativos). Cada error trae número de fila y causa.
- Política elegida (la historia decía «acordada»): las filas válidas se crean y las inválidas van al reporte; el mismo archivo no se confirma dos veces (hash SHA-256). Cámbiela si el negocio acuerda otra.
- El SKU lo genera el sistema; si el CSV trae uno que ya existe, la fila se rechaza. El costo se valida pero no se guarda (entra con el saldo inicial, HU-053).
- Pruebas: `tests/Acceptance/CatalogImportAcceptanceTest.php` (CA-052-1 a 3) y `tests/Feature/Panel/CatalogImportPageTest.php`.

## Verificar
```
docker compose run --rm -T tools vendor/bin/pest tests/Acceptance/ReconciliationAcceptanceTest.php tests/Acceptance/CatalogImportAcceptanceTest.php tests/Feature/Panel/CatalogImportPageTest.php
docker compose run --rm -T tools vendor/bin/pest
docker compose run --rm -T tools vendor/bin/pint --test
docker compose run --rm -T tools vendor/bin/phpstan analyse --memory-limit=1G
docker compose run --rm -T tools vendor/bin/deptrac analyse
```
