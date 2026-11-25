#!/bin/bash
# HU-060: respaldo de PostgreSQL con verificación de integridad, copia externa cifrada (opcional) y registro de fecha.
# Variables: PGHOST PGUSER PGPASSWORD PGDATABASE (conexión; nunca se imprimen), BACKUP_DIR (/backups),
# BACKUP_EXTERNAL_DIR (/external), BACKUP_ENCRYPTION_PASSWORD (si existe, la copia externa se cifra con AES-256),
# BACKUP_REQUIRE_EXTERNAL=1 (si la copia externa falla, el respaldo se marca como fallido), BACKUP_RETENTION_DAYS (14).
set -uo pipefail

BACKUP_DIR="${BACKUP_DIR:-/backups}"
EXTERNAL_DIR="${BACKUP_EXTERNAL_DIR:-/external}"
RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-14}"
DB="${PGDATABASE:-wms}"
STATUS_FILE="$BACKUP_DIR/ultimo-respaldo.json"
LOG_FILE="$BACKUP_DIR/respaldo.log"
TS="$(date +%Y%m%d_%H%M%S)"
NAME="${DB}_${TS}.dump"
TMP="$BACKUP_DIR/$NAME.tmp"
FINAL="$BACKUP_DIR/$NAME"
KEY_TABLES="users products stock_movements stock_balances"

log() { echo "$(date '+%Y-%m-%d %H:%M:%S') $*" | tee -a "$LOG_FILE" 2>/dev/null || echo "$(date '+%Y-%m-%d %H:%M:%S') $*"; }

write_status() { # estado, mensaje, bytes, sha256
  local msg="${2//\"/\'}"
  printf '{"status":"%s","at":"%s","file":"%s","bytes":%s,"sha256":"%s","external":"%s","message":"%s"}\n' \
    "$1" "$(date '+%Y-%m-%dT%H:%M:%S%z')" "$NAME" "${3:-0}" "${4:-}" "${EXTERNAL_RESULT:-no}" "$msg" > "$STATUS_FILE.tmp" 2>/dev/null \
    && mv -f "$STATUS_FILE.tmp" "$STATUS_FILE" 2>/dev/null
}

fail() {
  log "FALLO DEL RESPALDO: $1"
  write_status "FAILED" "$1"
  rm -f "$TMP" "$FINAL" "$FINAL.sha256" "$FINAL.meta" 2>/dev/null
  exit 1
}

EXTERNAL_RESULT="no"

# 1) Destino local accesible
mkdir -p "$BACKUP_DIR" 2>/dev/null
if [ ! -d "$BACKUP_DIR" ] || [ ! -w "$BACKUP_DIR" ]; then
  echo "FALLO DEL RESPALDO: el destino $BACKUP_DIR no es accesible o no se puede escribir." >&2
  exit 1
fi

# 2) Conteos de tablas clave antes y después del volcado (la restauración de prueba los compara)
count_rows() { psql -tAX -d "$DB" -c "SELECT count(*) FROM $1" 2>/dev/null; }
declare -A BEFORE AFTER
for t in $KEY_TABLES; do
  BEFORE[$t]="$(count_rows "$t")" || fail "no se pudo leer la tabla $t (¿base de datos inaccesible?)"
  [ -n "${BEFORE[$t]}" ] || fail "no se pudo leer la tabla $t (¿base de datos inaccesible?)"
done

# 3) Volcado en formato personalizado
log "Iniciando respaldo de $DB"
pg_dump --format=custom --no-owner --file="$TMP" "$DB" 2> "$BACKUP_DIR/.pg_dump.err" || fail "pg_dump terminó con error: $(tr '\n' ' ' < "$BACKUP_DIR/.pg_dump.err" | cut -c1-200)"
rm -f "$BACKUP_DIR/.pg_dump.err"
for t in $KEY_TABLES; do AFTER[$t]="$(count_rows "$t")"; done

