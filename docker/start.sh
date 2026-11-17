#!/bin/sh
set -eu

: "${APP_KEY:?Set APP_KEY in the service environment}"
: "${DB_URL:?Set DB_URL to the internal PostgreSQL connection URL}"

php artisan migrate --force --no-interaction
# Render Free has no interactive shell: provision only when runtime secrets opt in.
if [ -n "${DEMO_ACCOUNT_EMAIL:-}${DEMO_ACCOUNT_PASSWORD:-}" ]; then
    php artisan demo:provision --no-interaction
fi

php artisan optimize --except=config --no-interaction

exec "$@"
