# syntax=docker/dockerfile:1
ARG PHP_IMAGE=php:8.4-cli-trixie
FROM composer:2.11.2 AS composer
FROM postgres:17-trixie AS pgtools
RUN mkdir /pg-tools && cp /usr/lib/postgresql/17/bin/pg_dump /usr/lib/postgresql/17/bin/pg_restore /pg-tools/ && cp -L /usr/lib/x86_64-linux-gnu/libpq.so.5 /pg-tools/libpq.so.5

FROM ${PHP_IMAGE} AS extensions
RUN --mount=type=secret,id=proxy_ca \
    set -eu; \
    if [ -f /run/secrets/proxy_ca ]; then cp /run/secrets/proxy_ca /usr/local/share/ca-certificates/build-proxy.crt; update-ca-certificates; fi; \
    apt-get update; \
    apt-get install -y --no-install-recommends libpq-dev libcurl4-openssl-dev libonig-dev libxml2-dev; \
    docker-php-ext-install -j2 pdo_pgsql curl mbstring dom xml xmlwriter pcntl

FROM ${PHP_IMAGE} AS runtime
RUN --mount=type=secret,id=proxy_ca \
    set -eu; \
    if [ -f /run/secrets/proxy_ca ]; then cp /run/secrets/proxy_ca /usr/local/share/ca-certificates/build-proxy.crt; update-ca-certificates; fi; \
    apt-get update; \
    apt-get upgrade -y --no-install-recommends; \
    apt-get install -y --no-install-recommends libpq5 libcurl4t64 libonig5 libxml2 libsqlite3-0 liblz4-1 libzstd1 ca-certificates; \
    apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false $PHPIZE_DEPS; \
    rm -rf /var/lib/apt/lists/*; \
    if [ -f /usr/local/share/ca-certificates/build-proxy.crt ]; then rm /usr/local/share/ca-certificates/build-proxy.crt; update-ca-certificates --fresh; fi
COPY --from=extensions /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=extensions /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/
WORKDIR /app

FROM runtime AS monitor
RUN php -r 'exit(extension_loaded("pdo_pgsql") ? 0 : 1);'
CMD ["php", "-S", "0.0.0.0:8787", "-t", "monitor/public", "monitor/router.php"]

FROM runtime AS app
RUN --mount=type=secret,id=proxy_ca \
    set -eu; \
    if [ -f /run/secrets/proxy_ca ]; then cp /run/secrets/proxy_ca /usr/local/share/ca-certificates/build-proxy.crt; update-ca-certificates; fi; \
    apt-get update; apt-get install -y --no-install-recommends unzip; rm -rf /var/lib/apt/lists/*; \
    if [ -f /usr/local/share/ca-certificates/build-proxy.crt ]; then rm /usr/local/share/ca-certificates/build-proxy.crt; update-ca-certificates --fresh; fi
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY --from=pgtools /pg-tools/pg_dump /pg-tools/pg_restore /usr/local/bin/
COPY --from=pgtools /pg-tools/libpq.so.5 /usr/lib/x86_64-linux-gnu/libpq.so.5
RUN pg_dump --version && pg_restore --version
ENV COMPOSER_HOME=/tmp/listenlab-composer
CMD ["php", "app", "list"]
