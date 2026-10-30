#!/bin/sh
# Backend container entrypoint: apply pending migrations, then hand off to the
# base image's entrypoint (docker-php-entrypoint) with the original command.
#
# - Opt out with AUTO_MIGRATE=false (e.g. to run migrations by hand).
# - Migrations only run for the web server command (the default CMD); a container
#   started from this image with another command (queue worker, scheduler,
#   `php artisan ...`) skips them so several containers don't race on deploy.
# - Waits up to ~30s for the database; if migrations still fail the container
#   exits instead of serving against an out-of-date schema.
set -e

is_server_command() {
    # Same rule as docker-php-entrypoint: options only => `frankenphp run <options>`.
    [ "$#" -eq 0 ] || [ "${1#-}" != "$1" ] || [ "$1" = "frankenphp" ]
}

case "$(printf '%s' "${AUTO_MIGRATE:-true}" | tr '[:upper:]' '[:lower:]')" in
    false | 0 | no | off) run_migrations=0 ;;
    *) run_migrations=1 ;;
esac

if [ "$run_migrations" = 1 ] && is_server_command "$@"; then
    attempts=10
    i=1
    until php artisan migrate --force --no-interaction; do
        if [ "$i" -ge "$attempts" ]; then
            echo "entrypoint: migrations failed after $attempts attempts, giving up" >&2
            exit 1
        fi
        echo "entrypoint: migrate failed (database not ready?), retrying in 3s ($i/$attempts)" >&2
        i=$((i + 1))
        sleep 3
    done
fi

exec docker-php-entrypoint "$@"
