FROM php:8.3-fpm-alpine

# System dependencies
RUN apk add --no-cache \
    bash git curl unzip \
    libpng-dev libzip-dev libxml2-dev oniguruma-dev \
    freetype-dev libjpeg-turbo-dev \
    && docker-php-ext-install \
        pdo pdo_mysql mbstring zip xml bcmath pcntl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd \
    && pecl install redis \
    && docker-php-ext-enable redis

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# php.ini tweaks
RUN echo "memory_limit=512M" > /usr/local/etc/php/conf.d/acqc.ini \
    && echo "upload_max_filesize=64M" >> /usr/local/etc/php/conf.d/acqc.ini \
    && echo "post_max_size=64M" >> /usr/local/etc/php/conf.d/acqc.ini

WORKDIR /var/www/server

# Non-root user
RUN addgroup -g 1000 acqc && adduser -u 1000 -G acqc -s /bin/bash -D acqc \
    && chown -R acqc:acqc /var/www

USER acqc

EXPOSE 9000
CMD ["php-fpm"]
