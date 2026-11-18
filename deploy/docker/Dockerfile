# syntax=docker/dockerfile:1
#
# One image for every deployment (Railway, any container host, on-prem).
# Build from the repository root:
#   docker build -f deploy/docker/Dockerfile -t hr-workforce .
#
# Behaviour is chosen at runtime with environment variables
# (CONTAINER_ROLE, RUN_MIGRATIONS, DB_*, ...). See docs/deployment.md.

# ---------- 1. Frontend (React SPA) ----------
FROM node:22-alpine AS frontend
WORKDIR /build/frontend
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY frontend/ ./
# vite.config.ts writes the build to ../backend/public/app
RUN npm run build

# ---------- 2. PHP dependencies ----------
FROM composer:2 AS vendor
WORKDIR /app
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress
COPY backend/ ./
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative --no-scripts

# ---------- 3. Runtime ----------
FROM dunglas/frankenphp:1-php8.3 AS runtime

RUN install-php-extensions pdo_mysql intl opcache pcntl zip

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    LOG_STDERR_FORMATTER="Monolog\\Formatter\\JsonFormatter" \
    CONTAINER_ROLE=web \
    RUN_MIGRATIONS=false \
    PORT=8080

WORKDIR /app

COPY --from=vendor /app /app
COPY --from=frontend /build/backend/public/app /app/public/app
COPY deploy/docker/Caddyfile /etc/frankenphp/Caddyfile
COPY deploy/docker/php.ini $PHP_INI_DIR/conf.d/zz-app.ini
COPY deploy/docker/entrypoint.sh /usr/local/bin/entrypoint

# Run as an unprivileged user (port 8080 needs no extra capabilities).
RUN chmod +x /usr/local/bin/entrypoint \
    && rm -rf /app/tests /app/public/hot \
    && mkdir -p /app/storage/app /app/storage/framework/cache /app/storage/framework/sessions /app/storage/framework/views /app/storage/logs /app/bootstrap/cache \
    && setcap -r /usr/local/bin/frankenphp || true \
    && chown -R www-data:www-data /app/storage /app/bootstrap/cache /data/caddy /config/caddy

USER www-data

EXPOSE 8080

# Only roles that serve HTTP have something to probe.
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD if [ "$CONTAINER_ROLE" = "web" ] || [ "$CONTAINER_ROLE" = "all" ]; then \
          php -r 'exit(@file_get_contents("http://127.0.0.1:".getenv("PORT")."/up") === false ? 1 : 0);'; \
        else exit 0; fi

ENTRYPOINT ["entrypoint"]
