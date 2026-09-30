FROM php:8.3-fpm-alpine

# System dependencies required at runtime.
RUN apk add --no-cache \
        libpng \
        libjpeg-turbo \
        oniguruma \
        icu-libs \
        ca-certificates \
        tzdata \
        libldap

# Build dependencies required to compile PHP extensions.
RUN apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        libpng-dev \
        libjpeg-turbo-dev \
        oniguruma-dev \
        icu-dev \
        openldap-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql mysqli mbstring \
    && docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" gd \
    && docker-php-ext-install -j"$(nproc)" intl \
    && docker-php-ext-configure ldap --with-libdir=lib \
    && docker-php-ext-install -j"$(nproc)" ldap \
    && apk del .build-deps

# Run as a non-root user.
RUN addgroup -S -g 1000 app && adduser -S -u 1000 -G app app

WORKDIR /var/www/html

# Copy application code.
COPY --chown=app:app . /var/www/html

RUN mkdir -p /var/www/html/storage/logs /var/www/html/storage/cache \
    && chown -R app:app /var/www/html/storage

# Startup: run migrations and materialise the TLS certificate before php-fpm.
COPY docker/app-entrypoint.sh /usr/local/bin/app-entrypoint.sh
RUN chmod +x /usr/local/bin/app-entrypoint.sh

USER app

EXPOSE 9000

ENTRYPOINT ["/bin/sh", "/usr/local/bin/app-entrypoint.sh"]
CMD ["php-fpm"]
