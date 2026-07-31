#!/bin/sh
set -e
cd /app

[ -d vendor ] || composer install --no-interaction --prefer-dist
[ -f .env ] || cp .env.example .env
grep -q '^APP_KEY=base64' .env || php artisan key:generate --force

php artisan config:clear >/dev/null 2>&1 || true

exec php artisan test "$@"