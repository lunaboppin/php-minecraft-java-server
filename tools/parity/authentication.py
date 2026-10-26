#!/usr/bin/env python3
"""Compare encrypted rejection of an unjoined session. No account credentials are used."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import shutil
import time

from run import Server, ROOT


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--eula-file', type=Path, required=True)
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--jar', type=Path, default=ROOT / 'tools/.cache/26.3/server.jar')
    parser.add_argument('--attempts', type=int, default=3, help='Bounded attempts for explicit service-unavailable responses only')
    args = parser.parse_args()
    if not 1 <= args.attempts <= 5:
        parser.error('--attempts must be between 1 and 5')
    expected = json.loads((ROOT / 'reference/26.3/version.json').read_text())['server_sha1']
    if not args.jar.is_file() or hashlib.sha1(args.jar.read_bytes()).hexdigest() != expected:
        parser.error('Reference JAR checksum mismatch or missing')
    if not args.eula_file.is_file() or not re.search(r'^\s*eula\s*=\s*true\s*$', args.eula_file.read_text(), re.M | re.I):
        parser.error('Supply an existing accepted Minecraft EULA file')
    output = args.output.resolve()
    output.mkdir(parents=True, exist_ok=False)
    java = str(Path(os.environ['JAVA_HOME']) / 'bin/java') if os.environ.get('JAVA_HOME') else 'java'
    outcomes = {}
    for kind in ['php', 'java']:
        server = Server(output / kind, kind, java, args.jar.resolve(), args.eula_file.resolve())
        if kind == 'php':
            config = server.path / 'server.json'
            data = json.loads(config.read_text())
            data['onlineMode'] = True
            config.write_text(json.dumps(data))
        else:
            properties = server.path / 'server.properties'
            properties.write_text(properties.read_text().replace('online-mode=false', 'online-mode=true'))
        try:
            server.start()
            attempts = []
            outcomes[kind] = {'attempts': attempts}
            for attempt in range(1, args.attempts + 1):
                capture = output / f'{kind}-attempt-{attempt}-packets.json'
                probe = subprocess.run([
                    'php', str(ROOT / 'tests/support/auth-rejection-peer.php'),
                    str(server.port), str(capture),
                ])
                attempts.append({'probe_exit': probe.returncode, 'capture': capture.name if capture.exists() else None})
                outcomes[kind]['probe_exit'] = probe.returncode
                if probe.returncode != 75 or attempt == args.attempts:
                    if capture.exists():
                        shutil.copyfile(capture, output / (kind + '-packets.json'))
                    break
                time.sleep(min(attempt * 2, 5))

        except (OSError, RuntimeError, TimeoutError) as error:
            outcomes.setdefault(kind, {}).update(error=type(error).__name__, detail=str(error))
        finally:
            try:
                server.stop()
            except (OSError, RuntimeError, TimeoutError) as error:
                outcomes.setdefault(kind, {})['shutdown_error'] = str(error)
    passed = len(outcomes) == 2 and all(outcome.get('probe_exit') == 0 and 'error' not in outcome and 'shutdown_error' not in outcome for outcome in outcomes.values())
    (output / 'report.json').write_text(json.dumps({
        'passed': passed,
        'outcomes': outcomes,
        'observations': ['both servers explicitly reject an unjoined session after RSA exchange over AES/CFB8'] if passed else [],
        'limits': ['no verified real-account login', 'does not assert identical error wording or packet ordering', 'explicit service-unavailable responses retry within a fixed limit; every attempt is retained'],
    }, indent=2) + '\n')
    if not passed:
        raise SystemExit('Authentication comparison failed; both server outcomes and available captures are preserved in ' + str(output))


if __name__ == '__main__':
    main()
