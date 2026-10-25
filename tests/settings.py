#!/usr/bin/env python3
"""Client settings across configuration/play, multiplayer metadata, status and chunk unloading."""
import json
from pathlib import Path
import struct
import sys
import tempfile
ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'tools/parity'))
from protocol import Probe,wire


def information(view=4,skin=127,arm=0,listing=True):
    return wire.string('en_gb')+bytes([view&255,0,1,skin])+wire.vi(arm)+bytes([0,int(listing),0])


def status(port):
    client=wire.Client(port)
    try:
        client.handshake(1);client.send('status_request')
        return json.loads(wire.read_string(client.until('status_response'))[0])['players']
    finally:client.close()


def main():
    with tempfile.TemporaryDirectory(prefix='php-minecraft-settings-') as tmp:
        config={'port':0,'generator':'flat','viewDistance':4,'worldPath':str(Path(tmp)/'world.sqlite')}
        with wire.server(config) as port:
            a=Probe(port,'settings').login(information=information())
            b=Probe(port,'witness').login('Witness',information=information())
            try:
                assert a.appearance=={'skinParts':127,'mainHand':0},a.appearance
                players=status(port)
                assert players['online']==2 and {p['name'] for p in players['sample']}=={'ParityProbe','Witness'},players
                # Drain all initial batches before reducing distance.
                a.until('set_time',lambda p:struct.unpack_from('>q',p)[0]>120)
                assert len(a.chunks)==81,len(a.chunks)
                start=len(b.seen)
                a.send('client_information',information(view=2,skin=1,arm=1,listing=False));a.barrier()
                assert a.appearance=={'skinParts':1,'mainHand':1},a.appearance
                radius=next(p for name,p in reversed(a.seen) if name=='set_chunk_cache_radius')
                assert wire.read_vi(radius)[0]==2
                assert len(a.chunks)==25,len(a.chunks)
                b.barrier()
                meta=json.loads((ROOT/'reference/26.3/gameplay.json').read_text())['entity_metadata']
                expected=wire.vi(a.entity_id)+bytes([meta['skin_index']])+wire.vi(meta['byte_serializer'])+b'\1'+bytes([meta['main_hand_index']])+wire.vi(meta['main_hand_serializer'])+b'\1\xff'
                assert any(name=='set_entity_data' and p==expected for name,p in b.seen[start:]),'settings metadata not sent to witness'
                players=status(port)
                assert players['online']==2 and {p['name'] for p in players['sample']}=={'Anonymous Player','Witness'},players
                assert next(p['id'] for p in players['sample'] if p['name']=='Anonymous Player')=='00000000-0000-0000-0000-000000000000'
                # Malformed payloads fail explicitly, rather than silently corrupting settings.
                for payload in (information()+b'\0',information()[:-1]):
                    bad=Probe(port,'bad').login('BadSettings')
                    try:
                        bad.send('client_information',payload)
                        bad.until('disconnect')
                    finally:bad.close()
                a.barrier();b.barrier()
                print('PASS: settings in configuration/play, appearance propagation, listing privacy, distance changes and malformed settings')
            finally:a.close();b.close()


if __name__=='__main__':main()
