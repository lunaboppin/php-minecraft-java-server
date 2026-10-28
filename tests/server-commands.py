#!/usr/bin/env python3
"""/seed, /save-all, /save-on, /save-off and /stop feedback, autosave state and shutdown."""
import argparse
import json
from pathlib import Path
import sys
import tempfile
import time
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'tools/parity'))
sys.path.insert(0, str(ROOT / 'tests/support'))
from protocol import Probe, wire
from text_components import admin, chat, component, player, translate


def run(client, text):
    start = len(client.seen)
    client.send('chat_command', wire.string(text))
    client.tick_barrier()
    return client.seen[start:]


def chats(packets):
    return [payload for name, payload in packets if name == 'system_chat']


def failure(key):
    return chat({'text': '', 'color': 'red', 'extra': [translate(key)]})


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, help='Write PHP packet traces for the official codec verifier')
    args = parser.parse_args()
    with tempfile.TemporaryDirectory(prefix='php-minecraft-server-commands-') as tmp:
        config = {'port': 0, 'generator': 'flat', 'seed': -1234567, 'worldPath': str(Path(tmp) / 'world.sqlite'), 'operators': ['ParityProbe', 'Watcher']}
        traces = []
        with wire.server(config) as port:
            op = Probe(port, 'operator').login()
            watcher = Probe(port, 'watcher').login('Watcher')
            guest = Probe(port, 'guest').login('Guest')
            clients = (op, watcher, guest)
            try:
                for client in clients:
                    client.tick_barrier()
                op_uuid = next(payload[2:18] for name, payload in watcher.seen if name == 'player_info_update' and b'ParityProbe' in payload)
                echoes = lambda client, start: chats(client.seen[start:])

                seed = '-1234567'
                copyable = {'translate': 'chat.square_brackets', 'with': [{
                    'text': seed, 'color': 'green', 'insertion': seed,
                    'click_event': {'action': 'copy_to_clipboard', 'value': seed},
                    'hover_event': {'action': 'show_text', 'value': {'translate': 'chat.copy.click'}}}]}
                start = len(watcher.seen)
                assert chats(run(op, 'seed')) == [chat(translate('commands.seed.success', copyable))]
                watcher.tick_barrier()
                assert echoes(watcher, start) == [], '/seed is not broadcast to operators'

                start = len(watcher.seen)
                saved = translate('commands.save.success')
                assert chats(run(op, 'save-all flush')) == [chat(translate('commands.save.saving')), chat(saved)]
                watcher.tick_barrier()
                assert echoes(watcher, start) == [chat(admin(player('ParityProbe', op_uuid), saved))], 'only the success is echoed'

                assert chats(run(op, 'save-on')) == [failure('commands.save.alreadyOn')]
                assert chats(run(op, 'save-off')) == [chat(translate('commands.save.disabled'))]
                assert chats(run(op, 'save-off')) == [failure('commands.save.alreadyOff')]
                assert chats(run(op, 'save-on')) == [chat(translate('commands.save.enabled'))]
                assert [p for p in chats(run(guest, 'stop')) if b'Unknown command' in p], 'non-operators cannot stop the server'

                stopping = translate('commands.stop.stopping')
                op.send('chat_command', wire.string('stop'))
                assert op.until('system_chat') == chat(stopping)
                watcher.until('system_chat', lambda payload: payload == chat(admin(player('ParityProbe', op_uuid), stopping)))
                for client in clients:
                    assert client.until('disconnect') == component({'translate': 'multiplayer.disconnect.server_shutdown'})
                # The server exits on its own; the harness then sees a clean exit code.
                time.sleep(0.5)
            finally:
                for client in clients:
                    traces.extend(client.trace)
                    client.close()
        if args.output:
            args.output.write_text(json.dumps(traces))
    print('PASS: /seed copy text, /save-all, /save-on, /save-off, /stop broadcast and shutdown reason')


if __name__ == '__main__':
    main()
