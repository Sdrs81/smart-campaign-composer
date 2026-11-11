# syntax=docker/dockerfile:1
# Plain PHP built-in server with parallel workers; no framework.
FROM php:8.4-cli-alpine

COPY --from=ghcr.io/mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions redis

WORKDIR /app
COPY mock-provider/ ./

# 16 workers so the provider is never the bottleneck at the 100/s channel limit.
ENV PHP_CLI_SERVER_WORKERS=16

EXPOSE 8080
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public", "public/index.php"]
