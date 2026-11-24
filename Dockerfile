FROM php:8.2-apache

RUN apt-get update && apt-get install -y git curl zip unzip libpng-dev libonig-dev libxml2-dev libzip-dev libpq-dev default-mysql-client && apt-get clean && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install pdo_mysql pdo_pgsql mbstring exif pcntl bcmath gd zip

RUN a2enmod rewrite

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf && sed -ri -e 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

RUN composer create-project --prefer-dist laravel/laravel . "11.*"

COPY sistema_asistencia_donsen_laravel.php /var/www/html/sistema_asistencia_donsen_laravel.php
RUN php /var/www/html/sistema_asistencia_donsen_laravel.php && rm /var/www/html/sistema_asistencia_donsen_laravel.php

RUN mkdir -p /var/www/html/app/Providers && printf '<?php\nnamespace App\Providers;\nuse Illuminate\Support\ServiceProvider;\nuse Illuminate\Support\Facades\URL;\nclass AppServiceProvider extends ServiceProvider {\n    public function register(): void {}\n    public function boot(): void {\n        if (config("app.env") === "production") {\n            URL::forceScheme("https");\n        }\n    }\n}\n' > /var/www/html/app/Providers/AppServiceProvider.php

RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

RUN printf '#!/bin/bash\nset -e\ncp -n .env.example .env || true\nphp artisan key:generate --force\nphp artisan config:clear\nphp artisan migrate --force\nphp artisan db:seed --force || true\napache2-foreground\n' > /usr/local/bin/start-donsen.sh && chmod +x /usr/local/bin/start-donsen.sh

EXPOSE 80

CMD ["/usr/local/bin/start-donsen.sh"]
