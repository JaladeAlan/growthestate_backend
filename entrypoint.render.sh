#!/bin/sh
set -e

# ── Ensure storage directories exist ─────────────────────────────────────────
mkdir -p storage/app/public \
         storage/framework/cache/data \
         storage/framework/sessions \
         storage/framework/views \
         storage/logs \
         bootstrap/cache

# ── Render nginx config: substitute the real $PORT it assigns at runtime ────
PORT="${PORT:-8000}"
mkdir -p /etc/nginx/conf.d
sed "s/__PORT__/${PORT}/" /etc/nginx/templates/default.conf.template > /etc/nginx/conf.d/default.conf

# ── Laravel bootstrap ─────────────────────────────────────────────────────────
php artisan config:clear
php artisan cache:clear
php artisan config:cache
php artisan route:cache
php artisan storage:link --quiet 2>/dev/null || true
php artisan migrate --force

exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
