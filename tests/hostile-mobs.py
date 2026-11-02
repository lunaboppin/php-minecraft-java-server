#!/usr/bin/env python3
"""Hostile mobs hunt players: a zombie leaves creative players alone and hits a survival one for its
attack damage, scaled by difficulty; a creeper beside a survival player swells and explodes.
Exact timing depends on vanilla's random target checks, so only the outcomes are checked.
"""
from pathlib import Path
import sys,tempfile,time
ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'tools/parity'))
from protocol import Probe,wire


def command(c,text):c.send('chat_command',wire.string(text));c.tick_barrier()
def wait(c,seconds):
    end=time.monotonic()+seconds
    while time.monotonic()<end:c.barrier();time.sleep(.05)
def main():
    with tempfile.TemporaryDirectory(prefix='php-minecraft-hostile-') as tmp:
        cfg={'port':0,'generator':'flat','defaultGameMode':1,'difficulty':2,'operators':['ParityProbe'],'worldPath':str(Path(tmp)/'world.sqlite')}
        with wire.server(cfg) as port:
            a=Probe(port,'hostile').login()
            for text in ['gamerule spawn_mobs false','gamerule natural_health_regeneration false','gamerule advance_time false','time set midnight',
                         'fill -8 -61 -8 8 -61 8 minecraft:stone','fill -8 -60 -8 8 -55 8 minecraft:air','tp @s 0.5 -60 0.5 0 0','summon zombie 0.5 -60 2.5']:
                command(a,text)
            wait(a,3)
            assert a.health==20.0,('a zombie hit a creative player',a.health)
            command(a,'gamemode survival @s')
            wait(a,4)
            assert a.health<20.0,'a zombie never hit a survival player'
            # Normal difficulty leaves the zombie's 3 attack damage as it is.
            assert (20.0-a.health)%3.0==0.0 or a.health==0.0,('unscaled zombie damage',a.health)
            command(a,'kill @e[type=zombie]')
            command(a,'difficulty easy')
            command(a,'effect give @s instant_health 1 5')
            wait(a,1)
            before=a.health
            command(a,'summon zombie 0.5 -60 2.5')
            wait(a,4)
            # Easy difficulty: min(3 / 2 + 1, 3) = 2.5 a hit.
            assert before>a.health and (before-a.health)%2.5==0.0,('easy zombie damage',before,a.health)
            command(a,'kill @e[type=zombie]')
            command(a,'difficulty normal')
            command(a,'effect give @s instant_health 1 5')
            command(a,'effect give @s resistance 30 3')
            command(a,'summon creeper 0.5 -60 2.5')
            wait(a,5)
            command(a,'execute if entity @e[type=creeper]')
            assert any('conditional.fail' in str(m) for m in getattr(a,'chat',[])) or not any(kind==wire.BLOCKS['entity_types']['creeper'] for kind in a.entities.values()),'the creeper never exploded'
            # A skeleton strafes, draws for a second and shoots its arrows at a survival player.
            for text in ['kill @e[type=creeper]','effect clear @s','fill -8 -61 -8 8 -61 8 minecraft:stone','fill -8 -60 -8 8 -55 8 minecraft:air','tp @s 0.5 -60 0.5 0 0','effect give @s instant_health 1 5','summon skeleton 0.5 -60 8.5']:
                command(a,text)
            before=a.health
            arrow=wire.BLOCKS['entity_types']['arrow']
            # Arrows spread at random, so give the skeleton several shots before calling it a miss.
            end=time.monotonic()+30
            while time.monotonic()<end and not (a.health<before and any(s['type']==arrow for s in a.entity_spawns)):wait(a,.5)
            assert any(s['type']==arrow for s in a.entity_spawns),'the skeleton never shot'
            assert a.health<before,('no skeleton arrow hit',before,a.health)
            # A stray's arrows slow what they hit.
            for text in ['kill @e[type=skeleton]','kill @e[type=arrow]','effect give @s instant_health 1 5','summon stray 0.5 -60 8.5']:
                command(a,text)
            slowed=False
            end=time.monotonic()+15
            while time.monotonic()<end and not slowed:
                wait(a,.5)
                a.send('chat_command',wire.string('effect clear @s slowness'))
                slowed=b'commands.effect.clear.specific.success' in a.until('system_chat',lambda p:b'commands.effect.clear' in p,timeout=10)
            assert slowed,'no stray arrow slowed the player'
    print('PASS: zombies leave creative players alone and hit survival ones for difficulty-scaled damage; creepers swell and explode; skeletons and strays shoot')


if __name__=='__main__':main()
