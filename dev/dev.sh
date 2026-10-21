#!/usr/bin/env bash
# Control script for the scheduled_send development stack.
# Run ./dev.sh help for the list of commands.
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")"

URL="http://localhost:8095"
SQL_FILE="../SQL/mysql.initial.sql"
WAIT_TIMEOUT="${WAIT_TIMEOUT:-240}"

# docker compose exec allocates a TTY by default; disable it when not on a terminal.
tty_flag=()
if [ ! -t 0 ] || [ ! -t 1 ]; then
  tty_flag=(-T)
fi

usage() {
  cat <<'EOF'
Usage: ./dev.sh <command> [args]

  up        Start the stack and wait until Roundcube answers HTTP 200
  down      Stop the stack and remove its containers, network and volumes
  db-init   Load ../SQL/mysql.initial.sql into the Roundcube database
  worker    Run the plugin worker in the roundcube container (args are passed on)
  logs      Follow container logs (optionally name a service: roundcube, db, mail)
  shell     Open a shell in the roundcube container
  ps        Show container status

Environment:
  DEV_PLUGINS   Roundcube plugin list (default: scheduled_send,managesieve)
  WAIT_TIMEOUT  Seconds `up` waits for Roundcube (default: 240)
EOF
}

wait_for_roundcube() {
  echo "Waiting for Roundcube at $URL (up to ${WAIT_TIMEOUT}s) ..."
  local deadline=$((SECONDS + WAIT_TIMEOUT)) code=000
  while [ "$SECONDS" -lt "$deadline" ]; do
    code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 5 "$URL/" || true)"
    if [ "$code" = "200" ]; then
      echo "Roundcube is up: $URL (log in as alice@example.test with any password)"
      return 0
    fi
    sleep 2
  done
  echo "Roundcube did not answer HTTP 200 within ${WAIT_TIMEOUT}s (last status: $code)." >&2
  docker compose logs --tail=40 roundcube >&2 || true
  return 1
}

cmd="${1:-help}"
[ $# -gt 0 ] && shift

case "$cmd" in
  up)
    docker compose up -d
    wait_for_roundcube
    ;;
  down)
    docker compose down --volumes --remove-orphans
    ;;
  db-init)
    if [ ! -f "$SQL_FILE" ]; then
      echo "$SQL_FILE does not exist yet, nothing to load."
      exit 0
    fi
    # Credentials come from the db container's own environment (see docker-compose.yml).
    docker compose exec -T db sh -c \
      'exec mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"' < "$SQL_FILE"
    echo "Loaded $SQL_FILE"
    ;;
  worker)
    docker compose exec ${tty_flag[@]+"${tty_flag[@]}"} roundcube php /var/www/html/plugins/scheduled_send/bin/worker.php "$@"
    ;;
  logs)
    docker compose logs -f --tail=100 "$@"
    ;;
  shell)
    docker compose exec ${tty_flag[@]+"${tty_flag[@]}"} roundcube bash "$@"
    ;;
  ps)
    docker compose ps "$@"
    ;;
  help|-h|--help)
    usage
    ;;
  *)
    echo "Unknown command: $cmd" >&2
    usage >&2
    exit 2
    ;;
esac
