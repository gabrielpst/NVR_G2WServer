#!/usr/bin/env bash
set -euo pipefail

log() { echo "$(date -Iseconds) $*"; }

log "> Writing /root/.my.cnf"
cat >/root/.my.cnf <<EOF
[client]
user=${MYSQL_ADMIN_LOGIN:-root}
password=${MYSQL_ADMIN_PASSWORD:-root}
[mysqldump]
user=${MYSQL_ADMIN_LOGIN:-root}
password=${MYSQL_ADMIN_PASSWORD:-root}
EOF
chmod 600 /root/.my.cnf || true

log "> Writing /etc/bluecherry.conf"
cat >/etc/bluecherry.conf <<EOF
version = "1.0";
bluecherry:
{
    db:
    {
        type = 2;
        dbname   = "${BLUECHERRY_DB_NAME:-bluecherry}";
        user     = "${BLUECHERRY_DB_USER:-bluecherry}";
        password = "${BLUECHERRY_DB_PASSWORD:-bluecherry}";
        host     = "${BLUECHERRY_DB_HOST:-bc-mysql}";
        userhost = "${BLUECHERRY_DB_ACCESS_HOST:-%}";
    };
};
EOF

install -d -m 0775 /var/lib/bluecherry/recordings
chown "${BLUECHERRY_LINUX_USER_NAME:-bluecherry}":"${BLUECHERRY_LINUX_GROUP_NAME:-bluecherry}" /var/lib/bluecherry/recordings || true
chmod ug+rwx /var/lib/bluecherry/recordings || true
install -d -m 0775 /var/lib/bluecherry/snapshots
chown "${BLUECHERRY_LINUX_USER_NAME:-bluecherry}":"${BLUECHERRY_LINUX_GROUP_NAME:-bluecherry}" /var/lib/bluecherry/snapshots || true
chmod 777 /proc/self/fd/1 || true

log "> Starting rsyslogd"
/usr/sbin/rsyslogd || log "WARN: rsyslogd failed (continuing)"

PHPFPM_STARTED=0
for v in 8.3 8.2 8.1; do
  if command -v "php-fpm${v}" >/dev/null 2>&1; then
    log "> Starting php-fpm${v}"
    if "php-fpm${v}"; then
      sock="/run/php/php${v}-fpm.sock"
      [ -S "$sock" ] && ln -sf "$sock" /etc/alternatives/php-fpm.sock || true
      PHPFPM_STARTED=1
    else
      log "WARN: php-fpm${v} failed (continuing)"
    fi
    break
  fi
done
[ "$PHPFPM_STARTED" -eq 1 ] || log "WARN: no php-fpm found; UI may not work"

if command -v nginx >/dev/null 2>&1; then
  log "> Starting nginx"
  nginx || log "WARN: nginx failed (continuing)"
else
  log "WARN: nginx not installed; UI may be unreachable"
fi

DB_HOST="${BLUECHERRY_DB_HOST:-bc-mysql}"
DB_NAME="${BLUECHERRY_DB_NAME:-bluecherry}"
DB_USER="${BLUECHERRY_DB_USER:-bluecherry}"
DB_PASS="${BLUECHERRY_DB_PASSWORD:-bluecherry}"

log "> Waiting for MySQL at $DB_HOST..."
for i in $(seq 1 60); do
  mysql -h "$DB_HOST" -e "SELECT 1" >/dev/null 2>&1 && break
  sleep 1
done
log "> DB is up."

if mysql -h "$DB_HOST" -e "USE \`$DB_NAME\`" >/dev/null 2>&1; then
  log "> DB exists → upgrading"
  /bin/bc-database-upgrade "$DB_NAME" "$DB_USER" "$DB_PASS" "$DB_HOST" || { log "ERROR: DB upgrade failed"; exit 1; }
else
  log "> DB missing → creating"
  /bin/bc-database-create "$DB_NAME" "$DB_USER" "$DB_PASS" "$DB_HOST" || { log "ERROR: DB create failed"; exit 1; }
fi

log "> Ensuring '${DB_USER}' has PROCESS/SHOW VIEW/EVENT/TRIGGER/LOCK TABLES and native auth"
# Credenciais de admin vêm do /root/.my.cnf (escrito acima) e o SQL vai por
# stdin: nenhuma senha na linha de comando (ps, /proc) nem em log.
mysql -h "$DB_HOST" <<SQL || log 'WARN: grant/alter failed; continuing'
GRANT PROCESS, SHOW VIEW, EVENT, TRIGGER, LOCK TABLES ON *.* TO '${DB_USER}'@'${BLUECHERRY_DB_ACCESS_HOST:-%}';
ALTER USER '${DB_USER}'@'${BLUECHERRY_DB_ACCESS_HOST:-%}' IDENTIFIED WITH mysql_native_password BY '${DB_PASS}';
FLUSH PRIVILEGES;
SQL

