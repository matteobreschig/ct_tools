# =========================
# STAGE 1 - BUILD
# =========================
FROM php:8.4-cli AS build

# Install dependencies di sistema
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libicu-dev \
    libxml2-dev \
    libcurl4-openssl-dev \
    openssl \
    && docker-php-ext-install \
        pdo_mysql \
        zip \
        bcmath \
        exif \
        intl \
        dom \
        gd \
        curl \
        opcache \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Imposta working directory
WORKDIR /app

# Copia solo composer per caching layer
COPY composer.json composer.lock ./

# Composer install (solo pacchetti produzione ma con scripts)
RUN composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --no-progress

# Copia tutto il codice
COPY . .

# Ottimizza autoload
RUN composer dump-autoload --optimize --no-dev

# =========================
# STAGE 2 - PRODUCTION
# =========================
FROM php:8.4-apache

# Install dependencies richieste
RUN apt-get update && apt-get install -y \
    libzip-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libicu-dev \
    libxml2-dev \
    unzip \
    libcurl4-openssl-dev \
    && docker-php-ext-install \
        pdo_mysql \
        zip \
        bcmath \
        exif \
        intl \
        dom \
        gd \
        curl \
        opcache \
    && a2enmod rewrite \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Set document root Laravel
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Working directory
WORKDIR /var/www/html

# Copia il build stage
COPY --from=build /app /var/www/html

# Permessi su storage e bootstrap/cache
RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Copia entrypoint
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# Espone porta Apache
EXPOSE 80

# ENTRYPOINT
ENTRYPOINT ["/entrypoint.sh"]
