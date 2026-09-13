#!/bin/sh
set -eu

# Render Free web services provide neither a dashboard shell nor a pre-deploy
# command. Run forward-only, idempotent migrations before this single instance
# begins accepting traffic; `set -e` keeps the previous deployment live if a
# migration fails during startup.
php artisan migrate --force

php artisan package:discover --ansi
php artisan config:cache
php artisan route:cache

exec apache2-foreground
