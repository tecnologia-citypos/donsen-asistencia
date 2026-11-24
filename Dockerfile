FROM php:8.2-apache

RUN apt-get update && apt-get install -y git curl zip unzip libpng-dev libonig-dev libxml2-dev libzip-dev libpq-dev default-mysql-client && apt-get clean && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install pdo_mysql pdo_pgsql mbstring exif pcntl bcmath gd zip

RUN a2enmod rewrite

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf && sed -ri -e 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

RUN composer config --global policy.advisories.block false || true
RUN composer create-project --prefer-dist laravel/laravel .

COPY sistema_asistencia_donsen_laravel.php /var/www/html/sistema_asistencia_donsen_laravel.php
RUN php /var/www/html/sistema_asistencia_donsen_laravel.php

RUN printf '%s\n' '<?php' 'namespace App\Providers;' 'use Illuminate\Support\ServiceProvider;' 'use Illuminate\Support\Facades\URL;' 'class AppServiceProvider extends ServiceProvider {' '    public function register(): void {}' '    public function boot(): void { if (env("APP_ENV") === "production") { URL::forceScheme("https"); } }' '}' > app/Providers/AppServiceProvider.php

RUN printf '%s\n' '<?php' 'chdir("/var/www/html");' 'require "vendor/autoload.php";' '$app = require "bootstrap/app.php";' '$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();' 'if (\App\Models\User::count() === 0) {' '    \Illuminate\Support\Facades\Artisan::call("db:seed", ["--force" => true]);' '}' > /usr/local/bin/seed-check.php

RUN printf '%s\n' '#!/usr/bin/env bash' 'set -e' 'cd /var/www/html' 'sed -i "/^DB_/d" .env || true' 'if [ -z "$APP_KEY" ]; then' '  export APP_KEY=$(php artisan key:generate --show)' 'fi' 'php artisan config:clear' 'php artisan migrate --force' 'php /usr/local/bin/seed-check.php' 'sed -i "s/80/${PORT:-80}/g" /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf' 'exec apache2-foreground' > /usr/local/bin/start-donsen.sh && chmod +x /usr/local/bin/start-donsen.sh

RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

EXPOSE 80

CMD ["/usr/local/bin/start-donsen.sh"]
