#!/usr/bin/env bash
# Runs the browser tests: serves the app and Reverb on spare ports, runs Dusk, always stops them again.
# Prerequisites: `npm run build`, `php artisan dusk:chrome-driver`, and a .env.dusk.local (copy .env.dusk.example).
set -euo pipefail
cd "$(dirname "$0")/../.."

[ -f .env.dusk.local ] || cp .env.dusk.example .env.dusk.local
set -a
# shellcheck disable=SC1091
. ./.env.dusk.local
set +a

rm -rf tests/Browser/screenshots tests/Browser/console
case "$DB_DATABASE" in *dusk*) ;; *) echo "Refusing to run: DB_DATABASE=$DB_DATABASE is not a dusk database." >&2; exit 1 ;; esac
mkdir -p tests/Browser/screenshots tests/Browser/console
pids=()
# Each server runs in its own session so its whole process group (artisan serve -> php -S -> workers) can be stopped.
cleanup() { for p in "${pids[@]:-}"; do [ -n "$p" ] && kill -- "-$p" 2>/dev/null || true; done; }
trap cleanup EXIT

APP_PORT="${APP_URL##*:}"
for port in "$APP_PORT" "$REVERB_SERVER_PORT"; do
  if (exec 3<>"/dev/tcp/127.0.0.1/$port") 2>/dev/null; then echo "Port $port is already in use." >&2; exit 1; fi
done
php artisan migrate:fresh --force -q
PHP_CLI_SERVER_WORKERS=4 setsid php artisan serve --host=127.0.0.1 --port="$APP_PORT" --no-reload >storage/logs/dusk-serve.log 2>&1 &
pids+=($!)
setsid php artisan reverb:start --host="$REVERB_SERVER_HOST" --port="$REVERB_SERVER_PORT" >storage/logs/dusk-reverb.log 2>&1 &
pids+=($!)

for _ in $(seq 1 50); do
  curl -fs -o /dev/null "$APP_URL/up" && (exec 3<>"/dev/tcp/$REVERB_SERVER_HOST/$REVERB_SERVER_PORT") 2>/dev/null && break
  sleep 0.2
done

vendor/bin/phpunit -c phpunit.dusk.xml "$@"
