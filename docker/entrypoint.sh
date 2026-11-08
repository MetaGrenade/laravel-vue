#!/bin/sh
# Runs first in every container of the site: the web server, the queue worker, the scheduler and the one-off commands.
set -eu

cd /app

# Without a key the site cannot start, and the message it would give is one deep in a stack trace. Making a key is the one command
# that has to run without one.
if [ -z "${APP_KEY:-}" ]; then
    case "$*" in
        *key:generate*) ;;
        *)
            echo "APP_KEY is empty. Make one with:  docker compose -f compose.production.yaml run --rm --no-deps app php artisan key:generate --show" >&2
            echo "and put it in .env as APP_KEY=base64:... (docs/docker.md)." >&2
            exit 1
            ;;
    esac
fi

# The config, route, event and view caches, built from the settings this container was started with. With the config cached .env is
# not read, so a changed setting needs the container started again.
case "$*" in
    *key:generate*|*optimize*|*config:*|*route:*|*view:*|*event:*) ;;
    *) php artisan optimize --no-interaction >/dev/null ;;
esac

exec docker-php-entrypoint "$@"
