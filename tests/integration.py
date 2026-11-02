#!/usr/bin/env python3
"""Independent TCP client using only Python's standard library and Mojang fixtures."""
import contextlib
import json
import os
from pathlib import Path
import select
import socket
import sqlite3
import struct
import subprocess
import sys
import tempfile
import threading
import time
import zlib

ROOT = Path(__file__).resolve().parents[1]
PACKETS = json.loads((ROOT / 'reference/26.3/packets.json').read_text())
FIXTURES = json.loads((ROOT / 'reference/26.3/fixtures.json').read_text())
BLOCKS = json.loads((ROOT / 'reference/26.3/blocks.json').read_text())
STACK_RULES = json.loads((ROOT / 'reference/26.3/equipment-stack-rules.json').read_text())
COMPONENT_NAMES = {value: name for name, value in STACK_RULES['component_ids'].items()}
STACK_FIELDS = {STACK_RULES['component_ids'][name]: field for field, name in STACK_RULES['fields'].items()}
ENCHANTMENTS = json.loads((ROOT / 'reference/26.3/dynamic-registries.json').read_text())['minecraft:enchantment']
CAPTURE = []
CHECKS = 0


def check(condition, label):
    global CHECKS
    CHECKS += 1
    if not condition:
        raise AssertionError(label)


def vi(n):
    n &= 0xffffffff
    out = bytearray()
    while n > 127:
        out.append((n & 127) | 128)
        n >>= 7
    return bytes(out + bytes([n]))


def read_vi(data, offset=0):
    n = 0
    for i in range(5):
        b = data[offset + i]
        n |= (b & 127) << (i * 7)
        if b < 128:
            return (n if n < 2**31 else n - 2**32), offset + i + 1
    raise ValueError('bad VarInt')


def string(text):
    b = text.encode('utf-8')
    return vi(len(b)) + b


def read_string(data, offset=0):
    n, offset = read_vi(data, offset)
    return data[offset:offset+n].decode('utf-8'), offset+n


def packet_id(state, direction, name):
    return PACKETS[state][direction]['minecraft:' + name]['protocol_id']


def read_potion(payload, off):
    """PotionContents.STREAM_CODEC, keeping no hidden effects as vanilla does."""
    contents = {'potion': None, 'custom_color': None, 'custom_effects': [], 'custom_name': None}
    if payload[off]:
        holder, off = read_vi(payload, off + 1)
        contents['potion'] = STACK_RULES['potions'][holder]
    else:
        off += 1
    if payload[off]:
        contents['custom_color'] = struct.unpack('>i', payload[off + 1:off + 5])[0]
        off += 5
    else:
        off += 1
    count, off = read_vi(payload, off)
    for _ in range(count):
        holder, off = read_vi(payload, off)
        effect = {'id': STACK_RULES['mob_effects'][holder]}
        while True:
            amplifier, off = read_vi(payload, off)
            duration, off = read_vi(payload, off)
            details = {'amplifier': amplifier, 'duration': duration, 'ambient': bool(payload[off]), 'show_particles': bool(payload[off + 1]), 'show_icon': bool(payload[off + 2]), 'hidden_effect': None}
            effect = effect if 'amplifier' in effect else effect | details
            off += 4
            if not payload[off - 1]:
                break
        contents['custom_effects'].append(effect)
    if payload[off]:
        contents['custom_name'], off = read_string(payload, off + 1)
    else:
        off += 1
    return contents, off


