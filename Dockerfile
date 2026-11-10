# syntax=docker/dockerfile:1
FROM composer:2 AS composer
FROM postgres:17-bookworm AS pgtools
RUN mkdir /pg-tools && cp /usr/lib/postgresql/17/bin/pg_dump /usr/lib/postgresql/17/bin/pg_restore /pg-tools/ && cp -L /usr/lib/x86_64-linux-gnu/libpq.so.5 /pg-tools/libpq.so.5
FROM php:8.4-cli-bookworm

# The optional secret supports managed cloud proxies without baking their CA in.
RUN --mount=type=secret,id=proxy_ca \
    set -eu; \
    if [ -f /run/secrets/proxy_ca ]; then cp /run/secrets/proxy_ca /usr/local/share/ca-certificates/build-proxy.crt; update-ca-certificates; fi; \
    apt-get update; \
    apt-get install -y --no-install-recommends libpq-dev libcurl4-openssl-dev libonig-dev libxml2-dev unzip git; \
    docker-php-ext-install -j2 pdo_pgsql curl mbstring dom xml xmlwriter; \
    rm -rf /var/lib/apt/lists/*; \
    if [ -f /usr/local/share/ca-certificates/build-proxy.crt ]; then rm /usr/local/share/ca-certificates/build-proxy.crt; update-ca-certificates --fresh; fi

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY --from=pgtools /pg-tools/pg_dump /pg-tools/pg_restore /usr/local/bin/
COPY --from=pgtools /pg-tools/libpq.so.5 /usr/lib/x86_64-linux-gnu/libpq.so.5
RUN docker-php-ext-install -j2 pcntl
RUN pg_dump --version && pg_restore --version
WORKDIR /app
ENV COMPOSER_HOME=/tmp/listenlab-composer
CMD ["php", "app", "list"]
