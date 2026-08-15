FROM php:8.3-fpm

WORKDIR /var/www/html
ENV HOME=/var/www/html

RUN apt-get update && apt-get install -y --no-install-recommends \
    git curl zip unzip supervisor nginx \
    libpng-dev libonig-dev libxml2-dev libzip-dev libpq-dev libicu-dev \
    postgresql-client \
    && docker-php-ext-install pdo pdo_pgsql mbstring exif pcntl bcmath gd zip intl \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY . .
COPY entrypoint.sh /entrypoint.sh
COPY supervisord.render.conf /etc/supervisor/conf.d/supervisord.conf
COPY nginx/render/default.conf.template /etc/nginx/templates/default.conf.template

RUN composer install --optimize-autoloader --no-dev \
    && rm -f bootstrap/cache/packages.php bootstrap/cache/services.php \
    && php artisan package:discover --ansi \
    && mkdir -p storage/app/public/seed/lands \
    && mkdir -p storage/framework/{cache,sessions,views} \
    && mkdir -p storage/logs \
    && mkdir -p bootstrap/cache \
    && if [ -d "database/seeders/images/lands" ]; then \
        cp -r database/seeders/images/lands/* storage/app/public/seed/lands/ 2>/dev/null || true; \
       fi \
    && rm -rf /etc/nginx/sites-enabled/default \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 775 storage bootstrap/cache \
    && chmod +x /entrypoint.sh

# Render assigns the external port dynamically via $PORT at container start —
# EXPOSE here is documentation only, entrypoint.sh renders the real nginx
# config from the template using whatever $PORT actually is at runtime.
EXPOSE 8000

CMD ["/entrypoint.sh"]