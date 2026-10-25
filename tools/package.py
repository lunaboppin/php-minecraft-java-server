#!/usr/bin/env python3
"""Build a runnable distribution without Java caches, logs, or transient SQLite WAL files."""
from pathlib import Path
import json
import sqlite3
import subprocess
import tempfile
import zipfile

ROOT = Path(__file__).resolve().parents[1]
OUTPUT = ROOT / 'dist/minecraft-26.3-php-server.zip'
PREFIX = 'minecraft-26.3-php-server/'


def main():
    OUTPUT.parent.mkdir(exist_ok=True)
    files = [ROOT / name for name in ['README.md', 'ROADMAP.md', 'NOTICE.md', 'composer.json', 'server.json',
                                     'server.example.json', 'server-flat.example.json', '.gitignore']]
    for directory in ['src', 'bin', 'reference', 'tests', 'tools', 'docs', 'deploy']:
        files.extend(path for path in (ROOT / directory).rglob('*') if path.is_file()
                     and not any(part in ('.cache', '__pycache__') for part in path.relative_to(ROOT).parts)
                     and path.suffix != '.pyc')
    with tempfile.TemporaryDirectory(prefix='php-minecraft-distribution-') as temporary:
        temporary = Path(temporary)
        with zipfile.ZipFile(OUTPUT, 'w', zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
            for path in sorted(files):
                if path.exists():
                    archive.write(path, PREFIX + str(path.relative_to(ROOT)))
            if not (ROOT / 'server.json').exists():
                archive.write(ROOT / 'server.example.json', PREFIX + 'server.json')
            for path in sorted((ROOT / 'data').glob('*.sqlite')):
                backup = temporary / path.name
                with sqlite3.connect(path) as source, sqlite3.connect(backup) as target:
                    source.backup(target)
                archive.write(backup, PREFIX + 'data/' + path.name)
            archive.writestr(PREFIX + 'data/.gitkeep', '')
            launcher = zipfile.ZipInfo(PREFIX + 'start.sh')
            launcher.external_attr = 0o100755 << 16
            archive.writestr(launcher, '#!/bin/sh\ncd -- "$(dirname -- "$0")" || exit 1\nexec php bin/server.php --config=server.json\n')
            archive.writestr(PREFIX + 'start.bat', '@echo off\r\ncd /d "%~dp0"\r\nphp bin\\server.php --config=server.json\r\npause\r\n')
            archive.writestr(PREFIX + 'START-HERE.txt',
                             'Minecraft Java 26.3 PHP server\n\nRequires 64-bit PHP 8.5.12+ CLI with JSON, zlib, iconv, PDO and PDO SQLite.\n'
                             'Run start.sh, start.bat, or:\n  php bin/server.php --config=server.json\n\n'
                             'The included server.json sets the TCP port and world. See README.md for features, operator settings and limitations.\n')
        with zipfile.ZipFile(OUTPUT) as archive:
            if archive.testzip() is not None:
                raise RuntimeError('ZIP integrity check failed')
            archive.extractall(temporary / 'verify')
            extracted = temporary / 'verify' / PREFIX
            subprocess.run(['php', 'tests/run.php', '--unit'], cwd=extracted, check=True)
            print(f'ZIP verified: {len(archive.namelist())} entries, {OUTPUT.stat().st_size:,} bytes')
    print(OUTPUT)


if __name__ == '__main__':
    main()