def read_stack(payload, off):
    count, off = read_vi(payload, off)
    if count <= 0:
        return None, off
    item, off = read_vi(payload, off)
    added, off = read_vi(payload, off)
    removed, off = read_vi(payload, off)
    stack = {'item': item, 'count': count}
    for _ in range(added):
        component, off = read_vi(payload, off)
        field = STACK_FIELDS.get(component)
        if field in ('enchantments', 'storedEnchantments'):
            size, off = read_vi(payload, off)
            values = {}
            for _ in range(size):
                holder, off = read_vi(payload, off)
                level, off = read_vi(payload, off)
                values[ENCHANTMENTS[holder]] = level
            stack[field] = values
        elif field in ('damage', 'enchantability'):
            stack[field], off = read_vi(payload, off)
        elif field == 'potion':
            stack[field], off = read_potion(payload, off)
        else:
            raise AssertionError('unexpected stack component ' + str(component))
    if removed:
        names = []
        for _ in range(removed):
            component, off = read_vi(payload, off)
            names.append(COMPONENT_NAMES[component])
        stack['removedComponents'] = sorted(names)
    return stack, off


class Client:
    def __init__(self, port, terrain=False):
        self.sock = socket.create_connection(('127.0.0.1', port), timeout=4)
        self.sock.settimeout(4)
        self.dimension = 'overworld'
        self.terrain = terrain
        self.inventory = []
        self.revision = 0
        self.window = 0
        self.cursor = None
        self.state = 'handshake'
        self.threshold = -1
        self.seen = []
        self.teleport = None
        self.pose = [0.0, 0.0, 0.0, 0.0, 0.0]
        self.chunk_count = 0

    def close(self):
        self.sock.close()

    def handshake(self, intention, protocol=777, fragmented=False):
        payload = vi(protocol) + string('localhost') + struct.pack('>H', 25565) + vi(intention)
        data = self.frame('intention', payload)
        if fragmented:
            self.sock.sendall(data[:1])
            time.sleep(0.005)
            self.sock.sendall(data[1:4])
            self.sock.sendall(data[4:])
        else:
            self.sock.sendall(data)
        self.state = 'status' if intention == 1 else 'login'

    def frame(self, name, payload=b''):
        body = vi(packet_id(self.state, 'serverbound', name)) + payload
        if self.threshold >= 0:
            body = vi(len(body)) + zlib.compress(body) if len(body) >= self.threshold else b'\0' + body
        return vi(len(body)) + body

    def send(self, name, payload=b''):
        self.sock.sendall(self.frame(name, payload))
        # A client ends each tick it moved in; vanilla allows one position per client tick.
        if name.startswith('move_player_'):
            self.sock.sendall(self.frame('client_tick_end'))

    def exact(self, length):
        result = b''
        while len(result) < length:
            b = self.sock.recv(length-len(result))
            if not b:
                raise EOFError('server closed connection')
            result += b
        return result

    def receive(self):
        prefix = b''
        for _ in range(3):
            b = self.exact(1)
            prefix += b
            if b[0] < 128:
                break
        length, _ = read_vi(prefix)
        body = self.exact(length)
        if self.threshold >= 0:
            uncompressed, off = read_vi(body)
            body = zlib.decompress(body[off:]) if uncompressed else body[off:]
            if uncompressed:
                check(len(body) == uncompressed, 'correct compressed frame length')
        CAPTURE.append({'state': self.state, 'hex': body.hex(), **({'terrain': True} if self.terrain else {})})
        pid, off = read_vi(body)
        names = {v['protocol_id']: k[10:] for k, v in PACKETS[self.state]['clientbound'].items()}
        name = names[pid]
        payload = body[off:]
        if self.state == 'play' and name == 'respawn':
            _, dimension_off = read_vi(payload)
            self.dimension, _ = read_string(payload, dimension_off)
            self.dimension = self.dimension.removeprefix('minecraft:')
        if self.state == 'play' and name == 'login':
            levels, dimension_off = read_vi(payload, 5)
            for _ in range(levels):
                _, dimension_off = read_string(payload, dimension_off)
            for _ in range(3):
                _, dimension_off = read_vi(payload, dimension_off)
            _, dimension_off = read_vi(payload, dimension_off + 3)
            self.dimension, _ = read_string(payload, dimension_off)
            self.dimension = self.dimension.removeprefix('minecraft:')
        if self.state == 'play':
            CAPTURE[-1]['dimension'] = self.dimension
        self.seen.append((name, payload))
        if self.state == 'login' and name == 'login_compression':
            self.threshold, _ = read_vi(payload)
        if self.state == 'play':
            if name == 'player_position':
                self.teleport, off = read_vi(payload)
                values = [*struct.unpack_from('>ddd', payload, off), *struct.unpack_from('>ff', payload, off + 48)]
                relatives, = struct.unpack_from('>i', payload, off + 56)
                # Relative X, Y, Z, Y_ROT and X_ROT (bits 0-4) offset the client's pose, as a real client
                # applies them; vanilla moves the player to the confirmed position.
                self.pose = [self.pose[i] + value if relatives >> i & 1 else value for i, value in enumerate(values)]
                x, y, z, yaw, pitch = self.pose
                confirmation = vi(self.teleport) + struct.pack('>dddff', x, y, z, yaw, pitch)
                if self.teleport == 1 and (x, y, z, yaw, pitch) == (0.5, 64.0, 0.5, 0.0, 0.0):
                    check(confirmation.hex() == FIXTURES['client_teleport_confirmation'],
                          'teleport confirmation matches official 26.3 codec')
                self.send('accept_teleportation', confirmation)
            elif name == 'chunk_batch_finished':
                n, _ = read_vi(payload)
                self.chunk_count += n
                self.send('chunk_batch_received', struct.pack('>f', 20))
            elif name == 'keep_alive':
                self.send('keep_alive', payload)
        if self.state == 'play' and name == 'container_set_content':
            self.window, off = read_vi(payload)
            self.revision, off = read_vi(payload, off)
            count, off = read_vi(payload, off)
            self.inventory = []
            for _ in range(count):
                stack, off = read_stack(payload, off)
                self.inventory.append(stack)
            self.cursor, off = read_stack(payload, off)
            check(off == len(payload), 'inventory payload consumed')
        return name, payload

    def until(self, target, predicate=lambda payload: True, limit=20000, timeout=60):
        # Idle clients queue world updates (random ticks, fluids, mobs), so the timeout bounds the wait.
        deadline = time.monotonic() + timeout
        for _ in range(limit):
            if time.monotonic() > deadline:
                raise AssertionError('timed out waiting for ' + target)
            name, payload = self.receive()
            if name == target and predicate(payload):
                return payload
            check(name not in ('disconnect', 'login_disconnect'), 'not disconnected: ' + repr(payload))
        raise AssertionError('did not receive ' + target)

    def login(self, name='Steve'):
        self.handshake(2)
        self.send('hello', string(name) + bytes(16))
        payload = self.until('login_finished')
        actual_name, off = read_string(payload, 16)
        check(actual_name == name and len(payload)-off == 17, '26.3 login session UUID and properties')
        self.uuid = payload[:16]
        self.send('login_acknowledged')
        self.state = 'configuration'
        check(self.until('select_known_packs') == b'\0', 'explicit full-registry negotiation')
        self.send('select_known_packs', b'\0')
        self.until('finish_configuration')
        check(sum(n == 'registry_data' for n, _ in self.seen) == 32, 'all 32 registry packets received')
        self.send('finish_configuration')
        self.state = 'play'
        login_payload = self.until('login')
        self.entity_id = struct.unpack_from('>i', login_payload)[0]
        self.until('chunk_batch_finished')
        check(self.teleport == 1, 'initial teleport acknowledged')
        # Confirm the server processed the acknowledgement, rather than only reading
        # chunks that might already have been queued before a disconnect.
        nonce = struct.pack('>q', 263)
        self.send('ping_request', nonce)
        check(self.until('pong_response') == nonce, 'play continues after teleport confirmation')
        return self


