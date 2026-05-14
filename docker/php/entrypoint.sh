#!/bin/sh
set -e

if [ ! -f vendor/autoload.php ]; then
    echo "[entrypoint] Installing PHP dependencies..."
    composer install --no-interaction --prefer-dist
fi

exec "$@"
