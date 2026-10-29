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
    /** Vanilla 26.3 generation for chunks first generated after it was enabled (generation.legacy=2). */
    public readonly ?Vanilla\ChunkStore $vanilla;
    public readonly int $minY;
    public readonly int $height;
    public readonly int $globalBits;
    public readonly int $seed;
    public readonly string $generator;
    public function __construct(private readonly Version $version,string $path,string $generator='flat',int $seed=12345,public readonly string $dimension='overworld',bool $vanillaTerrain=false) {
        if(!in_array($dimension,['overworld','the_nether','the_end'],true)){throw new \InvalidArgumentException('Unknown dimension');}
        $this->minY=$dimension==='overworld'?-64:0;$this->height=$dimension==='overworld'?384:256;
        if(!in_array($generator,['flat','terrain'],true)){throw new \InvalidArgumentException('Unknown world generator');}
        $exists=is_file($path);$directory=dirname($path);
        if(!is_dir($directory) && !mkdir($directory,0755,true) && !is_dir($directory)){throw new \RuntimeException('Cannot create world directory');}
        $this->db=new \PDO('sqlite:'.$path,null,null,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('CREATE TABLE IF NOT EXISTS generation(cx INTEGER NOT NULL,cz INTEGER NOT NULL,legacy INTEGER NOT NULL,PRIMARY KEY(cx,cz)); PRAGMA journal_mode=WAL; PRAGMA synchronous=NORMAL; PRAGMA busy_timeout=3000; CREATE TABLE IF NOT EXISTS blocks(x INTEGER NOT NULL,y INTEGER NOT NULL,z INTEGER NOT NULL,state INTEGER NOT NULL,PRIMARY KEY(x,y,z)); CREATE INDEX IF NOT EXISTS chunk_blocks ON blocks(x,z); CREATE TABLE IF NOT EXISTS metadata(key TEXT PRIMARY KEY,value TEXT NOT NULL); CREATE TABLE IF NOT EXISTS players(uuid TEXT PRIMARY KEY,data TEXT NOT NULL); CREATE TABLE IF NOT EXISTS containers(position TEXT PRIMARY KEY,data TEXT NOT NULL)');
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
        // Enabling vanilla terrain keeps every chunk players changed, with a margin, and the
        // chunks players are standing in; all other chunks are generated again with vanilla.
        if($dimension==='overworld' && $generator==='terrain' && $vanillaTerrain && $this->setting('vanilla')===null){$this->transaction(fn()=>$this->adoptVanillaTerrain());}
        $this->vanilla=$dimension==='overworld' && $generator==='terrain' && $this->setting('vanilla')!==null?new Vanilla\ChunkStore($this->db,$version,$this->seed):null;
        if($this->vanilla!==null && $this->setting('vanillaSpawnPending')){
            // The spawn surface is refined to the decorated column once the chunk exists.
            $this->setting('spawn',$this->vanilla->findSpawn());
            $this->db->prepare('DELETE FROM metadata WHERE key=?')->execute(['vanillaSpawnPending']);
        }
        foreach($version->catalog->blocks as $name=>$info){
            $type=$info['block_entity_type']??(str_ends_with($name,'_bed')?'bed':(in_array($name,['chest','trapped_chest','barrel','furnace','smoker','blast_furnace'],true)?$name:null));
            if($type!==null && isset($version->blocks['block_entity_types'][$type])){for($state=$info['first'];$state<$info['first']+$info['count'];++$state){$this->blockEntityMap[$state]=$version->blocks['block_entity_types'][$type];}}
        }
        $this->globalBits=(int)ceil(log($version->blocks['state_count'],2));
    }
    /** @return array<string,true> chunks to keep on the old terrain: edits, containers and players, each with one chunk around them */
    private function keptChunks():array {
        $centres=[];
        foreach($this->db->query('SELECT DISTINCT (x >> 4) AS cx,(z >> 4) AS cz FROM blocks') as $row){$centres[]=[(int)$row['cx'],(int)$row['cz']];}
        foreach(array_keys($this->containerPositions) as $position){$p=array_map('intval',explode(':',$position));$centres[]=[$p[0]>>4,$p[2]>>4];}
        foreach($this->db->query('SELECT data FROM players') as $row){
            $player=json_decode($row['data'],true);
            if(is_array($player) && isset($player['x'],$player['z']) && ($player['dimension']??'overworld')==='overworld'){$centres[]=[((int)floor($player['x']))>>4,((int)floor($player['z']))>>4];}
        }
        $kept=[];
        foreach($centres as [$cx,$cz]){for($dz=-1;$dz<=1;++$dz){for($dx=-1;$dx<=1;++$dx){$kept[($cx+$dx).':'.($cz+$dz)]=true;}}}
        return $kept;
    }
    private function adoptVanillaTerrain():void {
        $kept=$this->keptChunks();$update=$this->db->prepare('UPDATE generation SET legacy=2 WHERE cx=? AND cz=?');$moved=0;
        foreach($this->db->query('SELECT cx,cz FROM generation WHERE legacy<>2')->fetchAll(\PDO::FETCH_NUM) as [$cx,$cz]){
            if(!isset($kept[$cx.':'.$cz])){$update->execute([$cx,$cz]);++$moved;}
        }
        // Spawn moves to vanilla land unless the old spawn area was kept.
        if($this->setting('spawn')===null && !isset($kept['0:0'])){$this->setting('vanillaSpawnPending',true);}
        $this->setting('vanilla','26.3');$this->setting('vanillaMigration',['kept'=>count($kept),'regenerated'=>$moved]);
    }
    public function setting(string $key,mixed $value=null): mixed {
        if($value!==null){$q=$this->db->prepare('INSERT INTO metadata(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value');$q->execute([$key,json_encode($value,JSON_THROW_ON_ERROR)]);return $value;}
        $q=$this->db->prepare('SELECT value FROM metadata WHERE key=?');$q->execute([$key]);$data=$q->fetchColumn();return $data===false?null:json_decode($data,true,512,JSON_THROW_ON_ERROR);
    }
    public function hashedSeed():int {return unpack('P',substr(hash('sha256',pack('P',$this->seed),true),0,8))[1];}
    public function spawn():array {return $this->setting('spawn')??match($this->dimension){'the_end'=>[100.5,65.0,0.5],'the_nether'=>[0.5,72.0,0.5],default=>[0.5,$this->terrain===null?64.0:65.0,0.5]};}
    private function generatorFor(int $cx,int $cz):TerrainGenerator|DimensionGenerator|Vanilla\ChunkStore|null {
        if($this->legacyTerrain===null){return $this->terrain;}
        $key=$cx.':'.$cz;if(isset($this->generationCache[$key])){return $this->generationCache[$key];}
        $q=$this->db->prepare('SELECT legacy FROM generation WHERE cx=? AND cz=?');$q->execute([$cx,$cz]);$legacy=$q->fetchColumn();
        if($legacy===false){$legacy=$this->vanilla!==null?2:0;$q=$this->db->prepare('INSERT INTO generation(cx,cz,legacy) VALUES(?,?,?)');$q->execute([$cx,$cz,$legacy]);}
        if(count($this->generationCache)>=128){array_shift($this->generationCache);}
        return $this->generationCache[$key]=match((int)$legacy){1=>$this->legacyTerrain,2=>$this->vanilla??$this->terrain,default=>$this->terrain};
    }
    /** Whether a chunk can be built without generating: vanilla chunks wait for the generation workers. */
    public function ready(int $cx,int $cz):bool {
        $generator=$this->generatorFor($cx,$cz);
        return !$generator instanceof Vanilla\ChunkStore || $generator->ready($cx,$cz);
    }
    public function requestGeneration(int $cx,int $cz,int $priority,float $keepFor=15.0):void {
        $generator=$this->generatorFor($cx,$cz);
        if($generator instanceof Vanilla\ChunkStore){$generator->request($cx,$cz,$priority,$keepFor);}
    }
    /** Vanilla quart biomes of a chunk as registry ids, in y, z, x order, or null for other generators. */
    private function vanillaBiomes(int $cx,int $cz):?array {
        $generator=$this->generatorFor($cx,$cz);
        if(!$generator instanceof Vanilla\ChunkStore){return null;}
        return array_map(fn(string $biome):int=>$this->version->registryId('worldgen/biome',$biome),$generator->final($cx,$cz)[1]);
    }
    public function loadPlayer(string $uuid,bool $online=false):?array {
        $q=$this->db->prepare('SELECT data FROM players WHERE uuid=?');$q->execute([($online?'online:':'').bin2hex($uuid)]);$data=$q->fetchColumn();return $data===false?null:json_decode($data,true,512,JSON_THROW_ON_ERROR);
    }
    public function savePlayer(string $uuid,array $data,bool $online=false):void {
        $q=$this->db->prepare('INSERT INTO players(uuid,data) VALUES(?,?) ON CONFLICT(uuid) DO UPDATE SET data=excluded.data');$q->execute([($online?'online:':'').bin2hex($uuid),json_encode($data,JSON_THROW_ON_ERROR)]);
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
    public function biome(int $x,int $z):int {
        $generator=$this->generatorFor($x>>4,$z>>4);
        if($generator instanceof Vanilla\ChunkStore){$column=$this->column($x,$z);return $this->version->registryId('worldgen/biome',$column['biome']);}
        return $generator?->biome($x,$z)??$this->version->registryId('worldgen/biome','plains');
    }
    public function column(int $x,int $z):array {
        $generator=$this->generatorFor($x>>4,$z>>4);
        if($generator instanceof Vanilla\ChunkStore){
            [$blocks,$biomes]=$generator->final($x>>4,$z>>4);$catalog=$this->version->catalog;$column=(($z&15)<<4)|($x&15);$height=$this->minY;
            for($y=$this->height-1;$y>=0;--$y){$state=$blocks[($y<<8)|$column];if(!$catalog->air($state) && !$catalog->fluid($state)){$height=$y+$this->minY;break;}}
            $quartY=min(max(($height-$this->minY)>>2,0),intdiv($this->height,4)-1);
            return ['height'=>$height,'biome'=>$biomes[($quartY<<4)|((($z&15)>>2)<<2)|(($x&15)>>2)]];
        }
        return $generator?->column($x,$z)??['height'=>63,'biome'=>'plains'];
    }
    public function base(int $y):int {return match($y){60=>$this->version->blocks['states']['bedrock'],61,62=>$this->version->blocks['states']['dirt'],63=>$this->version->blocks['states']['grass_block'],default=>0};}
    private function baseSections(int $cx,int $cz):array {
        $key=$cx.':'.$cz;if(isset($this->baseCache[$key])){return $this->baseCache[$key];}
        $generator=$this->terrain!==null?$this->generatorFor($cx,$cz):null;
        if($generator instanceof Vanilla\ChunkStore){$sections=array_chunk($generator->final($cx,$cz)[0],4096);}
        elseif($generator!==null){$sections=$generator->chunk($cx,$cz);}
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
        // Reads never start vanilla generation; a chunk still being generated reads as air.
        if($this->vanilla!==null && !isset($this->baseCache[($x>>4).':'.($z>>4)]) && !$this->ready($x>>4,$z>>4)){return 0;}
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
        $biomeBits=(int)ceil(log($this->version->registryCount('worldgen/biome'),2));
        $vanillaBiomes=$this->vanillaBiomes($cx,$cz);$biomeData=null;
        if($vanillaBiomes===null){
            $biomes=[];
            for($bz=0;$bz<4;++$bz){for($bx=0;$bx<4;++$bx){$biomes[]=$this->biome($cx*16+$bx*4+2,$cz*16+$bz*4+2);}}
            $biomeData=Palette::biomes(array_merge($biomes,$biomes,$biomes,$biomes),$biomeBits);
        }
        // Counts come from each section's distinct states; only sections holding block entities are
        // scanned per block, and heightmaps are found top-down, skipping all-air sections.
        $flags=$catalog->flags;$empty=[];
        foreach($sections as $section=>$states){
            $count=0;$fluids=0;$hasEntities=false;
            foreach(array_count_values($states) as $state=>$number){
                $flag=$flags[$state]??0;
                if(($flag&1)!==0){continue;}
                $count+=$number;if(($flag&4)!==0){$fluids+=$number;}
                if(isset($this->blockEntityMap[$state])){$hasEntities=true;}
            }
            $empty[$section]=$count===0;
            if($hasEntities){
                foreach($states as $index=>$state){
                    if(isset($this->blockEntityMap[$state]) && (($flags[$state]??0)&1)===0){$entities[]=chr((($index&15)<<4)|(($index>>4)&15)).pack('n',$section*16+$this->minY+($index>>8)).Binary::writeVarInt($this->blockEntityMap[$state]).$this->blockEntityNbt($cx*16+($index&15),$section*16+$this->minY+($index>>8),$cz*16+(($index>>4)&15));}
                }
            }
            $bytes.=pack('nn',$count,$fluids).Palette::blocks($states,$this->globalBits).($biomeData??Palette::biomes(array_slice($vanillaBiomes,$section*64,64),$biomeBits));
        }
        for($column=0;$column<256;++$column){
            $needSurface=true;$needMotion=true;
            for($y=count($sections)*16-1;$y>=0 && ($needSurface || $needMotion);--$y){
                if($empty[$y>>4]){$y&=~15;continue;}
                $flag=$flags[$sections[$y>>4][(($y&15)<<8)|$column]]??0;
                if(($flag&1)!==0){continue;}
                if($needSurface){$surface[$column]=$y+1;$needSurface=false;}
                if($needMotion && ($flag&6)!==0){$motion[$column]=$y+1;$needMotion=false;}
            }
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
