#!/bin/bash
# Embedded verbatim in the importable egg; no existing server files are needed.
set -Eeuo pipefail
export DEBIAN_FRONTEND=noninteractive
SERVER_REF="${SERVER_REF:-main}"
BUILD_JOBS="${BUILD_JOBS:-2}"
if [[ ! "$SERVER_REF" =~ ^[A-Za-z0-9][A-Za-z0-9._/-]{0,127}$ || "$SERVER_REF" == *..* ]]; then
    echo 'Invalid SERVER_REF: use a GitHub branch, tag or commit SHA.' >&2; exit 1
fi
if [[ ! "$BUILD_JOBS" =~ ^[1-4]$ ]]; then
    echo 'BUILD_JOBS must be between 1 and 4.' >&2; exit 1
fi
if [[ "$(getconf LONG_BIT)" != 64 ]]; then
    echo 'A 64-bit node is required.' >&2; exit 1
fi
mkdir -p /mnt/server
work="$(mktemp -d /tmp/php-minecraft-install.XXXXXXXX)"
cleanup() {
    result=$?
    if (( result != 0 )); then
        echo 'Installation failed. Existing data/ and server.json have been retained.' >&2
        if [[ -f "$work/build.log" ]]; then tail -40 "$work/build.log" >&2; fi
    fi
    rm -rf -- "$work"
}
trap cleanup EXIT
apt-get update
apt-get install -y --no-install-recommends ca-certificates curl python3 xz-utils tar
fetch() {
    curl --fail --location --silent --show-error --retry 3 --connect-timeout 20 --max-time 300 \
        --proto '=https' --proto-redir '=https' --output "$2" "$1"
}
echo "Downloading PHP Minecraft Java Server (${SERVER_REF}) from GitHub CDN..."
encoded_ref="$(python3 -c 'import sys,urllib.parse;print(urllib.parse.quote(sys.argv[1],safe=""))' "$SERVER_REF")"
fetch "https://codeload.github.com/lunaboppin/php-minecraft-java-server/tar.gz/${encoded_ref}" "$work/server.tar.gz"
mkdir "$work/app"
tar --extract --gzip --file "$work/server.tar.gz" --strip-components=1 --directory "$work/app" --no-same-owner
for required in bin/server.php src/bootstrap.php server.example.json reference/26.3/checksums.json deploy/panel/start.sh deploy/panel/configure.php; do
    test -f "$work/app/$required" || { echo "Missing server file: $required" >&2; exit 1; }
done
# Validate the bundled official reference data before replacing application files.
python3 - "$work/app" <<'PY'
import hashlib,json,sys
from pathlib import Path
root=Path(sys.argv[1])/'reference/26.3'
for name,expected in json.loads((root/'checksums.json').read_text()).items():
    path=root/name
    if path.resolve().is_relative_to(root.resolve()) is False:
        raise SystemExit('Invalid reference path')
    if hashlib.sha256(path.read_bytes()).hexdigest()!=expected:
        raise SystemExit('Reference checksum mismatch: '+name)
PY
# The maintained 8.5 branch is resolved on each install/reinstall; never trust a hardcoded mirror hash.
fetch 'https://www.php.net/releases/index.php?json&version=8.5' "$work/php-release.json"
python3 - "$work/php-release.json" "$work/php-selected.json" <<'PY'
import json,re,sys
from pathlib import Path
release=json.loads(Path(sys.argv[1]).read_text())
version=release.get('version','')
if not re.fullmatch(r'8\.5\.\d+',version) or tuple(map(int,version.split('.'))) < (8,5,12):
    raise SystemExit('PHP release feed did not provide stable PHP 8.5.12 or newer')
source=next((s for s in release.get('source',[]) if s.get('filename')==f'php-{version}.tar.xz'),None)
if source is None or not re.fullmatch(r'[a-fA-F0-9]{64}',source.get('sha256','')):
    raise SystemExit('Missing official PHP source checksum')
