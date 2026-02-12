# =========================
# STAGE 1 - BUILD
# =========================
FROM php:8.4-cli AS build

# Install system dependencies e estensioni PHP richieste da Composer
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libicu-dev \
    libxml2-dev \
    libonig-dev \
    && docker-php-ext-configure gd --with-freetype=/usr/include/ --with-jpeg=/usr/include/ \
    && docker-php-ext-install \
        pdo_mysql \
        zip \
        bcmath \
        exif \
        intl \
        gd \
        opcache \
        mbstring \
        pcntl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Copia Composer dal container ufficiale
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Copia solo i file composer per installare le dipendenze
COPY composer.json composer.lock ./

# Install dependencies senza eseguire gli script (build stage)
RUN composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --no-progress \
    --no-scripts

# Copia tutto il progetto
COPY . .

# Ottimizza autoload
RUN composer dump-autoload --optimize --no-dev

# =========================
# STAGE 2 - PRODUCTION
# =========================
FROM php:8.4-apache

# Install system dependencies e estensioni PHP richieste in produzione
RUN apt-get update && apt-get install -y \
    libzip-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libicu-dev \
    libxml2-dev \
    libonig-dev \
    unzip \
    && docker-php-ext-configure gd --with-freetype=/usr/include/ --with-jpeg=/usr/include/ \
    && docker-php-ext-install \
        pdo_mysql \
        zip \
        bcmath \
        exif \
        intl \
        gd \
        opcache \
        mbstring \
        pcntl \
    && a2enmod rewrite \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Imposta document root su /public
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

WORKDIR /var/www/html

# Copia il progetto dal build stage
COPY --from=build /app /var/www/html

# Setta permessi
RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

EXPOSE 80

# EntryPoint per comandi Laravel post-deploy
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh
ENTRYPOINT ["/entrypoint.sh"]
