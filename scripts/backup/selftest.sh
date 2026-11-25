#!/bin/bash
# Prueba de aceptación de HU-060 (CA-060-1 a CA-060-3) contra la base configurada en PGHOST/PGUSER/PGPASSWORD/PGDATABASE.
# Trabaja en una carpeta temporal: no toca los respaldos reales. Termina con código 0 solo si todo pasa.
set -uo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
DB="${PGDATABASE:-wms}"
export BACKUP_ENCRYPTION_PASSWORD="clave-de-prueba-$$-no-usar"
PASS=0; FAILS=0

check() { # id, descripción, función
  local id="$1" desc="$2"; shift 2
  if "$@"; then echo "$id OK     $desc"; PASS=$((PASS + 1)); else echo "$id FALLO  $desc"; FAILS=$((FAILS + 1)); fi
}

fresh() { export BACKUP_DIR="$WORK/$1" BACKUP_EXTERNAL_DIR="$WORK/$1-externo"; unset BACKUP_REQUIRE_EXTERNAL; mkdir -p "$BACKUP_DIR" "$BACKUP_EXTERNAL_DIR"; }

ca_060_1() {
  fresh uno
  bash "$HERE/backup.sh" >/dev/null 2>&1 || return 1
  local st="$BACKUP_DIR/ultimo-respaldo.json" dump enc
  grep -q '"status":"OK"' "$st" || return 1
  dump="$(ls -1t "$BACKUP_DIR"/*.dump 2>/dev/null | head -1)"; [ -n "$dump" ] || return 1
  pg_restore --list "$dump" >/dev/null 2>&1 || return 1
  [ "$(sha256sum "$dump" | cut -d' ' -f1)" = "$(cut -d' ' -f1 "$dump.sha256")" ] || return 1
  enc="$(ls -1t "$BACKUP_EXTERNAL_DIR"/*.enc 2>/dev/null | head -1)"; [ -n "$enc" ] || return 1
  [ "$(openssl enc -d -aes-256-cbc -pbkdf2 -pass env:BACKUP_ENCRYPTION_PASSWORD -in "$enc" 2>/dev/null | sha256sum | cut -d' ' -f1)" = "$(sha256sum "$dump" | cut -d' ' -f1)" ] || return 1
  grep -q '"external":"cifrada"' "$st" || return 1
  grep -Eq '"at":"[0-9]{4}-[0-9]{2}-[0-9]{2}T' "$st" || return 1
  # sin exponer credenciales en el estado ni en el registro
  if [ -n "${PGPASSWORD:-}" ] && grep -qF "$PGPASSWORD" "$st" "$BACKUP_DIR/respaldo.log"; then return 1; fi
  ! grep -qF "$BACKUP_ENCRYPTION_PASSWORD" "$st" "$BACKUP_DIR/respaldo.log"
}

ca_060_2() {
  # usa el respaldo de la prueba anterior
  bash "$HERE/restore-test.sh" >/dev/null 2>&1 || return 1
  local r="$BACKUP_DIR/ultima-restauracion.json"
  grep -q '"status":"OK"' "$r" || return 1
  grep -q '"ledger_differences":0' "$r" || return 1
  grep -Eq '"seconds":[0-9]+' "$r" || return 1
  grep -Eq '"tables":[1-9]' "$r" || return 1
  # la base aislada se elimina y la real no se toca
  [ -z "$(psql -d postgres -tAXc "SELECT 1 FROM pg_database WHERE datname='${DB}_restore_test'")" ]
}

ca_060_3a() { # destino inaccesible
  local out
  out="$(BACKUP_DIR=/proc/respaldo-inexistente bash "$HERE/backup.sh" 2>&1)"; local rc=$?
  [ "$rc" -ne 0 ] && [ ! -f /proc/respaldo-inexistente/ultimo-respaldo.json ]
}

ca_060_3b() { # destino externo obligatorio pero inaccesible: no se marca como correcto
  fresh tres
  BACKUP_REQUIRE_EXTERNAL=1 BACKUP_EXTERNAL_DIR=/proc/externo-inexistente bash "$HERE/backup.sh" >/dev/null 2>&1; local rc=$?
  [ "$rc" -ne 0 ] && grep -q '"status":"FAILED"' "$BACKUP_DIR/ultimo-respaldo.json" && ! grep -q '"status":"OK"' "$BACKUP_DIR/ultimo-respaldo.json" && [ -z "$(ls "$BACKUP_DIR"/*.dump 2>/dev/null)" ]
}

ca_060_3c() { # copia dañada: la verificación falla y no la da por buena
  fresh cuatro
  local good dump
  good="$(ls -1t "$WORK"/uno/*.dump 2>/dev/null | head -1)"
  [ -n "$good" ] || return 1
  dump="$BACKUP_DIR/$(basename "$good")"
  head -c 2000 "$good" > "$dump"            # copia truncada
  cp "$good.sha256" "$dump.sha256"
  bash "$HERE/restore-test.sh" "$dump" >/dev/null 2>&1; local rc=$?
  [ "$rc" -ne 0 ] && grep -q '"status":"FAILED"' "$BACKUP_DIR/ultima-restauracion.json" && ! grep -q '"status":"OK"' "$BACKUP_DIR/ultima-restauracion.json"
}

check CA-060-1 "genera el respaldo, verifica integridad, copia externa cifrada y registra la fecha sin exponer credenciales" ca_060_1
check CA-060-2 "restaura en una base aislada, reconcilia el ledger y documenta tiempo y datos recuperados" ca_060_2
check CA-060-3 "con destino inaccesible no genera respaldo ni lo marca como correcto" ca_060_3a
check CA-060-3 "con destino externo obligatorio inaccesible el respaldo queda como FAILED" ca_060_3b
check CA-060-3 "con una copia dañada la restauración falla y no se marca como correcta" ca_060_3c

echo "Resultado: $PASS correctas, $FAILS con fallo."
[ "$FAILS" -eq 0 ]
