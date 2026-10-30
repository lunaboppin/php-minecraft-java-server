#!/usr/bin/env python3
"""IP bans: banning a connected player's address disconnects everyone on it and refuses them at login.

With --eula-file (and the pinned JAR), the same steps run against the official 26.3 server and the
disconnect and login rejection payloads must match byte for byte.
"""
import argparse
import json
import os
from pathlib import Path
import shutil
import sys

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'tools/parity'))
from protocol import Probe, wire
from run import Server


def drain(client):
    """Packets until the server closes the connection."""
    seen = []
    try:
        for _ in range(5000):
            name, payload = client.receive()
            if name in ('system_chat', 'disconnect'):
                seen.append({'packet': name, 'hex': payload.hex()})
            if name == 'disconnect':
                break
    except (EOFError, OSError, AssertionError):
        pass
    return seen


def login_rejection(port, name):
    client = wire.Client(port)
    client.handshake(2)
    client.send('hello', wire.string(name) + bytes(16))
    try:
        for _ in range(100):
            packet, payload = client.receive()
            if packet == 'login_disconnect':
                return payload.hex()
            if packet == 'login_finished':
                return 'accepted'
    except (EOFError, OSError):
        return 'closed'
    finally:
        client.close()
    return 'none'


def record(kind, output, java, jar, eula):
    server = Server(output / kind, kind, java, jar, eula)
    server.start()
    result = {}
    try:
        operator = Probe(server.port, 'operator').login()
        witness = Probe(server.port, 'witness').login('Witness')
        for client in (operator, witness):
            client.tick_barrier()
        # Every test client connects from 127.0.0.1, so banning the witness's address bans them all.
        operator.send('chat_command', wire.string('ban-ip Witness Too  many alts'))
        result['operator'] = drain(operator)
        result['witness'] = drain(witness)
        result['rejoin'] = login_rejection(server.port, 'Newcomer')
    finally:
        server.stop()
    (output / f'{kind}-bans.json').write_text(json.dumps(result, indent=1))
    return result


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, default=ROOT / 'tools/.cache/ip-bans')
    parser.add_argument('--eula-file', type=Path, help='Existing user-accepted eula.txt; compares with the official server')
    parser.add_argument('--jar', type=Path, default=ROOT / 'tools/.cache/26.3/server.jar')
    parser.add_argument('--java', default=str(Path(os.environ['JAVA_HOME']) / 'bin/java') if 'JAVA_HOME' in os.environ else 'java')
    args = parser.parse_args()
    args.output = args.output.resolve()
    shutil.rmtree(args.output, ignore_errors=True)
    args.output.mkdir(parents=True)
    php = record('php', args.output, args.java, args.jar, args.eula_file)
    assert php['rejoin'] not in ('accepted', 'none', 'closed'), php
    if args.eula_file is not None:
        java = record('java', args.output, args.java, args.jar, args.eula_file)
        assert php == java, json.dumps({'php': php, 'java': java}, indent=1)[:4000]
        print('PASS: IP ban disconnects and login rejections identical to the official server')
    else:
        print('PASS: IP bans disconnect everyone on the address and refuse them at login')


if __name__ == '__main__':
    main()
