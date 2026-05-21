# Recipe Machine — v1.0 image.
#
# Stage 1: build the Vite asset bundle. We don't ship npm into the
# final runtime — only the built public/build/ output is needed.
FROM node:20-alpine AS assets
WORKDIR /build
COPY package.json package-lock.json* ./
RUN npm install --no-audit --no-fund
COPY resources/ resources/
COPY tailwind.config.js postcss.config.js vite.config.js ./
COPY public/ public/
RUN npm run build

# Stage 2: PHP runtime.
FROM php:8.3-cli

# Node is needed at RUNTIME (not just build time) so the PHP↔JS
# ingredient-formatter parity test can spawn Node from PHPUnit. Without
# Node on PATH the parity test skips silently, which would hide real
# formatter divergences.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libsqlite3-dev \
        libzip-dev \
        nodejs \
        zip \
    && docker-php-ext-install pdo pdo_sqlite zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . /var/www/html
# Bring in the freshly-built Vite manifest + bundle from stage 1.
COPY --from=assets /build/public/build /var/www/html/public/build

RUN composer install --no-interaction --prefer-dist --no-dev --optimize-autoloader \
    && mkdir -p database storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && touch database/database.sqlite \
    && chmod -R ug+rw storage bootstrap/cache database \
    && chmod +x docker-entrypoint.sh

EXPOSE 8000

ENTRYPOINT ["/var/www/html/docker-entrypoint.sh"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
