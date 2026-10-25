#!/bin/sh
set -eu
if [ "${1:-server}" != server ]; then exec "$@"; fi
case "${SERVER_PORT:-25676}" in ''|*[!0-9]*) echo 'SERVER_PORT must be an integer' >&2; exit 1;; esac
if [ "${SERVER_PORT:-25676}" -lt 1 ] || [ "${SERVER_PORT:-25676}" -gt 65535 ]; then echo 'Invalid SERVER_PORT' >&2; exit 1; fi
config_file="${CONFIG_FILE:-/app/data/server.json}"
if [ ! -f "$config_file" ]; then php deploy/docker/configure.php "$config_file"; fi
exec php -d memory_limit=512M -d date.timezone=UTC bin/server.php \
  --config="$config_file" --host=0.0.0.0 --port="${SERVER_PORT:-25676}"
