#!/bin/sh
set -eu

export PORT="${PORT:-10000}"
export IBEMS_BASE_URL="${IBEMS_BASE_URL:-${RENDER_EXTERNAL_URL:-http://localhost:${PORT}}}"

mkdir -p \
    /var/www/html/writable/cache \
    /var/www/html/writable/logs \
    /var/www/html/writable/session \
    /var/www/html/writable/uploads \
    /var/www/html/public/uploads
chown -R www-data:www-data /var/www/html/writable /var/www/html/public/uploads

if [ "${IBEMS_HOSTED_BOOTSTRAP:-0}" = "1" ]; then
    php spark ibems:hosted-bootstrap
fi

exec "$@"
