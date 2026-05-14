#!/bin/sh
set -e

if [ ! -d node_modules/.vite ] && [ ! -d node_modules/vue ]; then
    echo "[entrypoint] Installing frontend dependencies..."
    npm install
fi

exec "$@"
