FROM php:8.4-cli-alpine

RUN apk add --no-cache git unzip icu-libs icu-data-full rabbitmq-c \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev oniguruma-dev linux-headers rabbitmq-c-dev \
    && docker-php-ext-install -j$(nproc) pdo_mysql intl opcache bcmath \
    && pecl install amqp \
    && docker-php-ext-enable amqp \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/tmp/composer

COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /app

EXPOSE 8000

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php", "-S", "0.0.0.0:8000", "-t", "public", "public/index.php"]
