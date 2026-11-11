# syntax=docker/dockerfile:1
FROM golang:1.27.4-bookworm AS gosu
ARG GOSU_COMMIT=29b939aa5bd4dc588af765b8248f6e39150d2565
WORKDIR /src
RUN git init && git remote add origin https://github.com/tianon/gosu.git \
    && git fetch --depth=1 origin "${GOSU_COMMIT}" && git checkout --detach FETCH_HEAD \
    && go get golang.org/x/sys@v0.44.0 \
    && CGO_ENABLED=0 go build -trimpath -ldflags='-s -w' -o /out/gosu . \
    && /out/gosu --version && /out/gosu nobody id

# Keep PostgreSQL 17 and the existing Debian/locale to preserve volume compatibility.
FROM postgres:17-bookworm AS database
# Remove the superseded file before copying the verified replacement.
RUN rm /usr/local/bin/gosu
COPY --from=gosu /out/gosu /usr/local/bin/gosu
RUN gosu --version && gosu postgres id
