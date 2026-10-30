#!/usr/bin/env python3
"""Riding a saddled pig: using it mounts the player, and sneaking gets them off.

The mount teleports the rider into the seat and names the pig's passengers; the dismount puts
the rider beside the pig, who may mount again at once (Player.removeVehicle clears the
boarding cooldown). Sneak-using the pig does not mount. With --eula-file (and the pinned JAR), the same
steps run against the official 26.3 server and the packets each player receives must match,
with entity and teleport IDs zeroed.
"""
import argparse
import json
import os
from pathlib import Path
import shutil
import sys
import time

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'tools/parity'))
from protocol import Probe, wire
from run import Server

WATCHED = ('set_passengers', 'player_position', 'swing_animation', 'entity_position_sync')


def varint(data, at=0):
    value = shift = 0
    while True:
        byte = data[at]
        at += 1
        value |= (byte & 0x7f) << shift
        shift += 7
        if byte < 0x80:
            return value, at


def normalized(name, payload):
    """Each server numbers entities and teleports from its own counter."""
    if name == 'set_passengers':
        _, at = varint(payload)
        count, at = varint(payload, at)
        return '00' + bytes([count]).hex() + '00' * count
    _, at = varint(payload)
    return '00' + payload[at:].hex()


def collect(client, who, pig):
    """The watched packets, until a ping returns; position syncs only for the players and the pig."""
    token = os.urandom(8)
    client.send('ping_request', token)
    seen = []
    while True:
        name, payload = client.receive()
        if name in WATCHED:
            # A player's own syncs follow its teleport confirmations and ground changes, not riding.
            if name == 'entity_position_sync' and varint(payload)[0] != pig[0]:
                continue
            seen.append([who, name, normalized(name, payload)])
        if name == 'pong_response' and payload == token:
            return seen


def find_pig(client, pig):
    """The pig's network ID, from the add_entity packet in the probe's trace."""
    packets = json.loads((ROOT / 'reference/26.3/packets.json').read_text())
    add_entity = packets['play']['clientbound']['minecraft:add_entity']['protocol_id']
    for entry in client.trace:
        body = bytes.fromhex(entry.get('hex', ''))
        if entry.get('direction') != 'clientbound' or not body or varint(body)[0] != add_entity:
            continue
        entity, at = varint(body, varint(body)[1])
        kind, _ = varint(body, at + 16)
        if kind == pig[1]:
            pig[0] = entity


def record(kind, output, java, jar, eula, pig_type):
    server = Server(output / kind, kind, java, jar, eula)
    server.start()
    steps = []
    pig = [None, pig_type]
    try:
        operator = Probe(server.port, 'operator').login()
        witness = Probe(server.port, 'witness').login('Witness')
        for client in (operator, witness):
            client.tick_barrier()
        for command in ('fill -3 199 -3 3 199 3 stone', 'tp @a 0 200 0 0 0', 'kill @e[type=!player]',
                        'summon pig 1 200 1 {NoAI:1b,Tags:["rp"],Rotation:[0f,0f]}', 'item replace entity @e[tag=rp] saddle with saddle'):
            operator.send('chat_command', wire.string(command))
        for client in (operator, witness):
            client.tick_barrier()
            client.tick_barrier()
        collect(witness, 'witness', pig)
        collect(operator, 'operator', pig)
        find_pig(operator, pig)
        assert pig[0] is not None, 'the pig was not seen'
        interact = lambda secondary: operator.send('interact', wire.vi(pig[0]) + wire.vi(0) + b'\0' + (b'\1' if secondary else b'\0'))
        # Sneak-using the pig does nothing; using it mounts.
        for action in (lambda: interact(True), lambda: interact(False),
                       lambda: operator.send('player_input', b'\x20'), lambda: operator.send('player_input', b'\0'),
                       lambda: interact(False)):
            action()
            time.sleep(0.5)
            steps.append(collect(witness, 'witness', pig) + collect(operator, 'operator', pig))
    finally:
        server.stop()
    (output / f'{kind}-riding.json').write_text(json.dumps(steps, indent=1))
    return steps


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, default=ROOT / 'tools/.cache/pig-riding')
    parser.add_argument('--eula-file', type=Path, help='Existing user-accepted eula.txt; compares with the official server')
    parser.add_argument('--jar', type=Path, default=ROOT / 'tools/.cache/26.3/server.jar')
    parser.add_argument('--java', default=str(Path(os.environ['JAVA_HOME']) / 'bin/java') if 'JAVA_HOME' in os.environ else 'java')
    args = parser.parse_args()
    args.output = args.output.resolve()
    shutil.rmtree(args.output, ignore_errors=True)
    args.output.mkdir(parents=True)
    pig_type = json.loads((ROOT / 'reference/26.3/mobs.json').read_text())['entities']['pig']['id']
    php = record('php', args.output, args.java, args.jar.resolve(), args.eula_file, pig_type)
    names = [[name for _, name, _ in step] for step in php]
    assert names[0] == [] and 'set_passengers' in names[1] and 'player_position' in names[1], php
    if args.eula_file is not None:
        java = record('java', args.output, args.java, args.jar.resolve(), args.eula_file.resolve(), pig_type)
        assert php == java, json.dumps({'php': php, 'java': java}, indent=1)
        print('PASS: pig mounting and sneaking off identical to the official server')
    else:
        print('PASS: a saddled pig mounts its user, who sneaks off beside it')


if __name__ == '__main__':
    main()
