#!/bin/sh
set -eu
# This cache is per container, never baked with production secrets into the image.
php artisan config:cache
php artisan view:cache
exec docker-php-entrypoint "$@"
