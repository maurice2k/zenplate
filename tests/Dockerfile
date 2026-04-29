ARG PHP_VERSION=8.1
FROM php:${PHP_VERSION}-cli-alpine

RUN apk add --no-cache git unzip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json ./
RUN composer install --no-interaction --no-progress --prefer-dist

COPY . .

CMD ["vendor/bin/phpunit", "-c", "tests/phpunit.xml", "--colors=always"]