# 4) Verificación de integridad: el archivo no está vacío y su contenido se puede leer
[ -s "$TMP" ] || fail "el archivo de respaldo quedó vacío"
TABLES_IN_DUMP="$(pg_restore --list "$TMP" 2>/dev/null | grep -c ' TABLE DATA ')" || true
[ "${TABLES_IN_DUMP:-0}" -gt 0 ] || fail "el respaldo no contiene datos de tablas (copia dañada)"
SHA="$(sha256sum "$TMP" | cut -d' ' -f1)"
mv -f "$TMP" "$FINAL" || fail "no se pudo guardar el respaldo"
echo "$SHA  $NAME" > "$FINAL.sha256"
{
  for t in $KEY_TABLES; do echo "before_$t=${BEFORE[$t]}"; echo "after_$t=${AFTER[$t]}"; done
  echo "tables_in_dump=$TABLES_IN_DUMP"
} > "$FINAL.meta"
BYTES="$(stat -c %s "$FINAL")"

# 5) Copia externa (cifrada si hay contraseña) y verificación de la copia
if [ -n "${BACKUP_ENCRYPTION_PASSWORD:-}" ] && ! command -v openssl >/dev/null 2>&1; then
  # La imagen de PostgreSQL puede no traer openssl: se instala una vez (requiere internet en el contenedor).
  (apt-get update -qq && apt-get install -y -qq openssl) >/dev/null 2>&1 || true
fi
if [ -d "$EXTERNAL_DIR" ] && [ -w "$EXTERNAL_DIR" ]; then
  if [ -n "${BACKUP_ENCRYPTION_PASSWORD:-}" ]; then
    EXT="$EXTERNAL_DIR/$NAME.enc"
    openssl enc -aes-256-cbc -pbkdf2 -salt -pass env:BACKUP_ENCRYPTION_PASSWORD -in "$FINAL" -out "$EXT" 2>/dev/null \
      || { [ "${BACKUP_REQUIRE_EXTERNAL:-0}" = "1" ] && fail "no se pudo cifrar la copia externa"; EXT=""; }
    if [ -n "$EXT" ]; then
      CHECK="$(openssl enc -d -aes-256-cbc -pbkdf2 -pass env:BACKUP_ENCRYPTION_PASSWORD -in "$EXT" 2>/dev/null | sha256sum | cut -d' ' -f1)"
      if [ "$CHECK" = "$SHA" ]; then EXTERNAL_RESULT="cifrada"; else rm -f "$EXT"; [ "${BACKUP_REQUIRE_EXTERNAL:-0}" = "1" ] && fail "la copia externa cifrada no coincide con el respaldo"; fi
    fi
  else
    if cp -f "$FINAL" "$EXTERNAL_DIR/$NAME" 2>/dev/null && [ "$(sha256sum "$EXTERNAL_DIR/$NAME" | cut -d' ' -f1)" = "$SHA" ]; then
      EXTERNAL_RESULT="sin_cifrar"
    else
      rm -f "$EXTERNAL_DIR/$NAME" 2>/dev/null
      [ "${BACKUP_REQUIRE_EXTERNAL:-0}" = "1" ] && fail "no se pudo copiar el respaldo al destino externo"
    fi
  fi
elif [ "${BACKUP_REQUIRE_EXTERNAL:-0}" = "1" ]; then
  fail "el destino externo $EXTERNAL_DIR no es accesible"
fi

# 6) Limpieza de respaldos antiguos
find "$BACKUP_DIR" -maxdepth 1 -name "${DB}_*" -mtime +"$RETENTION_DAYS" -delete 2>/dev/null
[ -d "$EXTERNAL_DIR" ] && find "$EXTERNAL_DIR" -maxdepth 1 -name "${DB}_*" -mtime +"$RETENTION_DAYS" -delete 2>/dev/null

write_status "OK" "respaldo verificado" "$BYTES" "$SHA"
log "Respaldo correcto: $NAME ($BYTES bytes, sha256 ${SHA:0:12}…, copia externa: $EXTERNAL_RESULT)"
exit 0
