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
    /** @var array<string, array> decoded light of recent chunks, by "cx:cz" */
    private array $lightLevels=[];
    /** @var array<string, array{0: string, 1: string, 2: string, 3: array<int, int>}> each chunk's dampening, own sky and block levels and shaped cells */
    private array $ownLight=[];
    /** @var array<string, list<string>> chunks whose light was built while these neighbours were not generated */
    private array $builtWithout=[];
    /** @var array<string, string> light data last built for chunks being relit, to tell whether it changed */
    private array $staleLight=[];
    private array $blockEntityMap=[];
    private array $containerPositions=[];
    /** @var array<string, array<int, array>> biome containers /fillbiome rewrote, by chunk and section */
    private array $biomeEdits=[];
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
        $this->db->exec('CREATE TABLE IF NOT EXISTS generation(cx INTEGER NOT NULL,cz INTEGER NOT NULL,legacy INTEGER NOT NULL,PRIMARY KEY(cx,cz)); PRAGMA journal_mode=WAL; PRAGMA synchronous=NORMAL; PRAGMA busy_timeout=3000; CREATE TABLE IF NOT EXISTS blocks(x INTEGER NOT NULL,y INTEGER NOT NULL,z INTEGER NOT NULL,state INTEGER NOT NULL,PRIMARY KEY(x,y,z)); CREATE INDEX IF NOT EXISTS chunk_blocks ON blocks(x,z); CREATE TABLE IF NOT EXISTS metadata(key TEXT PRIMARY KEY,value TEXT NOT NULL); CREATE TABLE IF NOT EXISTS players(uuid TEXT PRIMARY KEY,data TEXT NOT NULL); CREATE TABLE IF NOT EXISTS containers(position TEXT PRIMARY KEY,data TEXT NOT NULL); CREATE TABLE IF NOT EXISTS chunk_mobs(cx INTEGER NOT NULL,cz INTEGER NOT NULL,data TEXT NOT NULL); CREATE INDEX IF NOT EXISTS chunk_mobs_chunk ON chunk_mobs(cx,cz); CREATE TABLE IF NOT EXISTS chunk_biomes(cx INTEGER NOT NULL,cz INTEGER NOT NULL,data TEXT NOT NULL,PRIMARY KEY(cx,cz))');
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
        // The Nether and End recorded no generated chunks, so their edited chunks are recorded as kept instead.
        if(($dimension==='the_nether' || $dimension==='the_end') && $vanillaTerrain && $this->setting('vanilla')===null){$this->transaction(fn()=>$this->adoptVanillaDimension());}
        $this->vanilla=(($dimension==='overworld' && $generator==='terrain') || $dimension!=='overworld') && $this->setting('vanilla')!==null?new Vanilla\ChunkStore($this->db,$version,$this->seed,$dimension,$this->height):null;
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
            if(is_array($player) && isset($player['x'],$player['z']) && ($player['dimension']??'overworld')===$this->dimension){$centres[]=[((int)floor($player['x']))>>4,((int)floor($player['z']))>>4];}
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
    private function adoptVanillaDimension():void {
        $kept=$this->keptChunks();$q=$this->db->prepare('INSERT OR IGNORE INTO generation(cx,cz,legacy) VALUES(?,?,0)');
        foreach(array_keys($kept) as $key){[$cx,$cz]=explode(':',$key);$q->execute([(int)$cx,(int)$cz]);}
        $this->setting('vanilla','26.3');$this->setting('vanillaMigration',['kept'=>count($kept),'regenerated'=>0]);
    }
    /** Save mobs with their unloaded chunk (vanilla's per-chunk entity storage). */
    public function storeChunkMobs(int $cx,int $cz,array $mobs): void {
        $q=$this->db->prepare('INSERT INTO chunk_mobs(cx,cz,data) VALUES(?,?,?)');
        foreach($mobs as $mob){$q->execute([$cx,$cz,json_encode($mob,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION)]);}
    }
    /** The mobs saved with a chunk, removed from storage as its loading takes them back. */
    public function takeChunkMobs(int $cx,int $cz): array {
        $q=$this->db->prepare('SELECT data FROM chunk_mobs WHERE cx=? AND cz=?');$q->execute([$cx,$cz]);
        $mobs=array_map(static fn(string $data):array=>json_decode($data,true,512,JSON_THROW_ON_ERROR),$q->fetchAll(\PDO::FETCH_COLUMN));
        if($mobs!==[]){$this->db->prepare('DELETE FROM chunk_mobs WHERE cx=? AND cz=?')->execute([$cx,$cz]);}
        return $mobs;
    }
    public function setting(string $key,mixed $value=null): mixed {
        if($value!==null){$q=$this->db->prepare('INSERT INTO metadata(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value');$q->execute([$key,json_encode($value,JSON_THROW_ON_ERROR)]);return $value;}
        $q=$this->db->prepare('SELECT value FROM metadata WHERE key=?');$q->execute([$key]);$data=$q->fetchColumn();return $data===false?null:json_decode($data,true,512,JSON_THROW_ON_ERROR);
    }
    public function hashedSeed():int {return unpack('P',substr(hash('sha256',pack('P',$this->seed),true),0,8))[1];}
    public function spawn():array {return $this->setting('spawn')??match($this->dimension){'the_end'=>[100.5,$this->vanilla===null?65.0:49.0,0.5],'the_nether'=>[0.5,72.0,0.5],default=>[0.5,$this->terrain===null?64.0:65.0,0.5]};}
    private function generatorFor(int $cx,int $cz):TerrainGenerator|DimensionGenerator|Vanilla\ChunkStore|null {
        if($this->legacyTerrain===null && ($this->vanilla??null)===null){return $this->terrain;}
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
    /** @return array<int, array> the biome containers /fillbiome rewrote in a chunk, by section */
    public function biomeEdits(int $cx,int $cz):array {
        $key=$cx.':'.$cz;if(isset($this->biomeEdits[$key])){return $this->biomeEdits[$key];}
        $q=$this->db->prepare('SELECT data FROM chunk_biomes WHERE cx=? AND cz=?');$q->execute([$cx,$cz]);$data=$q->fetchColumn();
        if(count($this->biomeEdits)>=64){array_shift($this->biomeEdits);}
        return $this->biomeEdits[$key]=$data===false?[]:json_decode($data,true,512,JSON_THROW_ON_ERROR);
    }
    /** @return list<array> every section's biome container, as rewritten or as generated */
    public function biomeContainers(int $cx,int $cz):array {
        $edits=$this->biomeEdits($cx,$cz);$names=$this->version->registryEntries('minecraft:worldgen/biome');$containers=[];
        $vanilla=$this->vanillaBiomes($cx,$cz);$columns=[];
        if($vanilla===null){for($bz=0;$bz<4;++$bz){for($bx=0;$bx<4;++$bx){$columns[]=substr($names[$this->generatedBiome($cx*16+$bx*4+2,$cz*16+$bz*4+2)],10);}}}
        for($section=0;$section<intdiv($this->height,16);++$section){
            $containers[]=$edits[$section]??BiomeContainer::fromBiomes($vanilla===null?array_merge($columns,$columns,$columns,$columns):array_map(static fn(int $id):string=>substr($names[$id],10),array_slice($vanilla,$section*64,64)));
        }
        return $containers;
    }
    /** Saves a chunk's rewritten biome containers and rebuilds its packet. */
    public function setBiomeContainers(int $cx,int $cz,array $containers):void {
        $key=$cx.':'.$cz;$this->biomeEdits[$key]=$containers;unset($this->cache[$key]);
        $q=$this->db->prepare('INSERT INTO chunk_biomes(cx,cz,data) VALUES(?,?,?) ON CONFLICT(cx,cz) DO UPDATE SET data=excluded.data');$q->execute([$cx,$cz,json_encode($containers,JSON_THROW_ON_ERROR)]);
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
            finally{$this->cache=[];$this->baseCache=[];$this->editCache=[];$this->lightCache=[];$this->lightLevels=[];$this->ownLight=[];$this->staleLight=[];$this->builtWithout=[];$this->generationCache=[];$this->biomeEdits=[];$this->containerPositions=$containers;}
            throw $e;
        }
    }
    public function checkpoint():void {$this->db->exec('PRAGMA wal_checkpoint(PASSIVE)');}
    public function biome(int $x,int $z):int {
        return $this->biomeEdits($x>>4,$z>>4)!==[]?$this->version->registryId('worldgen/biome',$this->column($x,$z)['biome']):$this->generatedBiome($x,$z);
    }
    private function generatedBiome(int $x,int $z):int {
        $generator=$this->generatorFor($x>>4,$z>>4);
        if($generator instanceof Vanilla\ChunkStore){$column=$this->column($x,$z);return $this->version->registryId('worldgen/biome',$column['biome']);}
        return $generator?->biome($x,$z)??$this->version->registryId('worldgen/biome','plains');
    }
    public function column(int $x,int $z):array {
        $column=$this->generatedColumn($x,$z);$edits=$this->biomeEdits($x>>4,$z>>4);
        if($edits!==[]){
            $quartY=min(max(($column['height']-$this->minY)>>2,0),intdiv($this->height,4)-1);
            if(isset($edits[$quartY>>2])){$column['biome']=BiomeContainer::get($edits[$quartY>>2],(($quartY&3)<<4)|((($z&15)>>2)<<2)|(($x&15)>>2));}
        }
        return $column;
    }
    private function generatedColumn(int $x,int $z):array {
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
        $previous=$this->get($x,$y,$z);
        $base=$this->terrain===null?$this->base($y):$this->baseSections($x>>4,$z>>4)[($y-$this->minY)>>4][(($y&15)<<8)|(($z&15)<<4)|($x&15)];
        if($state===$base){$q=$this->db->prepare('DELETE FROM blocks WHERE x=? AND y=? AND z=?');$q->execute([$x,$y,$z]);}
        else{$q=$this->db->prepare('INSERT INTO blocks(x,y,z,state) VALUES(?,?,?,?) ON CONFLICT(x,y,z) DO UPDATE SET state=excluded.state');$q->execute([$x,$y,$z,$state]);}
        $key=($x>>4).':'.($z>>4);unset($this->cache[$key]);
        // A change that blocks or gives light differently relights the chunk and, as light crosses
        // chunk borders, its neighbours.
        if(!Lighting::sameLight($previous,$state,$this->version->catalog,$this->version->directory)){
            unset($this->ownLight[$key]);
            for($dx=-1;$dx<=1;++$dx){for($dz=-1;$dz<=1;++$dz){$this->relight(($x>>4)+$dx,($z>>4)+$dz);}}
        }
        if(isset($this->editCache[$key])){$index=(($y-$this->minY)<<8)|(($z&15)<<4)|($x&15);if($state===$base){unset($this->editCache[$key][$index]);}else{$this->editCache[$key][$index]=$state;}}
    }
    /** Changed blocks in one of these states within the columns, as [x, y, z, state]; generated terrain is not searched. */
    public function editedStates(array $states,int $minX,int $maxX,int $minZ,int $maxZ):array {
        if($states===[]){return [];}
        $q=$this->db->prepare('SELECT x,y,z,state FROM blocks WHERE x BETWEEN ? AND ? AND z BETWEEN ? AND ? AND state IN ('.implode(',',array_fill(0,count($states),'?')).')');
        $q->execute([$minX,$maxX,$minZ,$maxZ,...$states]);
        return array_map(static fn(array $row):array=>array_map('intval',[$row['x'],$row['y'],$row['z'],$row['state']]),$q->fetchAll(\PDO::FETCH_ASSOC));
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
        $vanillaBiomes=$this->vanillaBiomes($cx,$cz);$biomeData=null;$edited=$this->biomeEdits($cx,$cz);$biomeId=fn(string $biome):int=>$this->version->registryId('worldgen/biome',$biome);
        if($vanillaBiomes===null){
            $biomes=[];
            for($bz=0;$bz<4;++$bz){for($bx=0;$bx<4;++$bx){$biomes[]=$this->generatedBiome($cx*16+$bx*4+2,$cz*16+$bz*4+2);}}
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
            $bytes.=pack('nn',$count,$fluids).Palette::blocks($states,$this->globalBits).(isset($edited[$section])?BiomeContainer::encode($edited[$section],$biomeId,$biomeBits):($biomeData??Palette::biomes(array_slice($vanillaBiomes,$section*64,64),$biomeBits)));
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
        $light=$this->areaLight($cx,$cz,$sections);
        $payload=pack('NN',$cx,$cz).$heightmaps.Binary::writeVarInt(strlen($bytes)).$bytes.Binary::writeVarInt(count($entities)).implode('',$entities).$light;
        if(count($this->cache)>=64){$first=array_key_first($this->cache);unset($this->cache[$first],$this->lightCache[$first]);}
        $this->lightCache[$key]=$light;return $this->cache[$key]=$payload;
    }
    public function blockEntityNbt(int $x,int $y,int $z):string {
        $name=$this->version->catalog->blockName($this->get($x,$y,$z));
        if($name==='jigsaw'){
            // JigsawBlockEntity.saveAdditional: its names, pool, final state, joint and priorities.
            $data=$this->container([$x,$y,$z])+['name'=>'minecraft:empty','target'=>'minecraft:empty','pool'=>'minecraft:empty','final_state'=>'minecraft:air','joint'=>'rollable'];
            return Nbt::component(['name'=>(string)$data['name'],'target'=>(string)$data['target'],'pool'=>(string)$data['pool'],'final_state'=>(string)$data['final_state'],'joint'=>(string)$data['joint'],'placement_priority'=>(int)($data['placement_priority']??0),'selection_priority'=>(int)($data['selection_priority']??0)]);
        }
        return str_ends_with($name,'_sign')?Nbt::sign($this->container([$x,$y,$z])):"\x0a\0";
    }
    public function blockEntityType(int $state):?int {return $this->blockEntityMap[$state]??null;}
    /** The block entity's type when BlockEntity.getUpdatePacket sends its data as the block changes, else null. */
    public function blockEntityUpdateType(int $state):?int {
        static $updating=['banner','beacon','brushable_block','campfire','conduit','copper_golem_statue','creaking_heart','decorated_pot','jigsaw','shelf','sign','hanging_sign','skull','mob_spawner','structure_block','test_block','test_instance_block','end_gateway','trial_spawner','vault'];
        static $types=null;
        $types??=array_flip(array_intersect_key($this->version->blocks['block_entity_types'],array_flip($updating)));
        $type=$this->blockEntityMap[$state]??null;
        return $type!==null && isset($types[$type])?$type:null;
    }
    /** BlockAndLightGetter.getRawBrightness: the larger of the chunk's sky light less the sky's darkening and its block light, or block light alone. */
    public function rawBrightness(int $x,int $y,int $z,bool $blockOnly=false,int $skyDarken=0):int {
        if($y<$this->minY || $y>=$this->minY+$this->height){return !$blockOnly && $y>=$this->minY+$this->height && $this->dimension==='overworld'?max(0,15-$skyDarken):0;}
        $key=($x>>4).':'.($z>>4);
        if(!isset($this->lightLevels[$key]) || !isset($this->lightCache[$key])){
            if(count($this->lightLevels)>=64){array_shift($this->lightLevels);}
            $this->lightLevels[$key]=self::decodeLight($this->chunkLight($x>>4,$z>>4));
        }
        $section=(($y-$this->minY)>>4)+1;$index=(($y&15)<<8)|(($z&15)<<4)|($x&15);
        $value=0;
        foreach($blockOnly?[1=>$this->lightLevels[$key][1]]:$this->lightLevels[$key] as $layer=>$arrays){
            if(isset($arrays[$section])){$value=max($value,(ord($arrays[$section][$index>>1])>>(($index&1)*4)&15)-($layer===0?$skyDarken:0));}
        }
        return $value;
    }
    /** @return array{0: int, 1: int} a VarInt and the offset after it */
    private static function varIntAt(string $data,int $offset):array {
        $value=0;$shift=0;
        do{$byte=ord($data[$offset++]);$value|=($byte&0x7f)<<$shift;$shift+=7;}while(($byte&0x80)!==0);
        return [$value,$offset];
    }
    /** @return array{0: array<int, string>, 1: array<int, string>} sky and block nibble arrays by section index */
    private static function decodeLight(string $light):array {
        $offset=0;$masks=[];
        for($i=0;$i<4;++$i){[$length,$offset]=self::varIntAt($light,$offset);$masks[]=unpack('P',str_pad(substr($light,$offset,$length),8,"\0"))[1];$offset+=$length;}
        $out=[[],[]];
        for($kind=0;$kind<2;++$kind){
            [,$offset]=self::varIntAt($light,$offset);
            for($i=0;$i<64;++$i){if(($masks[$kind]>>$i)&1){[$length,$offset]=self::varIntAt($light,$offset);$out[$kind][$i]=substr($light,$offset,$length);$offset+=$length;}}
        }
        return $out;
    }
    /**
     * A chunk's light as the light engine settles it across loaded chunks: its own levels, then
     * light crossing in from the eight neighbours that have been generated.
     */
    private function areaLight(int $cx,int $cz,array $sections):string {
        $hasSky=$this->dimension==='overworld';$area=[];$missing=[];
        for($dz=-1;$dz<=1;++$dz){for($dx=-1;$dx<=1;++$dx){
            $k=($dz+1)*3+$dx+1;
            $area[$k]=$k===4?$this->ownLight($cx,$cz,$sections):$this->ownLight($cx+$dx,$cz+$dz);
            if($area[$k]===null){$missing[]=($cx+$dx).':'.($cz+$dz);}
        }}
        $this->builtWithout[$cx.':'.$cz]=$missing;
        if(count($this->builtWithout)>1024){array_shift($this->builtWithout);}
        return Lighting::buildArea($area,count($sections),$hasSky,$this->version->directory);
    }
    /** @return array{0: string, 1: string, 2: string}|null a generated chunk's dampening and own levels */
    private function ownLight(int $cx,int $cz,?array $sections=null):?array {
        $key=$cx.':'.$cz;
        if(isset($this->ownLight[$key])){$value=$this->ownLight[$key];unset($this->ownLight[$key]);return $this->ownLight[$key]=$value;}
        if($sections===null){
            if(!$this->ready($cx,$cz)){return null;}
            $sections=$this->sections($cx,$cz);
        }
        if(count($this->ownLight)>=320){array_shift($this->ownLight);}
        [$sky,$block]=Lighting::levels($sections,$this->version->catalog,$this->dimension==='overworld',$this->version->directory);
        return $this->ownLight[$key]=[Lighting::dampening($sections,$this->version->catalog,$this->version->directory),$sky,$block,Lighting::shapes($sections,$this->version->directory)];
    }
    /** Settle the own light of a chunk and its generated neighbours ahead of building its packet. */
    public function warmLight(int $cx,int $cz):void {
        for($dz=-1;$dz<=1;++$dz){for($dx=-1;$dx<=1;++$dx){$this->ownLight($cx+$dx,$cz+$dz);}}
    }
    /** Drop a chunk's built light, keeping what it was to compare with once rebuilt. */
    private function relight(int $cx,int $cz):void {
        $key=$cx.':'.$cz;
        if(isset($this->lightCache[$key])){$this->staleLight[$key]??=$this->lightCache[$key];}
        unset($this->cache[$key],$this->lightCache[$key]);
    }
    /** Whether a relit chunk's light differs from what it had before the change. */
    public function lightChanged(int $cx,int $cz):bool {
        $key=$cx.':'.$cz;$before=$this->staleLight[$key]??null;unset($this->staleLight[$key]);
        return $before===null || $before!==$this->chunkLight($cx,$cz);
    }
    /**
     * A chunk was generated or sent: neighbours lit before it existed take its light in.
     *
     * @return list<array{0: int, 1: int}> the neighbours to relight
     */
    public function neighbourArrived(int $cx,int $cz):array {
        $key=$cx.':'.$cz;$out=[];
        for($dz=-1;$dz<=1;++$dz){for($dx=-1;$dx<=1;++$dx){
            $other=($cx+$dx).':'.($cz+$dz);
            if(in_array($key,$this->builtWithout[$other]??[],true)){$this->relight($cx+$dx,$cz+$dz);$out[]=[$cx+$dx,$cz+$dz];unset($this->builtWithout[$other]);}
        }}
        return $out;
    }
    public function chunkLight(int $cx,int $cz):string {$key=$cx.':'.$cz;if(!isset($this->lightCache[$key])){$this->chunk($cx,$cz);}return $this->lightCache[$key];}
    public static function light():string {
        $mask="\4\xff\xff\xff\x03";
        return $mask."\0\0".$mask.Binary::writeVarInt(26).str_repeat(Binary::writeVarInt(2048).str_repeat("\xff",2048),26)."\0";
    }
}