# Ponte de eventos ONVIF: faz a câmera avisar quando ELA detecta movimento, em
# vez do servidor decodificar vídeo pra detectar sozinho (que custa CPU por
# câmera, ainda mais em H.265). O supervisor sobe um processo por câmera com a
# opção "Trigger recording on ONVIF events" ligada e a agenda em Trigger.
# Ver misc/onvif/onvif-event-{supervisor,bridge}.php no repositório.
# Desligar com ONVIF_EVENT_BRIDGE=0 se precisar isolar algum problema.
if [ "${ONVIF_EVENT_BRIDGE:-1}" = "1" ] && [ -f /usr/share/bluecherry/onvif/onvif-event-supervisor.php ]; then
  log "> Starting ONVIF event bridge supervisor"
  php /usr/share/bluecherry/onvif/onvif-event-supervisor.php &
else
  log "> ONVIF event bridge disabled"
fi

# Snapshot updater: mantem um JPEG recente de cada camera pra o app usar como
# miniatura sem depender de gravacao. Desliga com SNAPSHOT_UPDATER=0.
if [ "${SNAPSHOT_UPDATER:-1}" = "1" ] && [ -f /usr/share/bluecherry/snapshots/snapshot-updater.php ]; then
  log "> Starting snapshot updater"
  php /usr/share/bluecherry/snapshots/snapshot-updater.php &
else
  log "> Snapshot updater disabled"
fi

# Rotação do /var/log/bluecherry.log. O pacote instala /etc/logrotate.d/bluecherry,
# mas quem chama o logrotate é o cron, e não há cron rodando no container: o
# arquivo crescia sem limite (397 MB em produção). Aqui um laço simples faz esse
# papel. Rotaciona por tamanho, porque uma câmera com defeito enche o log em
# horas, não em dias. Desliga com LOG_ROTATE=0.
if [ "${LOG_ROTATE:-1}" = "1" ] && command -v logrotate >/dev/null 2>&1; then
  cat >/etc/bluecherry-logrotate.conf <<EOF
/var/log/bluecherry.log {
    su root syslog
    size ${LOG_ROTATE_SIZE:-50M}
    rotate ${LOG_ROTATE_KEEP:-7}
    missingok
    notifempty
    compress
    delaycompress
    create 0640 syslog bluecherry
    postrotate
        pkill -HUP -x rsyslogd || true
    endscript
}
EOF
  rotate_logs() {
    logrotate -s /var/lib/logrotate/bluecherry-container.status /etc/bluecherry-logrotate.conf \
      || log "WARN: logrotate failed"
  }
  log "> Starting log rotation loop (every ${LOG_ROTATE_INTERVAL:-600}s, size ${LOG_ROTATE_SIZE:-50M}, keep ${LOG_ROTATE_KEEP:-7})"
  rotate_logs
  ( while sleep "${LOG_ROTATE_INTERVAL:-600}"; do rotate_logs; done ) &
else
  log "> Log rotation disabled"
fi

log "> Starting bc-server"
export LD_LIBRARY_PATH=/usr/lib/bluecherry
BC_ARGS=(-u "${BLUECHERRY_LINUX_USER_NAME:-bluecherry}" -g "${BLUECHERRY_LINUX_GROUP_NAME:-bluecherry}" -s)

if [ "${DEBUG:-0}" = "1" ]; then
  log "Running bc-server in DEBUG mode (foreground, log level debug)"
  exec /usr/sbin/bc-server "${BC_ARGS[@]}" -l d
fi

/usr/sbin/bc-server "${BC_ARGS[@]}" &
BC_PID=$!

# "docker stop"/"docker restart" mandam SIGTERM só pro PID 1, que é este script.
# Sem repassar, o bc-server ficava rodando até o SIGKILL do Docker (10 s depois)
# e morria com os arquivos de gravação abertos: o MP4 só ganha o índice (moov)
# no fechamento, então o trecho em curso de TODAS as câmeras ficava ilegível a
# cada reinício. Repassando o sinal, ele fecha as gravações antes de sair.
shutdown() {
  trap - TERM INT
  log "Signal received: stopping bc-server (pid $BC_PID) so open recordings are closed"
  kill -TERM "$BC_PID" 2>/dev/null || true
  wait "$BC_PID" 2>/dev/null || true
  log "bc-server stopped."
  exit 0
}
trap shutdown TERM INT

log "All services launched. Entering watchdog loop."

if [ "${DISABLE_WATCHDOG:-0}" = "1" ]; then
  log "Watchdog disabled (DISABLE_WATCHDOG=1)."
  tail -f /dev/null &
  wait $!
fi

SLEEP="${WATCHDOG_INTERVAL:-5}"
while :; do
  # "sleep & wait" em vez de "sleep": o bash só roda o trap entre comandos, e
  # o wait é interrompido pelo sinal na hora.
  sleep "$SLEEP" &
  wait $! || true
  if ! kill -0 "${BC_PID:-0}" 2>/dev/null; then
    EXIT=0
    wait "${BC_PID:-0}" || EXIT=$?
    log "bc-server exited (code=$EXIT). Exiting container."
    exit 1
  fi
  pgrep -x nginx    >/dev/null || log "WARN: nginx not running (transient?)"
  pgrep -x rsyslogd >/dev/null || log "WARN: rsyslogd not running (transient?)"
done
