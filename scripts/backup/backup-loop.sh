#!/bin/bash
# Ejecuta el respaldo todos los días a la hora BACKUP_HOUR (HH:MM, hora del contenedor) y uno al arrancar si no hay uno de las últimas 24 horas.
BACKUP_DIR="${BACKUP_DIR:-/backups}"
HOUR="${BACKUP_HOUR:-03:00}"
DB="${PGDATABASE:-wms}"
echo "Servicio de respaldo iniciado. Hora diaria: $HOUR. Retención: ${BACKUP_RETENTION_DAYS:-14} días."

if [ -z "$(find "$BACKUP_DIR" -maxdepth 1 -name "${DB}_*.dump" -mtime -1 2>/dev/null | head -1)" ]; then
  echo "No hay respaldo de las últimas 24 horas: se hace uno ahora."
  bash /scripts/backup.sh || echo "El respaldo inicial falló; revise ultimo-respaldo.json."
fi

while true; do
  now="$(date +%s)"
  target="$(date -d "today $HOUR" +%s)"
  [ "$target" -le "$now" ] && target="$(date -d "tomorrow $HOUR" +%s)"
  echo "Próximo respaldo: $(date -d "@$target" '+%Y-%m-%d %H:%M:%S')"
  sleep $((target - now))
  bash /scripts/backup.sh || echo "El respaldo falló; se reintenta mañana (revise ultimo-respaldo.json y respaldo.log)."
done
