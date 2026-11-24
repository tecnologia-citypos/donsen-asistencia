# ============================================================================
# DOCKERFILE AUTOINSTALABLE PARA RENDER (WEB SERVICE + POSTGRES / MYSQL)
# Proyecto: Sistema de Control de Asistencia QR - Empresa Donsen
# ============================================================================

FROM php:8.2-apache

# 1. Instalar dependencias del sistema operativo (soporte PostgreSQL de Render y MySQL)
RUN apt-get update && apt-get install -y \
    git \
    curl \
    zip \
    unzip \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    libpq-dev \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# 2. Instalar extensiones PHP requeridas por Laravel, PostgreSQL y MySQL
RUN docker-php-ext-install pdo_pgsql pdo_mysql mbstring exif pcntl bcmath gd zip

# 3. Habilitar mod_rewrite de Apache para las rutas de Laravel
RUN a2enmod rewrite

# 4. Configurar la carpeta /public de Laravel como raíz del servidor web
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# 5. Copiar Composer desde la imagen oficial
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

# 6. Crear el proyecto base de Laravel directamente en el servidor
WORKDIR /var/www/html
RUN composer create-project laravel/laravel . --prefer-dist --no-interaction

# 7. Copiar el instalador MVC de Donsen y desplegar Controladores, Modelos, Vistas y Migraciones
COPY sistema_asistencia_donsen_laravel.php /var/www/html/sistema_asistencia_donsen_laravel.php
RUN php /var/www/html/sistema_asistencia_donsen_laravel.php

# 8. Generar clave de aplicación y configurar HTTPS + TrustProxies para Render
RUN php artisan key:generate --force \
    && printf '%s\n' \
      '<?php' \
      'namespace App\Providers;' \
      'use Illuminate\Support\ServiceProvider;' \
      'use Illuminate\Support\Facades\URL;' \
      'class AppServiceProvider extends ServiceProvider {' \
      '    public function register(): void {}' \
      '    public function boot(): void {' \
      '        if (config("app.env") === "production") {' \
      '            URL::forceScheme("https");' \
      '        }' \
      '    }' \
      '}' > app/Providers/AppServiceProvider.php \
    && printf '%s\n' \
      '<?php' \
      'use Illuminate\Foundation\Application;' \
      'use Illuminate\Foundation\Configuration\Exceptions;' \
      'use Illuminate\Foundation\Configuration\Middleware;' \
      'return Application::configure(basePath: dirname(__DIR__))' \
      '    ->withRouting(web: __DIR__."/../routes/web.php", commands: __DIR__."/../routes/console.php", health: "/up")' \
      '    ->withMiddleware(function (Middleware $middleware) { $middleware->trustProxies(at: "*"); })' \
      '    ->withExceptions(function (Exceptions $exceptions) {})->create();' > bootstrap/app.php

# 9. Asignar permisos de escritura a storage y caché
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# 10. Puerto dinámico asignado por Render
EXPOSE 10000

# 11. Arranque: adapta puerto Apache, ejecuta migraciones + seeder de Donsen e inicia servidor
CMD sed -i "s/80/${PORT:-10000}/g" /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf \
    && php artisan config:clear \
    && php artisan migrate --force \
    && (php artisan db:seed --force || true) \
    && apache2-foreground