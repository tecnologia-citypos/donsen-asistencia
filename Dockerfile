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

RUN php -r 'file_put_contents("app/Providers/AppServiceProvider.php", "<?php\nnamespace App\Providers;\nuse Illuminate\Support\ServiceProvider;\nuse Illuminate\Support\Facades\URL;\nclass AppServiceProvider extends ServiceProvider {\n    public function register(): void {}\n    public function boot(): void { if (config("app.env") === "production") { URL::forceScheme("https"); } }\n}\n");'

RUN php -r 'file_put_contents("/usr/local/bin/seed-check.php", "<?php\nchdir("/var/www/html");\nrequire "vendor/autoload.php";\n$app = require "bootstrap/app.php";\n$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();\nif (\App\Models\User::count() === 0) {\n    \Illuminate\Support\Facades\Artisan::call("db:seed", ["--force" => true]);\n}\n");'

RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

RUN printf '#!/bin/sh\nset -e\ncp -n .env.example .env || true\nphp artisan key:generate --force\nsed -i "s/80/${PORT:-80}/g" /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf\nphp artisan config:clear\nphp artisan migrate --force\nphp /usr/local/bin/seed-check.php\nexec apache2-foreground\n' > /usr/local/bin/start-donsen.sh && chmod +x /usr/local/bin/start-donsen.sh

EXPOSE 80

CMD ["/usr/local/bin/start-donsen.sh"]
