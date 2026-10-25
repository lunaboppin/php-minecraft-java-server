#!/usr/bin/env python3
"""Checks that comparison mechanics detect errors instead of concealing them."""
from pathlib import Path
import struct
import sys
import tempfile
from unittest.mock import Mock
import unittest
sys.path.insert(0,str(Path(__file__).resolve().parents[2]/'tools/parity'))
from run import difference,observe
from protocol import Probe,block_pos,palette,wire


class HarnessTests(unittest.TestCase):
    def test_first_divergence_is_actionable(self):
        expected=[{'state':{'inventory':[None,{'item':1,'count':4}]}}]
        actual=[{'state':{'inventory':[None,{'item':1,'count':3}]}}]
        self.assertEqual(difference(expected,actual),{'path':'$[0].state.inventory[1].count','reference':4,'php':3})
        self.assertIsNone(difference(expected,expected))
        self.assertIsNotNone(difference({'a':1},{'b':1}))
        self.assertIsNotNone(difference([1],[1,2]))
        self.assertIsNotNone(difference(True,1))

    def test_padded_palette_word_boundaries(self):
        # Five bits leaves four unused bits in each word; values cannot straddle words.
        values=[i%7 for i in range(25)]
        words=[sum(v<<(5*i) for i,v in enumerate(values[j:j+12])) for j in range(0,25,12)]
        data=b'\5'+wire.vi(7)+b''.join(wire.vi(i+10) for i in range(7))+struct.pack('>3Q',*words)
        decoded,end=palette(data,0,25,8)
        self.assertEqual(decoded,[v+10 for v in values]);self.assertEqual(end,len(data))
        self.assertEqual(palette(b'\0'+wire.vi(900),0,64,3),([900]*64,3))

    def test_startup_failure_preserves_evidence_and_stops(self):
        controller=Mock();controller.kind='php';controller.start.side_effect=RuntimeError('startup failed')
        with tempfile.TemporaryDirectory() as tmp:
            capture=Path(tmp)/'trace.json'
            with self.assertRaisesRegex(RuntimeError,'php at startup/restart: startup failed'):
                observe(controller,{'steps':[]},capture)
            controller.stop.assert_called_once()
            self.assertEqual(capture.read_text(),'[]\n')

    def test_signed_world_coordinates(self):
        for position in [(-17,-64,-33),(0,319,0),(29999999,255,-29999999)]:
            self.assertEqual(block_pos(struct.unpack('>q',wire.block_position(*position))[0]),position)

    def test_checkpoints_do_not_change_when_later_packets_arrive(self):
        probe=Probe.__new__(Probe);probe.entity_spawns=[{'type':30,'position':[2.5,64,.5]}];probe.notes=[]
        first=probe.snapshot(['entity_spawns','notes'])
        probe.entity_spawns[0]['position'][0]=20.5
        probe.entity_spawns.append({'type':10,'position':[2.5,64,2.5]});probe.notes.append({'pitch':1.0})
        self.assertEqual(first,{'entity_spawns':[{'type':30,'position':[2.5,64,.5]}],'notes':[]})

    def test_retracking_keeps_initial_spawn_without_hiding_new_entities(self):
        probe=Probe.__new__(Probe);probe.dimension='overworld';probe.entities={};probe.spawned_profiles=set();probe.entity_spawns=[]
        def packet(entity,profile,x):return wire.vi(entity)+bytes([profile])*16+wire.vi(10)+struct.pack('>ddd',x,64,2.5)
        probe.observe_spawn(packet(3,1,2.5));probe.entities.pop(3)
        probe.observe_spawn(packet(3,1,32.5))
        self.assertEqual(probe.entity_spawns,[{'type':10,'position':[2.5,64,2.5]}])
        self.assertEqual(probe.entities,{3:10})
        probe.observe_spawn(packet(4,2,2.5))
        self.assertEqual(len(probe.entity_spawns),2)


if __name__=='__main__':unittest.main()
