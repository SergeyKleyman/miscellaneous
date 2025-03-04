FROM php:8.1-cli

RUN apt-get -qq update && apt-get -qq -y --no-install-recommends install \
        curl \
        libzip-dev

ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN chmod +x /usr/local/bin/install-php-extensions
RUN install-php-extensions \
        opentelemetry \
        zip

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

ADD . /app
WORKDIR /app

RUN composer install

ENV OTEL_PHP_AUTOLOAD_ENABLED=true

EXPOSE 8080

ENTRYPOINT /app/docker_entrypoint.sh
