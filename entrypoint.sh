#!/bin/sh
set -e

php artisan config:clear
php artisan cache:clear
php artisan config:cache
php artisan route:cache
php artisan storage:link
php artisan migrate --force

exec  php artisan serve --host=0.0.0.0 --port=${PORT:-8000}