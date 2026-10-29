# Instala y levanta CITY OFERTAS WMS en Windows con Docker Desktop.
# Uso (desde la carpeta del proyecto):
#   powershell -ExecutionPolicy Bypass -File .\scripts\windows\instalar.ps1
# Es seguro ejecutarlo varias veces: no borra datos. Nunca usa "docker compose down -v".

# Continue: Docker escribe su progreso por stderr; los errores se detectan con $LASTEXITCODE.
$ErrorActionPreference = 'Continue'
$root = Resolve-Path (Join-Path $PSScriptRoot '..\..')
Set-Location $root

function Paso([string]$texto) { Write-Host "`n==> $texto" -ForegroundColor Cyan }
function Falla([string]$texto) { Write-Host "`nERROR: $texto" -ForegroundColor Red; exit 1 }
function Ejecutar([string]$descripcion, [scriptblock]$bloque) {
    & $bloque
    if ($LASTEXITCODE -ne 0) { Falla "$descripcion (código $LASTEXITCODE). Revise el mensaje de arriba y vuelva a ejecutar el script." }
}
function Consulta([string]$sql) {
    $r = docker compose exec -T db psql -U wms_owner -d wms -tAc $sql
    if ($LASTEXITCODE -ne 0) { return '' }
    return ($r | Out-String).Trim()
}

Paso 'Verificando Docker'
docker info *> $null
if ($LASTEXITCODE -ne 0) { Falla 'Docker Desktop no está corriendo. Ábralo, espere "Engine running" y vuelva a ejecutar el script.' }
if (-not (Test-Path 'docker-compose.yml')) { Falla "No encuentro docker-compose.yml en $root." }

Paso 'Revisando el archivo .env'
$envNuevo = $false
if (-not (Test-Path '.env')) {
    Copy-Item '.env.example' '.env'
    $envNuevo = $true
    Write-Host 'Se creó .env desde .env.example. Complete WMS_ADMIN_USERNAME, WMS_ADMIN_EMAIL y WMS_ADMIN_PASSWORD (12+ caracteres) y quite el # inicial.' -ForegroundColor Yellow
    notepad .env | Out-Null
    Read-Host 'Guarde el archivo, ciérrelo y presione Enter para continuar'
}
$envTexto = Get-Content '.env' -Raw
if ($envTexto -notmatch '(?m)^WMS_ADMIN_PASSWORD=.{12,}') {
    Write-Host 'Aviso: .env no tiene WMS_ADMIN_PASSWORD activo (12+ caracteres). Si la base es nueva no se creará el administrador; luego podrá usar "php artisan wms:admin-reset".' -ForegroundColor Yellow
}

Paso 'Construyendo imágenes (la primera vez tarda varios minutos y necesita internet)'
Ejecutar 'Falló la construcción de la imagen de la app' { docker compose build }
Ejecutar 'Falló la construcción de la imagen de herramientas' { docker compose --profile tools build tools }

Paso 'Levantando PostgreSQL'
Ejecutar 'No se pudo iniciar la base de datos' { docker compose up -d db }
$listo = $false
for ($i = 0; $i -lt 30; $i++) {
    docker compose exec -T db pg_isready -U wms_owner -d wms *> $null
    if ($LASTEXITCODE -eq 0) { $listo = $true; break }
    Start-Sleep -Seconds 2
}
if (-not $listo) { Falla 'PostgreSQL no respondió en 60 segundos. Revise: docker compose logs db' }

if (-not (Test-Path 'vendor\autoload.php')) {
    Paso 'Instalando librerías PHP (vendor)'
    Ejecutar 'Falló composer install' { docker compose run --rm -T tools composer install --no-interaction }
}

if ($envNuevo -or $envTexto -match '(?m)^APP_KEY=\s*$') {
    Paso 'Generando la clave de la aplicación'
    Ejecutar 'No se pudo generar APP_KEY' { docker compose run --rm -T tools php artisan key:generate --force }
}

$hayTablas = Consulta "select to_regclass('public.users') is not null"
$respaldo = Join-Path $root 'respaldo\wms.dump'
if ($hayTablas -ne 't' -and (Test-Path $respaldo)) {
    Paso 'Restaurando los datos desde respaldo\wms.dump'
    Ejecutar 'No se pudo copiar el respaldo' { docker compose cp $respaldo db:/tmp/wms.dump }
    Ejecutar 'Falló la restauración' { docker compose exec -T db pg_restore -U wms_owner -d wms --no-owner --role=wms_owner /tmp/wms.dump }
    Paso 'Aplicando migraciones pendientes'
    Ejecutar 'Falló la migración' { docker compose run --rm -T tools php artisan migrate --force }
} elseif ($hayTablas -ne 't') {
    Paso 'Creando la base de datos (migraciones y datos iniciales)'
    Ejecutar 'Falló la migración' { docker compose run --rm -T tools php artisan migrate --force }
    Ejecutar 'Falló la carga de roles y administrador' { docker compose run --rm -T tools php artisan db:seed --force }
} else {
    Paso 'La base ya existe: solo se aplican migraciones pendientes'
    Ejecutar 'Falló la migración' { docker compose run --rm -T tools php artisan migrate --force }
}

Paso 'Publicando assets de Filament y limpiando cachés'
Ejecutar 'Falló filament:assets' { docker compose run --rm -T tools php artisan filament:assets }
Ejecutar 'Falló optimize:clear' { docker compose run --rm -T tools php artisan optimize:clear }

Paso 'Levantando todo el sistema'
Ejecutar 'No se pudieron iniciar los servicios' { docker compose up -d }
docker compose ps

Write-Host "`nListo. Abra http://localhost:8080/admin (o https://localhost/admin)." -ForegroundColor Green
Write-Host 'Para apagar: docker compose down   (NUNCA use la opción -v: borra la base de datos)' -ForegroundColor Green
