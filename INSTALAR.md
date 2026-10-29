# Instalar y ejecutar CITY OFERTAS WMS en otra PC (Windows)

Guía para llevar el proyecto en un USB y levantarlo en una PC nueva que solo tiene Docker.
No necesitas instalar PHP, Composer, Node ni PostgreSQL: todo corre dentro de Docker.

> Todos los comandos se escriben en **PowerShell**, dentro de la carpeta del proyecto
> (la que tiene `docker-compose.yml`).

---

## 0. Antes de salir de la PC actual (2 minutos)

### 0.1 Guardar los datos de la base (si quieres llevarlos)

Los datos de PostgreSQL **no están en la carpeta del proyecto**: viven en un volumen de Docker.
Si copias solo la carpeta, en la PC nueva tendrás una base vacía (con el administrador creado por la semilla).
Para llevar tus datos, genera un respaldo dentro de la carpeta:

```powershell
mkdir respaldo -Force
docker compose exec -T db pg_dump -U wms_owner -d wms -Fc -f /tmp/wms.dump
docker compose cp db:/tmp/wms.dump ./respaldo/wms.dump
```

Queda el archivo `respaldo\wms.dump`. (Probado el 29/10/2026: se restauró completo, con 53 tablas, 14 migraciones, roles y usuarios.)

### 0.2 Qué copiar al USB

Copia **toda la carpeta** `city-ofertas-wms`, incluidas:

- `.env` (configuración y clave de la app; está oculto en el Explorador: activa "Elementos ocultos").
- `vendor\` (librerías PHP ya descargadas, incluido Filament).
- `respaldo\wms.dump` si hiciste el paso 0.1.

> **Importante (seguridad):** el archivo `.git\config` de esta PC guarda un token de GitHub dentro de la
> dirección del repositorio. **No copies la carpeta `.git`** al USB, o antes revoca ese token en
> GitHub → Settings → Developer settings → Personal access tokens y configura el remoto sin token.

---

## 1. Preparar la PC nueva (una sola vez)

1. Instala **Docker Desktop** para Windows: <https://www.docker.com/products/docker-desktop/>
   (gratuito para uso personal y educativo). Acepta activar **WSL 2** si lo pide y reinicia la PC.
2. Abre Docker Desktop y espera a que diga **"Engine running"** (ballena verde).
3. Copia la carpeta del USB a un disco local, por ejemplo `C:\wms\city-ofertas-wms`.
   No lo ejecutes directamente desde el USB: es mucho más lento y puede fallar.
4. Abre PowerShell y entra a la carpeta:

```powershell
cd C:\wms\city-ofertas-wms
docker --version
docker compose version
```

Si ambos comandos responden, sigue.

---

## 2. Instalación rápida con el script (recomendado)

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\windows\instalar.ps1
```

El script hace, en orden, los pasos de la sección 3 y se detiene con un mensaje claro si algo falla.
Si prefieres hacerlo a mano, sigue la sección 3.

---

## 3. Instalación paso a paso

### 3.1 Revisar el archivo `.env`

Si copiaste el `.env`, no hagas nada. Si no existe:

```powershell
Copy-Item .env.example .env
notepad .env
```

En el Bloc de notas quita el `#` de estas tres líneas y pon tus datos (la contraseña con 12 o más caracteres):

```
WMS_ADMIN_USERNAME=admin
WMS_ADMIN_EMAIL=admin@cityofertas.pe
WMS_ADMIN_PASSWORD=una-clave-larga-y-segura
```

Luego genera la clave de la aplicación (solo si `.env` es nuevo):

```powershell
docker compose run --rm tools php artisan key:generate
```

### 3.2 Construir las imágenes (la primera vez tarda 10–20 min y necesita internet)

```powershell
docker compose build
docker compose --profile tools build tools
```

### 3.3 Levantar la base de datos

```powershell
docker compose up -d db
docker compose ps
```

Espera a que `db` diga `healthy` (unos 20 segundos).

### 3.4 Librerías PHP (solo si NO copiaste la carpeta `vendor`)

```powershell
docker compose run --rm tools composer install
```

Filament y Livewire están declarados en `composer.json` y `composer.lock`, así que se instalan solos.

### 3.5 Crear o restaurar la base de datos

**Opción A — Base nueva y vacía:**

```powershell
docker compose run --rm tools php artisan migrate --force
docker compose run --rm tools php artisan db:seed --force
```

`db:seed` crea los 5 roles con sus permisos y el administrador definido en `.env`.

**Opción B — Restaurar los datos que trajiste (`respaldo\wms.dump`):**