Path(sys.argv[2]).write_text(json.dumps({'version':version,'filename':source['filename'],'sha256':source['sha256'].lower()}))
PY
php_version="$(python3 -c 'import json,sys;print(json.load(open(sys.argv[1]))["version"])' "$work/php-selected.json")"
verify_php() {
    "$1" -n -r '
        if (PHP_VERSION_ID < 80512 || PHP_INT_SIZE !== 8) { exit(1); }
        foreach (["json","zlib","iconv","PDO","pdo_sqlite","filter","pcntl","openssl","curl"] as $extension) {
            if (!extension_loaded($extension)) { fwrite(STDERR,"Missing PHP extension: ".$extension."\n"); exit(1); }
        }
        $db=new PDO("sqlite::memory:"); $db->exec("CREATE TABLE test(id INTEGER)");
        if (!function_exists("pcntl_async_signals")) { exit(1); }
    '
}
php_binary=/mnt/server/.runtime/php/bin/php
if [[ -x "$php_binary" ]] && verify_php "$php_binary" && [[ "$("$php_binary" -n -r 'echo PHP_VERSION;')" == "$php_version" ]]; then
    echo "Reusing verified PHP ${php_version}."
else
    echo "Building PHP ${php_version} with SQLite, zlib, iconv, filter and signal handling (${BUILD_JOBS} jobs)."
    echo 'The first installation can take several minutes.'
    apt-get install -y --no-install-recommends build-essential pkg-config libsqlite3-dev zlib1g-dev libssl-dev libcurl4-openssl-dev
    filename="php-${php_version}.tar.xz"
    fetch "https://www.php.net/distributions/${filename}" "$work/$filename"
    python3 - "$work/php-selected.json" "$work/$filename" <<'PY'
import hashlib,json,sys
from pathlib import Path
expected=json.loads(Path(sys.argv[1]).read_text())['sha256']
if hashlib.sha256(Path(sys.argv[2]).read_bytes()).hexdigest()!=expected:
    raise SystemExit('Official PHP source SHA-256 verification failed')
PY
    mkdir "$work/php-source"
    tar --extract --xz --file "$work/$filename" --strip-components=1 --directory "$work/php-source" --no-same-owner
    (
        cd "$work/php-source"
        ./configure --prefix=/home/container/.runtime/php \
            --with-config-file-path=/home/container/.runtime/php \
            --with-config-file-scan-dir=/home/container/.runtime/php/conf.d \
            --disable-all --enable-cli --disable-cgi --disable-phpdbg --without-pear \
            --disable-opcache-jit --enable-filter --enable-ctype --enable-pdo \
            --with-pdo-sqlite --with-sqlite3 --with-zlib --with-iconv --enable-pcntl --with-openssl --with-curl
        make -j "$BUILD_JOBS"
    ) > "$work/build.log" 2>&1
    verify_php "$work/php-source/sapi/cli/php"
    mkdir -p /mnt/server/.runtime/php/bin
    # Rename a complete executable into place; failed builds leave the old runtime intact.
    install -m 0755 "$work/php-source/sapi/cli/php" "${php_binary}.new"
    mv -f -- "${php_binary}.new" "$php_binary"
    install -m 0644 "$work/php-source/LICENSE" /mnt/server/.runtime/php/LICENSE
fi
cp "$work/php-selected.json" /mnt/server/.runtime/php/release.json
# Only application files are updated. No archive data/ or server.json is copied.
for directory in bin src reference deploy tools tests docs; do
    if [[ -d "$work/app/$directory" ]]; then cp -a "$work/app/$directory" /mnt/server/; fi
done
for file in README.md NOTICE.md composer.json server.example.json server-flat.example.json; do
    cp "$work/app/$file" /mnt/server/
done
if [[ -f "$work/app/ROADMAP.md" ]]; then cp "$work/app/ROADMAP.md" /mnt/server/; fi
mkdir -p /mnt/server/data
chmod +x /mnt/server/deploy/panel/start.sh
cd /mnt/server
"$php_binary" -n deploy/panel/configure.php
"$php_binary" -n -r 'require "src/bootstrap.php"; echo "Installed PHP ".PHP_VERSION." with all required extensions.\n";'
python3 - "$SERVER_REF" "$work/server.tar.gz" <<'PY'
import hashlib,json,sys
from pathlib import Path
Path('.runtime/server-install.json').write_text(json.dumps({
    'repository':'lunaboppin/php-minecraft-java-server','ref':sys.argv[1],
    'archive_sha256':hashlib.sha256(Path(sys.argv[2]).read_bytes()).hexdigest()
},indent=2)+'\n')
PY
echo 'Installation complete. Start the server using its primary panel allocation.'
