#!/bin/sh
set -eu
memory_limit="${PHP_MEMORY_LIMIT:-}"
if [ "$memory_limit" != -1 ]; then case "${memory_limit%[KMG]}" in ''|0*|*[!0-9]*) echo 'PHP_MEMORY_LIMIT must be -1 or a positive size such as 2048M' >&2; exit 1;; esac; fi
if [ "${1:-server}" != server ]; then exec "$@"; fi
case "${SERVER_PORT:-25676}" in ''|*[!0-9]*) echo 'SERVER_PORT must be an integer' >&2; exit 1;; esac
if [ "${SERVER_PORT:-25676}" -lt 1 ] || [ "${SERVER_PORT:-25676}" -gt 65535 ]; then echo 'Invalid SERVER_PORT' >&2; exit 1; fi
config_file="${CONFIG_FILE:-/app/data/server.json}"
if [ ! -f "$config_file" ]; then php deploy/docker/configure.php "$config_file"; fi
exec php -d memory_limit="$memory_limit" -d date.timezone=UTC bin/server.php \
  --config="$config_file" --host=0.0.0.0 --port="${SERVER_PORT:-25676}"
