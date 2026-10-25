"""Version-777 observation helpers. Transport and constants use the existing independent client."""
from pathlib import Path
import struct
import json
import sys

ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'tests'))
import integration as wire
METADATA=json.loads((ROOT/'reference/26.3/gameplay.json').read_text())['entity_metadata']


def palette(data, off, count, local_max):
    bits=data[off];off+=1
    if bits==0:
        value,off=wire.read_vi(data,off)
        return [value]*count,off
    entries=None
    if bits<=local_max:
        n,off=wire.read_vi(data,off);entries=[]
        for _ in range(n):
            value,off=wire.read_vi(data,off);entries.append(value)
    if not 1<=bits<=32:raise ValueError('Invalid palette width')
    per_word=64//bits;words=(count+per_word-1)//per_word
    longs=struct.unpack_from('>'+str(words)+'Q',data,off);off+=words*8
    values=[(longs[i//per_word]>>((i%per_word)*bits))&((1<<bits)-1) for i in range(count)]
    return ([entries[v] for v in values] if entries is not None else values),off


def chunk_blocks(data,dimension):
    cx,cz=struct.unpack_from('>ii',data);n,off=wire.read_vi(data,8)
    for _ in range(n):
        _,off=wire.read_vi(data,off);size,off=wire.read_vi(data,off);off+=size*8
    length,off=wire.read_vi(data,off);end=off+length
    minimum=-64 if dimension=='overworld' else 0;sections=[]
    for _ in range(24 if dimension=='overworld' else 16):
        off+=4
        blocks,off=palette(data,off,4096,8)
        _,off=palette(data,off,64,3)
        sections.append(blocks)
    if off!=end:raise ValueError('Chunk section payload not consumed')
    return cx,cz,minimum,sections


def block_pos(value):
    x=value>>38;y=value&4095;z=(value>>12)&0x3ffffff
    return x,y-4096 if y>=2048 else y,z-0x4000000 if z>=0x2000000 else z


class Probe(wire.Client):
    def __init__(self,port,label):
        super().__init__(port,terrain=True)
        self.label=label;self.trace=[];self.step='connect';self.blocks={};self.chunks={}
        self.health=20.;self.food=20;self.mode=None;self.position=None;self.deaths=0
        self.appearance={};self.entities={};self.observed=[];self.game_time=None;self.loaded=False;self._receiving=False;self._automatic=[]

    def send(self,name,payload=b''):
        (self._automatic if self._receiving else self.trace).append({'state':self.state,'direction':'serverbound','hex':(wire.vi(wire.packet_id(self.state,'serverbound',name))+payload).hex(),
                           'step':self.step,'connection':self.label,'dimension':self.dimension,'terrain':True})
        super().send(name,payload)

    def handshake(self,intention,protocol=777,fragmented=False):
        payload=wire.vi(protocol)+wire.string('localhost')+struct.pack('>H',25565)+wire.vi(intention)
        self.trace.append({'state':'handshake','direction':'serverbound','hex':(b'\0'+payload).hex(),'step':self.step,'connection':self.label,'terrain':True})
        super().handshake(intention,protocol,fragmented)

    def receive(self):
        # Transport parses compression, teleport confirmation, batch acks and complete inventories.
        self._receiving=True
        try:name,p=super().receive()
        finally:self._receiving=False
        self.trace.append({**wire.CAPTURE[-1],'direction':'clientbound','step':self.step,'connection':self.label})
        self.trace.extend(self._automatic);self._automatic=[]
        if self.state=='configuration' and name=='keep_alive':self.send('keep_alive',p)
        if self.state=='play':
            self.observed.append(name)
            if name=='login':
                self.entity_id=struct.unpack_from('>i',p)[0]
                count,off=wire.read_vi(p,5)
                for _ in range(count):_,off=wire.read_string(p,off)
                for _ in range(3):_,off=wire.read_vi(p,off)
                _,off=wire.read_vi(p,off+3);_,off=wire.read_string(p,off)
                self.mode,_=wire.read_vi(p,off+8)
            elif name=='respawn':
                _,off=wire.read_vi(p);_,off=wire.read_string(p,off);self.mode,_=wire.read_vi(p,off+8)
                self.chunks={};self.blocks={};self.entities={};self.loaded=False
            elif name=='game_event' and p[0]==3:self.mode=int(struct.unpack_from('>f',p,1)[0])
            elif name=='player_position':
                _,off=wire.read_vi(p);self.position=list(struct.unpack_from('>ddd',p,off))
            elif name=='set_entity_data':
                entity,off=wire.read_vi(p)
                if entity==getattr(self,'entity_id',None):
                    meta=METADATA
                    while p[off]!=255:
                        index=p[off];serializer,off=wire.read_vi(p,off+1)
                        if serializer==meta['byte_serializer']:value=p[off];off+=1
                        elif serializer in (meta['int_serializer'],meta['main_hand_serializer']):value,off=wire.read_vi(p,off)
                        else:break  # Other metadata remains in the trace for the official codec verifier.
                        if index==meta['skin_index']:self.appearance['skinParts']=value
                        if index==meta['main_hand_index']:self.appearance['mainHand']=value
            elif name=='add_entity':
                entity,off=wire.read_vi(p);kind,off=wire.read_vi(p,off+16)
                self.entities[entity]=kind
            elif name=='remove_entities':
                count,off=wire.read_vi(p)
                for _ in range(count):
                    entity,off=wire.read_vi(p,off);self.entities.pop(entity,None)
            elif name=='set_time':self.game_time=struct.unpack_from('>q',p)[0]
            elif name=='chunk_batch_finished' and not self.loaded:
                self.send('player_loaded');self.loaded=True
            elif name=='set_health':
                self.health=struct.unpack_from('>f',p)[0];self.food,_=wire.read_vi(p,4)
            elif name=='player_combat_kill':self.deaths+=1
            elif name=='level_chunk_with_light':
                cx,cz,minimum,sections=chunk_blocks(p,self.dimension)
                self.chunks[(cx,cz)]=(minimum,sections)
            elif name=='forget_level_chunk':
                cz,cx=struct.unpack_from('>ii',p);self.chunks.pop((cx,cz),None)
                self.blocks={pos:state for pos,state in self.blocks.items() if (pos[0]>>4,pos[2]>>4)!=(cx,cz)}
            elif name=='block_update':
                pos=block_pos(struct.unpack_from('>q',p)[0]);state,_=wire.read_vi(p,8);self.blocks[pos]=state
            elif name=='section_blocks_update':
                packed=struct.unpack_from('>q',p)[0]
                sx=packed>>42;sz=(packed>>20)&0x3fffff;sy=packed&0xfffff
                if sz>=1<<21:sz-=1<<22
                if sy>=1<<19:sy-=1<<20
                count,off=wire.read_vi(p,8)
                for _ in range(count):
                    v=0
                    for i in range(10):
                        b=p[off];off+=1;v|=(b&127)<<(7*i)
                        if b<128:break
                    xyz=v&4095
                    self.blocks[(sx*16+(xyz>>8),sy*16+(xyz&15),sz*16+((xyz>>4)&15))]=v>>12
            elif name=='container_set_slot':
                window,off=wire.read_vi(p);self.revision,off=wire.read_vi(p,off)
                slot=struct.unpack_from('>h',p,off)[0];stack,off=wire.read_stack(p,off+2)
                if window==self.window and 0<=slot<len(self.inventory):self.inventory[slot]=stack
            elif name=='set_cursor_item':self.cursor,_=wire.read_stack(p,0)
            elif name=='set_player_inventory':
                slot,off=wire.read_vi(p);stack,_=wire.read_stack(p,off)
                slot=slot+36 if slot<9 else (45 if slot==40 else (44-slot if 36<=slot<=39 else slot))
                if 0<=slot<len(self.inventory):self.inventory[slot]=stack
        return name,p

    def login(self,name='ParityProbe',information=None):
        self.handshake(2);self.send('hello',wire.string(name)+bytes(16));self.until('login_finished')
        self.send('login_acknowledged');self.state='configuration'
        if information is not None:self.send('client_information',information)
        self.until('select_known_packs');self.send('select_known_packs',b'\0');self.until('finish_configuration')
        self.send('finish_configuration');self.state='play'
        self.entity_id=struct.unpack_from('>i',self.until('login'))[0]
        self.until('chunk_batch_finished');self.tick_barrier()
        return self

    def barrier(self):
        # Vanilla handles ping on the network thread; this confirms transport only.
        token=struct.pack('>q',263777)
        self.send('ping_request',token);self.until('pong_response',lambda p:p==token)

    def tick_barrier(self):
        # Wait for the main simulation thread; a ping alone does not fence command execution.
        self.barrier()
        if self.game_time is None:self.until('set_time')
        previous=self.game_time
        self.until('set_time',lambda p:struct.unpack_from('>q',p)[0]>previous+20)
        self.barrier()

    def block(self,pos):
        key=tuple(pos)
        if key in self.blocks:return self.blocks[key]
        x,y,z=key;chunk=self.chunks.get((x>>4,z>>4))
        if chunk is None:return None
        minimum,sections=chunk
        if not minimum<=y<minimum+len(sections)*16:return 0
        return sections[(y-minimum)>>4][((y&15)<<8)|((z&15)<<4)|(x&15)]

    def snapshot(self,fields,positions=()):
        output={}
        for field in fields:
            if field=='blocks':output[field]={','.join(map(str,p)):self.block(p) for p in positions}
            elif field=='inventory':output[field]=[None if s is None else {k:v for k,v in sorted(s.items()) if k!='damage' or v!=0} for s in self.inventory]
            elif field=='entities':output[field]=sorted(self.entities.values())
            elif field=='registries':output[field]=sum(name=='registry_data' for name,_ in self.seen)
            elif field=='chunk_sections':output[field]=sorted(set(len(x[1]) for x in self.chunks.values()))
            elif field in ('health','food','mode','dimension','cursor','deaths','position','appearance'):output[field]=getattr(self,field)
            else:raise ValueError('Unknown snapshot field: '+field)
        return output