@contextlib.contextmanager
def server(config):
    with tempfile.TemporaryDirectory(prefix='php-minecraft-server-') as temp:
        cfg = Path(temp) / 'server.json'
        # Scenarios build near spawn as non-operators, like the vanilla references run with spawn-protection=0.
        # Scenarios expect players at the spawn itself; vanilla's default respawn_radius scatters them.
        cfg.write_text(json.dumps({'logDirectory':str(Path(temp)/'logs'),'spawnProtection':0,'initialGameRules':{'respawn_radius':'0'},**config}))
        error = open(Path(temp) / 'stderr.log', 'w+')
        proc = subprocess.Popen(['php', str(ROOT / 'bin/server.php'), '--config=' + str(cfg)], cwd=ROOT,
                                stdout=subprocess.PIPE, stderr=error, text=True)
        reader=None;drained=[]
        try:
            ready, _, _ = select.select([proc.stdout], [], [], 10)
            if not ready:
                raise RuntimeError('server startup timed out')
            line = proc.stdout.readline()
            if 'Listening on' not in line:
                error.seek(0)
                raise RuntimeError('server startup failed: ' + line + error.read())
            port = int(line.split('Listening on ')[1].split(' ')[0].rsplit(':', 1)[1])
            reader=threading.Thread(target=lambda:drained.append(proc.stdout.read()),daemon=True);reader.start()
            yield port
        finally:
            proc.terminate()
            try:
                if reader is None:output,_=proc.communicate(timeout=5)
                else:proc.wait(timeout=5);reader.join(timeout=2);output=''.join(drained)
            except subprocess.TimeoutExpired:
                proc.kill()
                if reader is None:output,_=proc.communicate()
                else:proc.wait();reader.join(timeout=2);output=''.join(drained)
            error.seek(0)
            errors = error.read()
            error.close()
            if proc.returncode not in (0, -15) or errors:
                raise AssertionError('server failed: ' + output + '\n' + errors)


