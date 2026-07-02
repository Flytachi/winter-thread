# Parameterized test image for winter-thread.
# Build:  docker build -f tests/docker/Dockerfile --build-arg PHP_VERSION=8.5 -t wt-test:8.5 .
# Provides: pcntl, posix, shmop, swoole (coroutine hooks), procps (ps / /proc).
ARG PHP_VERSION=8.4
FROM php:${PHP_VERSION}-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git unzip libssl-dev procps \
    && rm -rf /var/lib/apt/lists/*

# Core process/IPC extensions.
RUN docker-php-ext-install pcntl posix shmop

# Swoole with coroutine runtime hooks. Non-fatal if a given PHP build has no
# compatible Swoole release yet (the swoole-tagged tests will simply skip).
RUN pecl install swoole \
    && docker-php-ext-enable swoole \
    || echo "swoole unavailable for PHP ${PHP_VERSION}; swoole-group tests will skip"

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
