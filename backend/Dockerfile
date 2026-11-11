# syntax=docker/dockerfile:1
# Build context is the repository root, because the entrypoint lives in docker/:
#   docker build -f backend/Dockerfile .
# FrankenPHP in classic mode (no Octane): every request boots the app, so there is
# no state leaking between requests and SSE streams are not buffered.
FROM dunglas/frankenphp:1-php8.4

# intl: grapheme_strlen counts visible characters; pcntl: queue:work signals;
# redis + pdo_pgsql: queue/rate limiter and database; zip: Composer unpacks dist archives.
RUN install-php-extensions pdo_pgsql redis pcntl intl opcache zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Plain HTTP inside the compose network; the image would otherwise start HTTPS.
ENV SERVER_NAME=:80 \
    COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /app

# Dev dependencies stay installed: `make test` and `make lint` run inside this image.
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-interaction --no-scripts --no-autoloader --prefer-dist

COPY backend/ ./
# The directories come first: git keeps no empty folders, so a fresh clone has no bootstrap/cache, and the
# package:discover step of dump-autoload fails without it.
RUN mkdir -p storage/logs storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache \
    && composer dump-autoload --optimize --no-interaction \
    && chmod -R a+rwX storage bootstrap/cache

# Empty on purpose: settings come from the container environment, and a missing file
# makes dotenv emit a warning that Pest prints on every test.
RUN touch .env

COPY docker/backend/entrypoint.sh /usr/local/bin/campaign-entrypoint
RUN chmod +x /usr/local/bin/campaign-entrypoint

EXPOSE 80

ENTRYPOINT ["campaign-entrypoint"]
CMD ["--config", "/etc/caddy/Caddyfile", "--adapter", "caddyfile"]