def status_test(port):
    c = Client(port)
    try:
        c.handshake(1, protocol=9999, fragmented=True)
        nonce = b'\x80\0\xff\x7f\x01\x02\x03\x04'
        c.sock.sendall(c.frame('status_request') + c.frame('ping_request', nonce))
        result = c.until('status_response')
        text, off = read_string(result)
        info = json.loads(text)
        check(info['version'] == {'name': '26.3', 'protocol': 777}, 'status advertises exact version')
        # Vanilla's ServerStatus codec: field order, a bare-string plain description, and no empty sample or false enforcesSecureChat.
        check(list(info) == ['description', 'players', 'version'] and isinstance(info['description'], str), 'status matches vanilla field shape')
        check('sample' not in info['players'] or info['players']['sample'], 'an empty player sample is omitted')
        check(c.until('pong_response') == nonce, '64-bit ping echoed byte-for-byte')
        check(c.sock.recv(1) == b'', 'status connection closes after pong')
    finally:
        c.close()


def rejected_login(port, name='Bad-Name', protocol=777, reason='Invalid'):
    c = Client(port)
    try:
        c.handshake(2, protocol=protocol)
        if protocol == 777:
            c.send('hello', string(name) + bytes(16))
        payload = c.until('login_disconnect')
        text, _ = read_string(payload)
        check(reason in text, 'login rejected: ' + reason)
    finally:
        c.close()


def block_position(x, y, z):
    return struct.pack('>Q', ((x & 0x3ffffff) << 38) | ((z & 0x3ffffff) << 12) | (y & 0xfff))


