FROM php:8.3-cli

WORKDIR /var/www/html

# Install system dependencies including PostGIS
RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    curl \
    zip \
    unzip \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    libpq-dev \
    postgresql-client \
    && docker-php-ext-install pdo pdo_pgsql mbstring exif pcntl bcmath gd zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Copy project files
COPY . .

# Install PHP dependencies
RUN composer install --optimize-autoloader --no-dev

# Ensure storage directories exist with correct permissions
RUN mkdir -p storage/app/public/seed/lands \
    && mkdir -p storage/framework/{cache,sessions,views} \
    && mkdir -p storage/logs \
    && mkdir -p bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Copy seed images from database/seeders/images/lands to storage/app/public/seed/lands
RUN if [ -d "database/seeders/images/lands" ]; then \
        cp -r database/seeders/images/lands/* storage/app/public/seed/lands/ 2>/dev/null || true; \
    fi

EXPOSE 8000

# Startup command for Render
CMD php artisan config:clear && \
    php artisan cache:clear && \
    php artisan storage:link && \
    php artisan config:cache && \
    php artisan route:cache && \
    php artisan migrate:fresh --seed --force && \
    php artisan db:seed --force && \
    php artisan serve --host=0.0.0.0 --port=${PORT:-8000}