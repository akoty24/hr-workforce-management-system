#!/usr/bin/env bash
# Container entrypoint. One image, several roles (CONTAINER_ROLE):
#   web        HTTP server on $PORT
#   worker     queue worker
#   scheduler  task scheduler
#   all        all three in one container (small hosts / Railway free plan)
set -euo pipefail
cd /app

role="${CONTAINER_ROLE:-web}"

if [ -z "${APP_KEY:-}" ]; then
  echo "APP_KEY is not set. Generate one with: php artisan key:generate --show" >&2
  exit 1
fi

# Migrations run from ONE place only (set RUN_MIGRATIONS=true on a single
# service). A failure stops the container, so a half-migrated release never
# serves traffic.
if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
  php artisan migrate --force --no-interaction
fi

# Cache config, routes, views and events for this environment.
php artisan optimize --no-interaction

web()       { exec frankenphp run --config /etc/frankenphp/Caddyfile; }
worker()    { exec php artisan queue:work --sleep=3 --tries=3 --max-time=3600 --no-interaction; }
scheduler() { exec php artisan schedule:work --no-interaction; }

case "$role" in
  web)       web ;;
  worker)    worker ;;
  scheduler) scheduler ;;
  all)
    # If any process exits, stop the container so the platform restarts it.
    php artisan queue:work --sleep=3 --tries=3 --max-time=3600 --no-interaction &
    php artisan schedule:work --no-interaction &
    frankenphp run --config /etc/frankenphp/Caddyfile &
    trap 'kill 0' TERM INT
    wait -n
    echo "A process exited; stopping container." >&2
    kill 0
    exit 1
    ;;
  *)
    echo "Unknown CONTAINER_ROLE '$role' (expected web, worker, scheduler or all)." >&2
    exit 1
    ;;
esac
