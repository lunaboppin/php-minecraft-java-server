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
exec "$php_binary" -n -d memory_limit=-1 -d date.timezone=UTC \
    bin/server.php --config=server.json --host=0.0.0.0 --port="$((10#$SERVER_PORT))"
