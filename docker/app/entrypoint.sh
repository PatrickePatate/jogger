#!/bin/sh
# Roles: `app` (web server; also migrates), or any artisan command line for the other containers
# (e.g. `php artisan horizon`). Only `app` migrates, so the others wait for it to be healthy.
set -eu
cd /var/www/html

if [ "${1:-app}" = "healthcheck" ]; then
    # The web container answers /up; the others are healthy while their process runs.
    if [ "${JOGGER_ROLE:-app}" = "app" ]; then
        exec php -r 'exit(@file_get_contents("http://127.0.0.1:8000/up") === false ? 1 : 0);'
    fi
    exit 0
fi

# Generating a key is the one thing that must work without one.
case "$*" in *key:generate*) exec "$@" ;; esac

if [ -z "${APP_KEY:-}" ]; then
    echo "APP_KEY is not set. Generate one with: docker run --rm <image> php artisan key:generate --show" >&2
    exit 1
fi

if [ "${1:-app}" = "app" ]; then
    # Migrates under a lock: of several replicas starting at once, one migrates and the others wait.
    php artisan jogger:install
    php artisan storage:link --force >/dev/null 2>&1 || true
fi

php artisan config:cache >/dev/null
php artisan route:cache >/dev/null
php artisan view:cache >/dev/null

if [ "${1:-app}" = "app" ]; then
    exec frankenphp php-server --root public --listen :8000
fi

exec "$@"