def main():
    with tempfile.TemporaryDirectory(prefix='php-minecraft-world-test-') as temp:
        world = str(Path(temp) / 'world.sqlite')
        config = {'port': 0, 'worldPath': world, 'maxPlayers': 2, 'generator': 'flat', 'defaultGameMode': 1}
        with server(config) as port:
            status_test(port)
            rejected_login(port, protocol=766, reason='26.3')
            rejected_login(port)
            a = Client(port).login('Steve')
            rejected_login(port, name='sTeVe', reason='already connected')
            b = Client(port).login('Alex')
            rejected_login(port, name='Third', reason='full')
            try:
                spawned = a.until('add_entity')
                _, off = read_vi(spawned)
                check(spawned[off:off+16] == b.uuid, 'multiplayer spawn UUID')
                # Use exact serverbound packet payloads emitted by official Java codecs.
                a.send('chat', bytes.fromhex(FIXTURES['client_chat']))
                # Unsigned chat reaches others as vanilla's player_chat with the sender's UUID and text.
                b.until('player_chat', lambda p: a.uuid in p and b'\x05Hello' in p)
                check(True, 'chat relayed to other player')
                # As on a vanilla dedicated server, /version needs operator permission.
                a.send('chat_command', string('version'))
                a.until('system_chat', lambda p: b'command.unknown.command' in p)
                a.send('set_creative_mode_slot', bytes.fromhex(FIXTURES['creative_stone']))
                a.until('container_set_slot')
                a.send('use_item_on', bytes.fromhex(FIXTURES['place_stone']))
                ack = a.until('block_changed_ack')
                check(read_vi(ack)[0] == 1, 'placement sequence acknowledged')
                update = b.until('block_update', lambda p: p[:8] == block_position(0, 64, 1))
                check(read_vi(update, 8)[0] == BLOCKS['states']['stone'], 'placed block broadcast')
                with sqlite3.connect(world) as db:
                    check(db.execute('SELECT state FROM blocks WHERE x=0 AND y=64 AND z=1').fetchone() == (BLOCKS['states']['stone'],), 'placement persisted to SQLite')
                a.send('player_action', bytes.fromhex(FIXTURES['dig_stone']))
                a.until('block_changed_ack')
                b.until('block_update', lambda p: p[:8] == block_position(0, 64, 1) and read_vi(p, 8)[0] == 0)
                check(True, 'creative block breaking replicated')
                # Restore the edit and verify it survives restarting the PHP process.
                a.send('use_item_on', bytes.fromhex(FIXTURES['place_stone']))
                a.until('block_changed_ack')
                # Chunk center update after crossing a negative coordinate boundary.
                # Walked in steps a tick apart: vanilla teleports back anyone moving over ten blocks at once.
                for step in (-5, -11, -17):
                    a.send('move_player_pos', struct.pack('>dddB', step, 64, step, 1))
                    time.sleep(0.12)
                center = a.until('set_chunk_cache_center', lambda p: read_vi(p)[0] == -2)
                x, off = read_vi(center)
                z, _ = read_vi(center, off)
                check((x, z) == (-2, -2), 'negative chunk coordinates use floor division')
                a.send('chat_command', string('spawn'))
                a.until('player_position')
                # Server remains responsive when another connection sends an invalid frame.
                bad = Client(port)
                bad.sock.sendall(b'\x80\x80\x80')
                check(bad.sock.recv(1) == b'', 'overlong frame disconnected')
                bad.close()
                status_test(port)
                # Keep-alive is tested on a real socket, with batching continuously acknowledged.
                a.until('keep_alive', limit=1000)
                a.send('chat_command', string('list'))
                a.until('system_chat', lambda p: b'commands.list.players' in p and b'Steve' in p and b'Alex' in p)
                check(True, 'keep-alive exchange and continued play')
            finally:
                a.close()
                b.close()
        with server(config) as port:
            c = Client(port).login('Returning')
            try:
                with sqlite3.connect(world) as db:
                    check(db.execute('SELECT state FROM blocks WHERE x=0 AND y=64 AND z=1').fetchone() == (BLOCKS['states']['stone'],), 'world edit survives process restart')
            finally:
                c.close()
        # Compression disabled and threshold zero exercise both transport boundary cases.
        for threshold in [-1, 0]:
            with server({**config, 'compressionThreshold': threshold}) as port:
                c = Client(port).login('Boundary')
                check(c.threshold == threshold, 'compression mode ' + str(threshold))
                c.close()
    if os.environ.get('PHP_MINECRAFT_CAPTURE'):
        Path(os.environ['PHP_MINECRAFT_CAPTURE']).write_text(json.dumps(CAPTURE))
    print(f'PASS: {CHECKS} independent TCP checks ({len(CAPTURE)} captured server packets)')


if __name__ == '__main__':
    main()
