#!/bin/bash
set -Eeuo pipefail
cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.."
if [[ ! "${SERVER_PORT:-}" =~ ^[0-9]{1,5}$ ]] || (( 10#$SERVER_PORT < 1 || 10#$SERVER_PORT > 65535 )); then
    echo 'SERVER_PORT must be the primary panel allocation (1-65535).' >&2; exit 1
fi
php_binary="./.runtime/php/bin/php"
if [[ ! -x "$php_binary" ]]; then
    echo 'The panel PHP runtime is missing. Run Reinstall to install it.' >&2; exit 1
fi
# -n prevents host/user php.ini settings from loading extensions or stale binary paths.
"$php_binary" -n deploy/panel/configure.php
# Wings supplies the allocation in MiB (0 = unlimited); keep PHP below it so exhaustion is a PHP error, not an OOM kill.
if [[ -n "${PHP_MEMORY_LIMIT:-}" ]]; then
    memory_limit="$PHP_MEMORY_LIMIT"
    if [[ "$memory_limit" != -1 && ! "$memory_limit" =~ ^[1-9][0-9]*[KMG]?$ ]]; then
        echo 'PHP_MEMORY_LIMIT must be -1 or a positive size such as 2048M.' >&2; exit 1
    fi
elif [[ "${SERVER_MEMORY:-0}" =~ ^[0-9]+$ ]] && (( 10#${SERVER_MEMORY:-0} > 0 )); then
    memory_limit="$(( 10#$SERVER_MEMORY * 3 / 4 ))M"
else
    memory_limit=-1
fi
echo "PHP memory limit: ${memory_limit}"
exec "$php_binary" -n -d memory_limit="$memory_limit" -d date.timezone=UTC \
    bin/server.php --config=server.json --host=0.0.0.0 --port="$((10#$SERVER_PORT))"
