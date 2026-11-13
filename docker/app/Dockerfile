# Jogger server image: web app, queue workers (Horizon), scheduler and Reverb all run from it, chosen by command.
#   docker build -f docker/app/Dockerfile -t jogger .

FROM --platform=$BUILDPLATFORM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --optimize --no-dev --no-scripts

FROM --platform=$BUILDPLATFORM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
# Tailwind scans the views of installed packages too.
COPY --from=vendor /app/vendor ./vendor
RUN npm run build

FROM dunglas/frankenphp:1-php8.4
ARG VERSION=dev
ENV JOGGER_VERSION=${VERSION}
RUN install-php-extensions pdo_pgsql redis pcntl intl zip bcmath opcache \
 && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
 && printf 'opcache.enable_cli=1\nopcache.validate_timestamps=0\nmemory_limit=256M\nexpose_php=0\n' > "$PHP_INI_DIR/conf.d/jogger.ini" \
 && useradd --uid 1000 --create-home --shell /usr/sbin/nologin jogger

WORKDIR /var/www/html
COPY --chown=jogger:jogger . .
COPY --chown=jogger:jogger --from=vendor /app/vendor ./vendor
COPY --chown=jogger:jogger --from=assets /app/public/build ./public/build
COPY docker/app/entrypoint.sh /usr/local/bin/jogger-entrypoint
RUN chmod +x /usr/local/bin/jogger-entrypoint \
 && rm -rf node_modules tests agent docker .git \
 && mkdir -p storage/app/logs storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache /data /config \
 && chown -R jogger:jogger storage bootstrap/cache /data /config

USER jogger
ENV APP_ENV=production APP_DEBUG=false LOG_CHANNEL=stderr XDG_DATA_HOME=/data XDG_CONFIG_HOME=/config
EXPOSE 8000 8080
HEALTHCHECK --interval=15s --timeout=3s --start-period=60s --retries=5 CMD ["jogger-entrypoint", "healthcheck"]
ENTRYPOINT ["jogger-entrypoint"]
CMD ["app"]
