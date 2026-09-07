#!/bin/sh
set -eu

php artisan package:discover --ansi
php artisan config:cache
php artisan route:cache

exec apache2-foreground