```powershell
docker compose cp ./respaldo/wms.dump db:/tmp/wms.dump
docker compose exec -T db pg_restore -U wms_owner -d wms --no-owner --role=wms_owner /tmp/wms.dump
docker compose run --rm tools php artisan migrate --force
```

El último `migrate` solo aplica las migraciones que el respaldo no tuviera. **No ejecutes `db:seed`
después de restaurar** (los roles y el administrador ya vienen en el respaldo).

### 3.6 Levantar todo el sistema

```powershell
docker compose up -d
docker compose ps
```

Deben aparecer `app`, `worker`, `scheduler`, `db` y `caddy` en estado `running` / `healthy`.

### 3.7 Entrar

- Panel: <http://localhost:8080/admin>
- Con HTTPS local (Caddy): <https://localhost/admin> — la primera vez el navegador avisará que el
  certificado es local; acepta para continuar.

Ingresa con el correo y la contraseña del administrador (los de `.env`, o los de tu respaldo).

---

## 4. Uso diario

| Quiero… | Comando |
|---|---|
| Encender el sistema | `docker compose up -d` |
| Apagarlo (los datos se conservan) | `docker compose down` |
| Ver si está todo arriba | `docker compose ps` |
| Ver errores de la app | `docker compose logs app --tail 100` |
| Aplicar migraciones nuevas | `docker compose run --rm tools php artisan migrate --force` |
| Olvidé la clave del admin | `docker compose run --rm tools php artisan wms:admin-reset` |
| Listar usuarios | `docker compose run --rm tools php artisan wms:users` |
| Hacer un respaldo | ver sección 0.1 |

> **Nunca ejecutes `docker compose down -v`**: la opción `-v` borra el volumen con toda la base de datos.

---

## 5. Pruebas y calidad (para el equipo)

```powershell
docker compose run --rm -T tools vendor/bin/pint --test
docker compose run --rm -T tools vendor/bin/phpstan analyse --memory-limit=1G
docker compose run --rm -T tools vendor/bin/deptrac analyse
docker compose run --rm -T tools vendor/bin/pest
docker compose run --rm -T tools vendor/bin/pest --group=tooling
```

Las pruebas usan la base aislada `wms_test` y **nunca** tocan la base `wms` (hay un candado que lo impide).
La suite completa tarda entre 5 y 10 minutos.

---

## 6. Problemas comunes

| Síntoma | Causa probable | Solución |
|---|---|---|
| `failed to connect to the docker API` / `dockerDesktopLinuxEngine` | Docker Desktop no está abierto | Ábrelo y espera "Engine running" |
| `port is already allocated` (5432, 8080, 80 o 443) | Otro programa usa ese puerto (PostgreSQL local, IIS, Skype, XAMPP) | Cierra ese programa o detén su servicio y vuelve a `docker compose up -d` |
| `https://localhost` muestra una página de **Apache** o "Not Found" | XAMPP (servicio Apache) también escucha en 80/443 | Detén Apache en el panel de XAMPP o en `services.msc`, o usa <http://localhost:8080/admin> |
| La página muestra error 500 | Falta `APP_KEY` o la base no está migrada | `docker compose run --rm tools php artisan key:generate` y luego `migrate --force`; revisa `docker compose logs app` |
| `Class "Filament\..." not found` | Falta la carpeta `vendor` | `docker compose run --rm tools composer install` y `docker compose build app` |
| `relation "users" does not exist` | No se migró la base | Paso 3.5 |
| No puedo entrar con el admin | No se ejecutó `db:seed` o el `.env` no tenía `WMS_ADMIN_*` | Completa `.env` y ejecuta `db:seed --force`, o `wms:admin-reset` |
| Cuenta bloqueada | 5 contraseñas incorrectas: se bloquea 15 minutos | Espera 15 min o restablece la clave con `wms:admin-reset` |
| `docker compose build` falla con `curl error 56` | Corte de internet al descargar | Vuelve a ejecutar el mismo comando (el Dockerfile ya reintenta) |
| El panel se ve sin estilos | Assets sin publicar | `docker compose run --rm tools php artisan filament:assets` y recarga con Ctrl+F5 |
| Muy lento | El proyecto está en el USB o en OneDrive | Cópialo a `C:\wms` |

---

## 7. Qué hay dentro (referencia rápida)

| Servicio | Qué hace | Puerto |
|---|---|---|
| `app` | Laravel + Filament (FrankenPHP) | 8080 → 8000 |
| `worker` | Tareas en cola | — |
| `scheduler` | Tareas programadas | — |
| `db` | PostgreSQL 17 (`wms` y `wms_test`) | 127.0.0.1:5432 |
| `caddy` | HTTPS delante de `app` | 80 / 443 |
| `tools` | PHP para migrar, probar y formatear (se usa con `docker compose run --rm tools …`) | — |
