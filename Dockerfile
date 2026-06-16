FROM webdevops/php-nginx:8.4-alpine

WORKDIR /app

RUN docker-php-ext-configure exif \
    && docker-php-ext-install exif

ENV WEB_DOCUMENT_ROOT=/app/public
ENV APP_ENV=production

COPY --chown=application:application . .
COPY --chown=application:application .docker/prod.supervisord.conf /opt/docker/etc/supervisor.d/jobs.conf

USER application

RUN mkdir -p /app/storage/logs || exit 0

RUN composer install --no-interaction
# RUN composer install --no-dev --no-interaction --optimize-autoloader

RUN php artisan storage:link
