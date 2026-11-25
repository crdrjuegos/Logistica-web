# Respaldo y restauración (HU-060)

El volumen `pgdata` **no es un respaldo**: si se borra el contenedor con `down -v`, los datos se pierden. El servicio `backup` hace una copia diaria, la verifica y guarda una segunda copia (cifrada) fuera de la carpeta del proyecto.

## Activar
```
docker compose -f docker-compose.yml -f docker-compose.backup.yml up -d backup
```
- Corre todos los días a las 03:00 (hora de Lima) y una vez al arrancar si no hay respaldo de las últimas 24 horas.
- Guarda `wms_AAAAMMDD_HHMMSS.dump` en `./respaldos` (14 días de retención).
- `./respaldos/ultimo-respaldo.json` dice si el último respaldo fue `OK` o `FAILED`, con fecha, tamaño y suma SHA-256. `respaldo.log` guarda el historial. Ninguno contiene contraseñas.

## Copia externa cifrada
En `.env` (no se sube al repositorio):
```
BACKUP_EXTERNAL_PATH=E:/RespaldosWMS      # una carpeta de OTRO disco, USB o unidad de red
BACKUP_ENCRYPTION_PASSWORD=una-frase-larga-y-secreta
BACKUP_REQUIRE_EXTERNAL=1                 # si la copia externa falla, el respaldo se marca FAILED
```
La copia externa se cifra con AES-256 y se comprueba descifrándola. **Sin esa contraseña no se puede restaurar:** guárdela aparte.
Para descifrar a mano:
```
openssl enc -d -aes-256-cbc -pbkdf2 -in wms_AAAAMMDD_HHMMSS.dump.enc -out wms.dump
```

## Comprobar que funciona
```
docker compose -f docker-compose.yml -f docker-compose.backup.yml run --rm --entrypoint bash backup /scripts/backup.sh
docker compose -f docker-compose.yml -f docker-compose.backup.yml run --rm --entrypoint bash backup /scripts/restore-test.sh
docker compose -f docker-compose.yml -f docker-compose.backup.yml run --rm --entrypoint bash backup /scripts/selftest.sh
```
- `backup.sh`: respalda ya.
- `restore-test.sh`: restaura el último respaldo en una base aislada (`wms_restore_test`, que se elimina al terminar), compara los conteos de tablas clave con los registrados al respaldar y reconcilia el ledger. Deja el resultado, el tiempo y los datos recuperados en `./respaldos/ultima-restauracion.json`.
- `selftest.sh`: la prueba de aceptación de CA-060-1 a CA-060-3 (incluye destino inaccesible y copia dañada). No toca los respaldos reales.

## Restaurar de verdad (emergencia)
1. Detenga la aplicación: `docker compose stop app worker scheduler`.
2. Cree la base vacía y restaure el respaldo elegido:
   ```
   docker compose exec -T db psql -U wms_owner -d postgres -c "DROP DATABASE wms"
   docker compose exec -T db psql -U wms_owner -d postgres -c "CREATE DATABASE wms OWNER wms_owner"
   docker compose exec -T db pg_restore -U wms_owner -d wms --no-owner < respaldos/wms_AAAAMMDD_HHMMSS.dump
   ```
3. Levante de nuevo: `docker compose up -d`.

## Si falla un respaldo
Un respaldo fallido **nunca** queda marcado como correcto: `ultimo-respaldo.json` pasa a `FAILED` con el motivo, el archivo incompleto se elimina y `docker compose logs backup` muestra el error. Revise ese archivo cada semana, o antes de cualquier cambio importante.
