#!/bin/sh
set -e

# ── Ensure storage directories exist ─────────────────────────────────────────
mkdir -p storage/app/public \
         storage/framework/cache/data \
         storage/framework/sessions \
         storage/framework/views \
         storage/logs \
         bootstrap/cache

# ── Laravel bootstrap ─────────────────────────────────────────────────────────
php artisan config:clear
php artisan cache:clear
php artisan config:cache
php artisan route:cache
php artisan storage:link --quiet 2>/dev/null || true
php artisan migrate --force

# ── Mailer test (remove after one deploy) ────────────────────────────────────
php artisan mail:test \
  --to=alaladeayodeji1@gmail.com \
  --mailer=resend,mailtrap,mailersend,mailgun,postmark || true

exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf