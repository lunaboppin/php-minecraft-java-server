#!/usr/bin/env python3
"""Format/check modular command PHP using a checksum-pinned, non-runtime tool."""
import argparse
import hashlib
from pathlib import Path
import subprocess
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
VERSION = '3.95.27'
SHA256 = '47231757eefdd08778e2bd047d98a63dfaad881006c8c1d1bc870d63be7f6a9b'
URL = f'https://github.com/PHP-CS-Fixer/PHP-CS-Fixer/releases/download/v{VERSION}/php-cs-fixer.phar'
DEFAULT_PATHS = [
    '.php-cs-fixer.dist.php',
    'src/Server/Commands.php',
    'src/Server/Commands',
    'tests/commands-unit.php',
]


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--check', action='store_true', help='Check formatting without editing files')
    parser.add_argument('paths', nargs='*', help='Optional PHP files/directories instead of command modules')
    args = parser.parse_args()
    cache = ROOT / 'tools/.cache' / f'php-cs-fixer-{VERSION}.phar'
    cache.parent.mkdir(parents=True, exist_ok=True)
    if not cache.is_file() or hashlib.sha256(cache.read_bytes()).hexdigest() != SHA256:
        temporary = cache.with_suffix('.download')
        urllib.request.urlretrieve(URL, temporary)
        if hashlib.sha256(temporary.read_bytes()).hexdigest() != SHA256:
            temporary.unlink()
            raise RuntimeError('PHP formatter checksum mismatch')
        temporary.replace(cache)

    command = [
        'php', str(cache), 'fix', '--config=.php-cs-fixer.dist.php',
        '--using-cache=no', '--diff', *(args.paths or DEFAULT_PATHS),
    ]
    if args.check:
        return subprocess.run([*command, '--dry-run'], cwd=ROOT).returncode

    # Expanding compressed legacy statements can reveal another spacing fix in
    # a subsequent pass. Check the result and only repeat if fixes remain.
    for _ in range(3):
        fixed = subprocess.run(command, cwd=ROOT)
        if fixed.returncode:
            return fixed.returncode
        checked = subprocess.run([*command, '--dry-run'], cwd=ROOT)
        if checked.returncode != 8:
            return checked.returncode
    return checked.returncode


if __name__ == '__main__':
    raise SystemExit(main())
