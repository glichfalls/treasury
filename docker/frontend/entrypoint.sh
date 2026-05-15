#!/bin/sh
set -e

# Install on first boot. Re-install when package.json or package-lock.json
# are newer than the current install (so adding a dep + restarting picks it
# up without needing a manual `docker compose exec frontend npm install`).
needs_install=0
if [ ! -d node_modules/vue ]; then
    needs_install=1
elif [ package.json -nt node_modules/.install-stamp ] || [ package-lock.json -nt node_modules/.install-stamp ]; then
    needs_install=1
fi

if [ "$needs_install" = "1" ]; then
    echo "[entrypoint] Installing frontend dependencies..."
    npm install
    touch node_modules/.install-stamp
fi

exec "$@"
