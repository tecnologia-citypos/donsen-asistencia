FROM php:8.2-apache

1. Instalar dependencias del sistema operativo (soporte PostgreSQL y MySQL)

RUN apt-get update && apt-get install -y 

git 

curl 

zip 

unzip 

libpng-dev 

libonig-dev 

libxml2-dev 

libzip-dev 

libpq-dev 

default-mysql-client 

&& apt-get clean 

&& rm -rf /var/lib/apt/lists/*

2. Instalar extensiones PHP requeridas por Laravel, PostgreSQL y MySQL

RUN docker-php-ext-install pdo_mysql pdo_pgsql mbstring exif pcntl bcmath gd zip

3. Habilitar mod_rewrite de Apache para las rutas de Laravel

RUN a2enmod rewrite

4. Configurar /var/www/html/public como raíz del servidor web Apache

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
&& sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

5. Instalar Composer

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

6. Crear proyecto base Laravel 11 dentro del contenedor

RUN rm -rf /var/www/html/* && composer create-project --prefer-dist laravel/laravel:^11.0 /var/www/html

7. Copiar el instalador MVC de Donsen y generar modelos, controladores, migraciones y vistas

COPY sistema_asistencia_donsen_laravel.php /var/www/html/sistema_asistencia_donsen_laravel.php
RUN php /var/www/html/sistema_asistencia_donsen_laravel.php

8. Configurar proxy HTTPS de Render en Laravel para evitar errores de sesión/CSRF (419)

RUN php -r '$f="bootstrap/app.php"; $c=file_get_contents($f); $c=str_replace("->withMiddleware(function (Middleware $middleware) {", "->withMiddleware(function (Middleware $middleware) {\n        $middleware->trustProxies(at: "*");", $c); file_put_contents($f,$c);' 

&& php -r '$f="app/Providers/AppServiceProvider.php"; $c=file_get_contents($f);$c=str_replace("public function boot(): void\n    {", "public function boot(): void\n    {\n        if (env("APP_ENV") === "production") { \Illuminate\Support\Facades\URL::forceScheme("https"); }", $c); file_put_contents($f,$c);'

9. Script de arranque automático (migraciones, datos iniciales de Donsen y puerto de Render)

RUN printf '#!/usr/bin/env bash\nset -e\nif [ ! -f .env ]; then cp .env.example .env; fi\nphp artisan key:generate --force\nphp artisan config:clear\nphp artisan migrate --force\nUSER_COUNT=$(php -r "require \"vendor/autoload.php\"; \$app = require_once \"bootstrap/app.php\"; \$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); echo App\\Models\\User::count();")\nif [ "$USER_COUNT" = "0" ]; then php artisan db:seed --force; fi\nchown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache\nchmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache\nPORT_NUM=${PORT:-80}\nsed -i "s/80/${PORT_NUM}/g" /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf\nexec apache2-foreground\n' > /usr/local/bin/start.sh && chmod +x /usr/local/bin/start.sh

EXPOSE 80

CMD ["/usr/local/bin/start.sh"]
