#!/bin/bash
# HU-060: restauración controlada. Restaura un respaldo en una base aislada (nunca en la base real), compara los conteos
# con los registrados al respaldar, reconcilia el ledger con fn_reconcile_ledger() y documenta tiempo y datos recuperados.
# Uso: restore-test.sh [archivo.dump]   (por defecto, el respaldo más reciente de BACKUP_DIR)
set -uo pipefail

BACKUP_DIR="${BACKUP_DIR:-/backups}"
DB="${PGDATABASE:-wms}"
TEST_DB="${RESTORE_TEST_DB:-${DB}_restore_test}"
RESULT_FILE="$BACKUP_DIR/ultima-restauracion.json"
KEY_TABLES="users products stock_movements stock_balances"
FILE="${1:-$(ls -1t "$BACKUP_DIR"/"${DB}"_*.dump 2>/dev/null | head -1)}"
START="$(date +%s)"

if [ "$TEST_DB" = "$DB" ]; then echo "La base de prueba no puede ser la base real." >&2; exit 2; fi

result() { # estado, mensaje
  printf '{"status":"%s","at":"%s","file":"%s","seconds":%s,"tables":%s,"rows":{%s},"ledger_differences":%s,"message":"%s"}\n' \
    "$1" "$(date '+%Y-%m-%dT%H:%M:%S%z')" "$(basename "${FILE:-}")" "$(( $(date +%s) - START ))" "${TABLES:-0}" "${ROWS_JSON:-}" "${DIFFS:--1}" "${2//\"/\'}" > "$RESULT_FILE" 2>/dev/null
  echo "Restauración $1: $2"
}
fail() { result "FAILED" "$1"; psql -d postgres -qAtX -c "DROP DATABASE IF EXISTS \"$TEST_DB\"" >/dev/null 2>&1; exit 1; }

[ -n "$FILE" ] && [ -f "$FILE" ] || fail "no hay un archivo de respaldo para restaurar"

# Integridad previa (copia dañada)
if [ -f "$FILE.sha256" ] && [ "$(sha256sum "$FILE" | cut -d' ' -f1)" != "$(cut -d' ' -f1 "$FILE.sha256")" ]; then fail "la suma de verificación no coincide (copia dañada)"; fi
pg_restore --list "$FILE" >/dev/null 2>&1 || fail "el archivo no se puede leer como respaldo de PostgreSQL (copia dañada)"

# Restauración en base vacía y aislada
psql -d postgres -qAtX -c "DROP DATABASE IF EXISTS \"$TEST_DB\"" >/dev/null 2>&1
psql -d postgres -qAtX -c "CREATE DATABASE \"$TEST_DB\"" >/dev/null 2>&1 || fail "no se pudo crear la base aislada $TEST_DB"
pg_restore --no-owner --no-privileges --exit-on-error -d "$TEST_DB" "$FILE" > /tmp/restore.err 2>&1 || fail "pg_restore falló: $(tr '\n' ' ' < /tmp/restore.err | cut -c1-200)"

TABLES="$(psql -tAX -d "$TEST_DB" -c "SELECT count(*) FROM information_schema.tables WHERE table_schema='public' AND table_type='BASE TABLE'")"
[ "${TABLES:-0}" -gt 0 ] || fail "la base restaurada no tiene tablas"

# Conteos recuperados frente a los registrados al respaldar (antes <= restaurado <= después)
ROWS_JSON=""
for t in $KEY_TABLES; do
  got="$(psql -tAX -d "$TEST_DB" -c "SELECT count(*) FROM $t")"
  ROWS_JSON="$ROWS_JSON\"$t\":${got:-0},"
  if [ -f "$FILE.meta" ]; then
    before="$(grep "^before_$t=" "$FILE.meta" | cut -d= -f2)"; after="$(grep "^after_$t=" "$FILE.meta" | cut -d= -f2)"
    if [ -n "$before" ] && [ -n "$after" ] && { [ "$got" -lt "$before" ] || [ "$got" -gt "$after" ]; }; then
      ROWS_JSON="${ROWS_JSON%,}"; fail "los datos recuperados de $t ($got) no coinciden con lo respaldado ($before a $after)"
    fi
  fi
done
ROWS_JSON="${ROWS_JSON%,}"

# Reconciliación del ledger sobre la copia restaurada
DIFFS="$(psql -tAX -d "$TEST_DB" -c "SELECT count(*) FROM fn_reconcile_ledger()" 2>/dev/null)"
[ -n "$DIFFS" ] || fail "no se pudo reconciliar el ledger en la copia restaurada"
[ "$DIFFS" = "0" ] || fail "la reconciliación encontró $DIFFS diferencia(s) entre el ledger y los saldos de la copia"

psql -d postgres -qAtX -c "DROP DATABASE IF EXISTS \"$TEST_DB\"" >/dev/null 2>&1
result "OK" "restaurado en $(( $(date +%s) - START )) s; $TABLES tablas; ledger sin diferencias"
exit 0
