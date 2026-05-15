#!/bin/sh
# Production entrypoint for the PHP-FPM container.
#
# Runs pending Doctrine migrations before handing off to php-fpm. If the DB is
# briefly unreachable (host MariaDB still starting after a server reboot, etc.)
# we retry. If migrations fail for a real reason after retries, we exit non-zero
# so the container restarts visibly rather than serving against a stale schema.
#
# Single-instance assumption: if you ever scale the PHP service to multiple
# replicas, move migrations out of the entrypoint and into a one-shot job —
# concurrent migrate runs from competing containers will race on the migrations
# table.

set -e
cd /app

attempt=1
max_attempts=15
while true; do
    if php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration; then
        break
    fi
    if [ "$attempt" -ge "$max_attempts" ]; then
        echo "[entrypoint] doctrine:migrations:migrate failed after $max_attempts attempts — aborting."
        exit 1
    fi
    echo "[entrypoint] Migration attempt $attempt/$max_attempts failed (DB not ready?), retrying in 2s..."
    attempt=$((attempt + 1))
    sleep 2
done

exec "$@"
