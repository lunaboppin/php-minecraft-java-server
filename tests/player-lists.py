#!/usr/bin/env python3
"""/ban, /pardon, /banlist and /whitelist: kicks, login rejections, operator bypass and reloading the saved lists."""
import json
from pathlib import Path
import sys
import tempfile
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'tools/parity'))
sys.path.insert(0, str(ROOT / 'tests/support'))
from protocol import Probe, wire


def run(client, text):
    start = len(client.seen)
    client.send('chat_command', wire.string(text))
    client.tick_barrier()
    return [payload for packet, payload in client.seen[start:] if packet == 'system_chat']


def disconnect_reason(client):
    try:
        client.until('bundle_delimiter', lambda payload: False, timeout=10)
    except (AssertionError, EOFError, OSError) as error:
        return str(error)
    return ''


def rejection(name):
    """The login_disconnect reason a joining player receives, as JSON."""
    try:
        Probe(port, 'rejected').login(name)
    except AssertionError as error:
        text = str(error)
        return json.loads(text[text.index('{'):text.rindex('}') + 1])
    raise AssertionError(name + ' was admitted')


with tempfile.TemporaryDirectory(prefix='php-minecraft-player-lists-') as tmp:
    config = {'port': 0, 'generator': 'flat', 'worldPath': str(Path(tmp) / 'world.sqlite'), 'operators': ['ParityProbe']}
    with wire.server(config) as port:
        op = Probe(port, 'operator').login()
        guest = Probe(port, 'guest').login('Guest')
        for client in (op, guest):
            client.tick_barrier()
        assert any(b'commands.ban.success' in payload for payload in run(op, 'ban Guest Griefing'))
        assert 'multiplayer.disconnect.banned' in disconnect_reason(guest), 'an online player is kicked'
        assert rejection('Guest') == {'translate': 'multiplayer.disconnect.banned.reason', 'with': [{'text': 'Griefing'}]}, 'a banned player cannot join'
        assert any(b'commands.banlist.entry' in payload for payload in run(op, 'banlist'))
        assert any(b'commands.pardon.success' in payload for payload in run(op, 'pardon Guest'))
        Probe(port, 'guest').login('Guest').close()
        # The whitelist turns away unlisted players, but never operators.
        run(op, 'whitelist on')
        assert rejection('Guest') == {'translate': 'multiplayer.disconnect.not_whitelisted'}
        op.close()
        op = Probe(port, 'operator').login()
        op.tick_barrier()
        assert any(b'commands.whitelist.add.success' in payload for payload in run(op, 'whitelist add Guest'))
        # Reloading reads the lists back from whitelist.json and banned-players.json.
        run(op, 'whitelist reload')
        assert any(b'Guest' in payload for payload in run(op, 'whitelist list'))
        Probe(port, 'guest').login('Guest').close()
        run(op, 'whitelist off')
        op.close()
print('PASS: /ban, /pardon, /banlist and /whitelist kicks, login rejections, operator bypass and saved lists')
