# Simple KUMA — PHP 8.2 + Apache (document root = public/)
FROM php:8.2-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
        libcurl4-openssl-dev \
        libonig-dev \
        libpng-dev \
        libzip-dev \
        libicu-dev \
        unzip \
        git \
    && docker-php-ext-install -j$(nproc) \
        mysqli \
        pdo_mysql \
        mbstring \
        gd \
        zip \
        intl \
        opcache \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*
# curl / openssl / json / fileinfo / filter ship enabled with php:8.2-apache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
    && printf '%s\n' \
        '<Directory /var/www/html/public>' \
        '    Options FollowSymLinks' \
        '    AllowOverride All' \
        '    Require all granted' \
        '</Directory>' \
        > /etc/apache2/conf-available/simplekuma-public.conf \
    && a2enconf simplekuma-public

WORKDIR /var/www/html

COPY . /var/www/html/

RUN if [ ! -f vendor/autoload.php ]; then \
        composer install --no-dev --optimize-autoloader --no-interaction; \
    fi \
    && mkdir -p storage/logs storage/cache storage/updates config \
    && chown -R www-data:www-data storage config \
    && chmod -R ug+rwx storage config

COPY docker/entrypoint.sh /usr/local/bin/simplekuma-entrypoint.sh
RUN sed -i 's/\r$//' /usr/local/bin/simplekuma-entrypoint.sh \
    && chmod +x /usr/local/bin/simplekuma-entrypoint.sh

EXPOSE 80

ENTRYPOINT ["simplekuma-entrypoint.sh"]
CMD ["apache2-foreground"]
