<?php
declare(strict_types=1);
namespace PhpMinecraftJavaServer\World;
use PhpMinecraftJavaServer\Protocol\{Binary,Version,Nbt};

/** Persisted block edits overlay an immutable, seed-specific procedural generator. */
class World
{
    private \PDO $db;
    private array $cache=[];
    private array $baseCache=[];
    private array $editCache=[];
    private array $generationCache=[];
    private array $lightCache=[];
    private array $blockEntityMap=[];
    private array $containerPositions=[];
    private readonly TerrainGenerator|DimensionGenerator|null $terrain;
    private ?TerrainGenerator $legacyTerrain=null;
    public readonly int $minY;
    public readonly int $height;
    public readonly int $globalBits;
    public readonly int $seed;
    public readonly string $generator;
    public function __construct(private readonly Version $version,string $path,string $generator='flat',int $seed=12345,public readonly string $dimension='overworld') {
        if(!in_array($dimension,['overworld','the_nether','the_end'],true)){throw new \InvalidArgumentException('Unknown dimension');}
        $this->minY=$dimension==='overworld'?-64:0;$this->height=$dimension==='overworld'?384:256;
        if(!in_array($generator,['flat','terrain'],true)){throw new \InvalidArgumentException('Unknown world generator');}
        $exists=is_file($path);$directory=dirname($path);
        if(!is_dir($directory) && !mkdir($directory,0755,true) && !is_dir($directory)){throw new \RuntimeException('Cannot create world directory');}
        $this->db=new \PDO('sqlite:'.$path,null,null,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('CREATE TABLE IF NOT EXISTS generation(cx INTEGER NOT NULL,cz INTEGER NOT NULL,legacy INTEGER NOT NULL,PRIMARY KEY(cx,cz)); PRAGMA journal_mode=WAL; PRAGMA busy_timeout=3000; CREATE TABLE IF NOT EXISTS blocks(x INTEGER NOT NULL,y INTEGER NOT NULL,z INTEGER NOT NULL,state INTEGER NOT NULL,PRIMARY KEY(x,y,z)); CREATE INDEX IF NOT EXISTS chunk_blocks ON blocks(x,z); CREATE TABLE IF NOT EXISTS metadata(key TEXT PRIMARY KEY,value TEXT NOT NULL); CREATE TABLE IF NOT EXISTS players(uuid TEXT PRIMARY KEY,data TEXT NOT NULL); CREATE TABLE IF NOT EXISTS containers(position TEXT PRIMARY KEY,data TEXT NOT NULL)');
        foreach($this->db->query('SELECT position FROM containers')->fetchAll(\PDO::FETCH_COLUMN) as $position){$this->containerPositions[$position]=true;}
        $stored=$this->setting('generator');
        if($stored===null){
            $stored=$exists?'flat':$generator;
            $this->setting('generator',$stored);$this->setting('seed',$seed);
            $this->setting('algorithm',$dimension!=='overworld'?'dimension-v1':($stored==='terrain'?TerrainGenerator::ALGORITHM:'flat-v1'));
            $this->setting('dimension',$dimension);
        }
        if($stored!==$generator){throw new \RuntimeException('Generator differs from saved world; use a new worldPath to change generator');}
        $this->generator=$stored;$this->seed=(int)$this->setting('seed');
        if($generator==='terrain' && $this->seed!==$seed){throw new \RuntimeException('Seed differs from saved world; use a new worldPath to change seed');}
        if(($this->setting('dimension')??'overworld')!==$dimension){throw new \RuntimeException('Dimension differs from saved world');}
        if($dimension==='overworld' && $generator==='terrain' && $this->setting('algorithm')==='terrain-v1'){
            // Preserve all previously explored or edited chunks before enabling new decorations/caves.
            $this->transaction(function(){
                $q=$this->db->prepare('INSERT OR IGNORE INTO generation(cx,cz,legacy) VALUES(?,?,1)');
                foreach(array_keys($this->setting('naturalSpawns')??[]) as $key){$coords=explode(':',$key);$q->execute([(int)$coords[0],(int)$coords[1]]);}
                foreach($this->db->query('SELECT DISTINCT (x >> 4) AS cx,(z >> 4) AS cz FROM blocks') as $row){$q->execute([(int)$row['cx'],(int)$row['cz']]);}
                $this->setting('algorithm',TerrainGenerator::ALGORITHM);
            });
        }
        if($dimension==='overworld' && $generator==='terrain' && $this->setting('algorithm')!==TerrainGenerator::ALGORITHM){throw new \RuntimeException('Unsupported terrain generator version');}
        $this->terrain=$dimension!=='overworld'?new DimensionGenerator($version,$seed,$dimension):($generator==='terrain'?new TerrainGenerator($version,$seed):null);
        if($dimension==='overworld' && $generator==='terrain'){$this->legacyTerrain=new TerrainGenerator($version,$seed,false);}
        foreach($version->catalog->blocks as $name=>$info){
            $type=$info['block_entity_type']??(str_ends_with($name,'_bed')?'bed':(in_array($name,['chest','trapped_chest','barrel','furnace','smoker','blast_furnace'],true)?$name:null));
            if($type!==null && isset($version->blocks['block_entity_types'][$type])){for($state=$info['first'];$state<$info['first']+$info['count'];++$state){$this->blockEntityMap[$state]=$version->blocks['block_entity_types'][$type];}}
        }
        $this->globalBits=(int)ceil(log($version->blocks['state_count'],2));
    }
    public function setting(string $key,mixed $value=null): mixed {
        if($value!==null){$q=$this->db->prepare('INSERT INTO metadata(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value');$q->execute([$key,json_encode($value,JSON_THROW_ON_ERROR)]);return $value;}
        $q=$this->db->prepare('SELECT value FROM metadata WHERE key=?');$q->execute([$key]);$data=$q->fetchColumn();return $data===false?null:json_decode($data,true,512,JSON_THROW_ON_ERROR);
    }
    public function hashedSeed():int {return unpack('P',substr(hash('sha256',pack('P',$this->seed),true),0,8))[1];}
    public function spawn():array {return $this->setting('spawn')??match($this->dimension){'the_end'=>[100.5,65.0,0.5],'the_nether'=>[0.5,72.0,0.5],default=>[0.5,$this->terrain===null?64.0:65.0,0.5]};}
    private function generatorFor(int $cx,int $cz):TerrainGenerator|DimensionGenerator|null {
        if($this->legacyTerrain===null){return $this->terrain;}
        $key=$cx.':'.$cz;if(isset($this->generationCache[$key])){return $this->generationCache[$key];}
        $q=$this->db->prepare('SELECT legacy FROM generation WHERE cx=? AND cz=?');$q->execute([$cx,$cz]);$legacy=$q->fetchColumn();
        if($legacy===false){$q=$this->db->prepare('INSERT INTO generation(cx,cz,legacy) VALUES(?,?,0)');$q->execute([$cx,$cz]);}
        if(count($this->generationCache)>=128){array_shift($this->generationCache);}
        return $this->generationCache[$key]=$legacy!==false && (int)$legacy===1?$this->legacyTerrain:$this->terrain;
    }
    public function loadPlayer(string $uuid):?array {
        $q=$this->db->prepare('SELECT data FROM players WHERE uuid=?');$q->execute([bin2hex($uuid)]);$data=$q->fetchColumn();return $data===false?null:json_decode($data,true,512,JSON_THROW_ON_ERROR);
    }
    public function savePlayer(string $uuid,array $data):void {
        $q=$this->db->prepare('INSERT INTO players(uuid,data) VALUES(?,?) ON CONFLICT(uuid) DO UPDATE SET data=excluded.data');$q->execute([bin2hex($uuid),json_encode($data,JSON_THROW_ON_ERROR)]);
    }
    public function container(array $position,?array $data=null):array {
        $key=implode(':',$position);
        if($data!==null){unset($this->cache[($position[0]>>4).':'.($position[2]>>4)]);$this->containerPositions[$key]=true;$q=$this->db->prepare('INSERT INTO containers(position,data) VALUES(?,?) ON CONFLICT(position) DO UPDATE SET data=excluded.data');$q->execute([$key,json_encode($data,JSON_THROW_ON_ERROR)]);return $data;}
        $q=$this->db->prepare('SELECT data FROM containers WHERE position=?');$q->execute([$key]);$bytes=$q->fetchColumn();return $bytes===false?['slots'=>[],'burn'=>0,'cook'=>0,'fuelTotal'=>0,'position'=>$position]:json_decode($bytes,true,512,JSON_THROW_ON_ERROR);
    }
    public function deleteContainer(array $position):void {unset($this->containerPositions[implode(':',$position)]);$q=$this->db->prepare('DELETE FROM containers WHERE position=?');$q->execute([implode(':',$position)]);}
    public function transaction(callable $action):void {
        $containers=$this->containerPositions;$this->db->beginTransaction();
        try{$action();$this->db->commit();}
        catch(\Throwable $e){
            try{if($this->db->inTransaction()){$this->db->rollBack();}}
            finally{$this->cache=[];$this->baseCache=[];$this->editCache=[];$this->lightCache=[];$this->generationCache=[];$this->containerPositions=$containers;}
            throw $e;
        }
    }
    public function checkpoint():void {$this->db->exec('PRAGMA wal_checkpoint(PASSIVE)');}
    public function biome(int $x,int $z):int {return $this->generatorFor($x>>4,$z>>4)?->biome($x,$z)??$this->version->registryId('worldgen/biome','plains');}
    public function column(int $x,int $z):array {return $this->generatorFor($x>>4,$z>>4)?->column($x,$z)??['height'=>63,'biome'=>'plains'];}
    public function base(int $y):int {return match($y){60=>$this->version->blocks['states']['bedrock'],61,62=>$this->version->blocks['states']['dirt'],63=>$this->version->blocks['states']['grass_block'],default=>0};}
    private function baseSections(int $cx,int $cz):array {
        $key=$cx.':'.$cz;if(isset($this->baseCache[$key])){return $this->baseCache[$key];}
        if($this->terrain!==null){$sections=$this->generatorFor($cx,$cz)->chunk($cx,$cz);}
        else{
            $sections=array_fill(0,24,array_fill(0,4096,0));
            for($y=60;$y<=63;++$y){for($index=0;$index<256;++$index){$sections[7][(($y&15)<<8)|$index]=$this->base($y);}}
        }
        if(count($this->baseCache)>=32){array_shift($this->baseCache);}
        return $this->baseCache[$key]=$sections;
    }
    public function get(int $x,int $y,int $z):int {
        if($y<$this->minY || $y>=$this->minY+$this->height){return 0;}
        $edits=$this->chunkEdits($x>>4,$z>>4);$index=(($y-$this->minY)<<8)|(($z&15)<<4)|($x&15);
        if(isset($edits[$index])){return $edits[$index];}
        if($this->terrain===null){return $this->base($y);}
        $sections=$this->baseSections($x>>4,$z>>4);return $sections[($y-$this->minY)>>4][(($y&15)<<8)|(($z&15)<<4)|($x&15)];
    }
    private function chunkEdits(int $cx,int $cz):array {
        $key=$cx.':'.$cz;if(isset($this->editCache[$key])){return $this->editCache[$key];}
        $q=$this->db->prepare('SELECT x,y,z,state FROM blocks WHERE x BETWEEN ? AND ? AND z BETWEEN ? AND ?');$q->execute([$cx*16,$cx*16+15,$cz*16,$cz*16+15]);$edits=[];
        while($edit=$q->fetch(\PDO::FETCH_ASSOC)){$index=(((int)$edit['y']-$this->minY)<<8)|(((int)$edit['z']&15)<<4)|((int)$edit['x']&15);$edits[$index]=(int)$edit['state'];}
        if(count($this->editCache)>=32){array_shift($this->editCache);}
        return $this->editCache[$key]=$edits;
    }
    public function set(int $x,int $y,int $z,int $state):void {
        if($y<$this->minY || $y>=$this->minY+$this->height || abs($x)>29999984 || abs($z)>29999984 || $state<0 || $state>=$this->version->blocks['state_count']){throw new \InvalidArgumentException('Invalid block');}
        if(isset($this->containerPositions[$x.':'.$y.':'.$z]) && $this->version->catalog->blockName($state)!==$this->version->catalog->blockName($this->get($x,$y,$z))){$this->deleteContainer([$x,$y,$z]);}
        $base=$this->terrain===null?$this->base($y):$this->baseSections($x>>4,$z>>4)[($y-$this->minY)>>4][(($y&15)<<8)|(($z&15)<<4)|($x&15)];
        if($state===$base){$q=$this->db->prepare('DELETE FROM blocks WHERE x=? AND y=? AND z=?');$q->execute([$x,$y,$z]);}
        else{$q=$this->db->prepare('INSERT INTO blocks(x,y,z,state) VALUES(?,?,?,?) ON CONFLICT(x,y,z) DO UPDATE SET state=excluded.state');$q->execute([$x,$y,$z,$state]);}
        $key=($x>>4).':'.($z>>4);unset($this->cache[$key],$this->lightCache[$key]);
        if(isset($this->editCache[$key])){$index=(($y-$this->minY)<<8)|(($z&15)<<4)|($x&15);if($state===$base){unset($this->editCache[$key][$index]);}else{$this->editCache[$key][$index]=$state;}}
    }
    public function sections(int $cx,int $cz):array {
        $sections=$this->baseSections($cx,$cz);
        foreach($this->chunkEdits($cx,$cz) as $index=>$state){$sections[$index>>12][$index&4095]=$state;}
        return $sections;
    }
    public function chunk(int $cx,int $cz):string {
        $key=$cx.':'.$cz;if(isset($this->cache[$key])){return $this->cache[$key];}
        $sections=$this->sections($cx,$cz);$catalog=$this->version->catalog;$surface=array_fill(0,256,0);$motion=$surface;$bytes='';$entities=[];
        $biomes=[];
        for($bz=0;$bz<4;++$bz){for($bx=0;$bx<4;++$bx){$biomes[]=$this->biome($cx*16+$bx*4+2,$cz*16+$bz*4+2);}}
        $biomeData=Palette::biomes(array_merge($biomes,$biomes,$biomes,$biomes),(int)ceil(log($this->version->registryCount('worldgen/biome'),2)));
        foreach($sections as $section=>$states){
            $count=0;$fluids=0;
            foreach($states as $index=>$state){
                if($catalog->air($state)){continue;}++$count;if($catalog->fluid($state)){++$fluids;}
                if(isset($this->blockEntityMap[$state])){$entities[]=chr((($index&15)<<4)|(($index>>4)&15)).pack('n',$section*16+$this->minY+($index>>8)).Binary::writeVarInt($this->blockEntityMap[$state]).$this->blockEntityNbt($cx*16+($index&15),$section*16+$this->minY+($index>>8),$cz*16+(($index>>4)&15));}
                $column=$index&255;$height=$section*16+($index>>8)+1;
                $surface[$column]=max($surface[$column],$height);
                if($catalog->motionBlocking($state)){$motion[$column]=max($motion[$column],$height);}
            }
            $bytes.=pack('nn',$count,$fluids).Palette::blocks($states,$this->globalBits).$biomeData;
        }
        $heightmaps="\2";
        foreach([1=>$surface,4=>$motion] as $type=>$values){$packed=Palette::pack($values,9);$heightmaps.=Binary::writeVarInt($type).Binary::writeVarInt(intdiv(strlen($packed),8)).$packed;}
        $light=$this->terrain===null?self::light():Lighting::build($sections,$surface,$catalog,$this->dimension!=='the_nether');
        $payload=pack('NN',$cx,$cz).$heightmaps.Binary::writeVarInt(strlen($bytes)).$bytes.Binary::writeVarInt(count($entities)).implode('',$entities).$light;
        if(count($this->cache)>=64){$first=array_key_first($this->cache);unset($this->cache[$first],$this->lightCache[$first]);}
        $this->lightCache[$key]=$light;return $this->cache[$key]=$payload;
    }
    public function blockEntityNbt(int $x,int $y,int $z):string {
        $name=$this->version->catalog->blockName($this->get($x,$y,$z));
        return str_ends_with($name,'_sign')?Nbt::sign($this->container([$x,$y,$z])):"\x0a\0";
    }
    public function blockEntityType(int $state):?int {return $this->blockEntityMap[$state]??null;}
    public function chunkLight(int $cx,int $cz):string {$key=$cx.':'.$cz;if(!isset($this->lightCache[$key])){$this->chunk($cx,$cz);}return $this->lightCache[$key];}
    public static function light():string {
        $mask="\4\xff\xff\xff\x03";
        return $mask."\0\0".$mask.Binary::writeVarInt(26).str_repeat(Binary::writeVarInt(2048).str_repeat("\xff",2048),26)."\0";
    }
}
