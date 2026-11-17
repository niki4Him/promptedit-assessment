#!/bin/sh
set -eu

: "${APP_KEY:?Set APP_KEY in the service environment}"
: "${DB_URL:?Set DB_URL to the internal PostgreSQL connection URL}"

php artisan migrate --force --no-interaction
php artisan optimize --except=config --no-interaction

exec "$@"
