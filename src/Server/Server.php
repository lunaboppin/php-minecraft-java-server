<?php
declare(strict_types=1);
namespace Jva\Server;
use Jva\Protocol\{Binary, Framing, Nbt, Packets, ProtocolException, Version};
use Jva\World\World;

final class Server
{
    use InventoryManagement, Entities, Survival, Commands, Animals, Dimensions;
    private mixed $listener = null;
    /** @var array<int,Client> */
    private array $clients = [];
    private bool $running = true;
    private int $nextEntity = 1;
    private int $ticks = 0;
    private array $pendingLight=[];
    private World $world;
    public function __construct(private readonly Config $config, private readonly Version $version = new Version()) {
        $this->world = new World($version,$config->worldPath,$config->generator,$config->seed);
        $this->worlds['overworld']=$this->world;
        foreach(['the_nether','the_end'] as $dimension){$path=$config->worldPath.'.'.$dimension.'.sqlite';$this->worlds[$dimension]=new World($version,$path,'terrain',$config->seed,$dimension);}
        foreach(array_keys($this->worlds) as $dimension){$this->withDimension($dimension,fn()=>$this->restoreAnimals());}
        $this->crafting = new \Jva\World\Crafting($version->directory);
        foreach(['worldAge','dayTime','daylightCycle','weather','keepInventory','naturalRegeneration'] as $key){$saved=$this->world->setting($key);if($saved!==null){$this->$key=$saved;}}
    }
    public function stop(): void { $this->running = false; }
    public function run(?int $maxTicks = null): void {
        $host = str_contains($this->config->host, ':') ? '['.$this->config->host.']' : $this->config->host;
        $this->listener = @stream_socket_server('tcp://'.$host.':'.$this->config->port,$errno,$error);
        if ($this->listener === false) { throw new \RuntimeException('Cannot listen: ' . $error); }
        stream_set_blocking($this->listener,false);
        self::log('Listening on ' . stream_socket_get_name($this->listener,false) . ' (Minecraft '.Version::NAME.', protocol '.Version::PROTOCOL.')');
        self::log('Offline authentication: usernames are unverified. World: '.$this->config->worldPath);
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM,fn() => $this->stop());
            pcntl_signal(SIGINT,fn() => $this->stop());
        }
        $nextTick = self::now();
        try {
            while ($this->running && ($maxTicks === null || $this->ticks < $maxTicks)) {
                $read = [$this->listener]; $write = [];
                foreach ($this->clients as $client) {
                    if (!$client->closing) { $read[] = $client->socket; }
                    if ($client->output !== '') { $write[] = $client->socket; }
                }
                $except = null;
                $wait = min(50000,max(0,(int)(($nextTick-self::now())*1000000)));
                foreach ($this->clients as $client) { if ($client->bufferedWork && !$client->closing) { $wait = min($wait,1000); } }
                $ready = @stream_select($read,$write,$except,0,$wait);
                if ($ready !== false) {
                    foreach ($read as $socket) {
                        if ($socket === $this->listener) { $this->accept(); continue; }
                        $id = (int)$socket;
                        if (!isset($this->clients[$id])) { continue; }
                        $client = $this->clients[$id];
                        $bytes = @fread($socket,65536);
                        if ($bytes === false || ($bytes === '' && feof($socket))) { $this->remove($id); continue; }
                        $client->input .= $bytes;
                        if ($bytes !== '') { $client->lastRead = self::now(); }
                        if (strlen($client->input) > Framing::MAX_PACKET + 65536) { $this->remove($id); }
                    }
                    foreach ($write as $socket) { if (isset($this->clients[(int)$socket])) { $this->flush($this->clients[(int)$socket]); } }
                }
                foreach ($this->clients as $id => $client) {
                    if ($client->closing) { continue; }
                    try {
                        for ($i=0;$i<128;++$i) {
                            $frame = Framing::extract($client->input,$client->compression);
                            if ($frame === null) { break; }
                            $now = self::now();
                            if ($now - $client->packetWindow >= 1) { $client->packetWindow = $now; $client->packetsThisSecond = 0; }
                            if (++$client->packetsThisSecond > 1000) { throw new ProtocolException('Packet rate exceeded'); }
                            $this->packet($client,new Binary($frame));
                            if ($client->closing) { break; }
                        }
                        $client->bufferedWork = $i === 128 && $client->input !== '';
                    } catch (ProtocolException|\InvalidArgumentException $e) {
                        self::log('Protocol error from '.$client->peer.': '.$e->getMessage());
                        $this->disconnect($client,'Invalid packet: '.$e->getMessage());
                    }
                }
                $now = self::now();
                if ($now >= $nextTick) { $this->tick($now); ++$this->ticks; $nextTick = max($nextTick+0.05,$now); }
            }
        } finally {
            foreach ($this->clients as $client) {
                if($client->state==='play'){$this->closeWindow($client);$this->savePlayer($client);}
                $this->disconnect($client,'Server shutting down');
                $this->flush($client);
                if (is_resource($client->socket)) { fclose($client->socket); }
            }
            $this->clients = [];
            $this->saveWorld();
            fclose($this->listener);
            self::log('Server stopped');
        }
    }
    private static function now(): float { return hrtime(true)/1e9; }
    private static function log(string $text): void { fwrite(STDOUT,'['.gmdate('H:i:s').'] '.$text.PHP_EOL); }
    private function accept(): void {
        for ($i=0;$i<16;++$i) {
            $socket = @stream_socket_accept($this->listener,0,$peer);
            if ($socket === false) { break; }
            if (count($this->clients) >= max(128,$this->config->maxPlayers*2)) { fclose($socket); continue; }
            stream_set_blocking($socket,false);
            stream_set_read_buffer($socket,0);
            stream_set_write_buffer($socket,0);
            $this->clients[(int)$socket] = new Client($socket,$peer);
        }
    }
    private function flush(Client $client): void {
        if ($client->output === '') { return; }
        $written = @fwrite($client->socket,substr($client->output,0,65536));
        if ($written === false) { $this->remove((int)$client->socket); return; }
        $client->output = substr($client->output,$written);
    }
    private function packet(Client $c, Binary $r): void {
        $id = $r->varInt();
        $name = $this->version->name($c->state,$id);
        $state = $c->state;
        try {
            match ($state) {
                'handshake' => $this->handshake($c,$name,$r),
                'status' => $this->status($c,$name,$r),
                'login' => $this->login($c,$name,$r),
                'configuration' => $this->configuration($c,$name,$r),
                'play' => $this->withDimension($c->dimension,fn()=>$this->play($c,$name,$r)),
                default => throw new ProtocolException('Invalid connection state'),
            };
        } catch (ProtocolException $e) {
            throw new ProtocolException($state . '/' . $name . ': ' . $e->getMessage(), 0, $e);
        }
    }
    private function handshake(Client $c, string $name, Binary $r): void {
        if ($name !== 'intention') { throw new ProtocolException('Expected handshake'); }
        $protocol = $r->varInt(); $r->string(255); $r->ushort(); $next = $r->varInt(); $r->end();
        if ($next !== 1 && $next !== 2) { throw new ProtocolException('Unsupported handshake intention'); }
        $c->state = $next === 1 ? 'status' : 'login';
        if ($next === 2 && $protocol !== Version::PROTOCOL) { $this->disconnect($c,'Use Minecraft Java '.Version::NAME.' (protocol '.Version::PROTOCOL.')'); }
    }
    private function status(Client $c, string $name, Binary $r): void {
        if ($name === 'status_request') {
            $r->end(); $sample = [];
            foreach ($this->players() as $player) { $sample[] = ['name'=>$player->name,'id'=>Binary::formatUuid($player->uuid)]; }
            $json = json_encode(['version'=>['name'=>Version::NAME,'protocol'=>Version::PROTOCOL],'players'=>['max'=>$this->config->maxPlayers,'online'=>count($sample),'sample'=>array_slice($sample,0,12)],'description'=>['text'=>$this->config->motd],'enforcesSecureChat'=>false],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
            $c->send($this->version,'status_response',Binary::writeString($json));
        } elseif ($name === 'ping_request') {
            $payload = $r->bytes(8); $r->end();
            $c->send($this->version,'pong_response',$payload); $this->closeAfterWrite($c);
        } else { throw new ProtocolException('Unexpected status packet'); }
    }
    private function login(Client $c, string $name, Binary $r): void {
        if ($name === 'hello' && !$c->loginStarted) {
            $username = $r->string(16); $r->bytes(16); $r->end();
            if (preg_match('/\A[A-Za-z0-9_]{1,16}\z/D',$username) !== 1) { throw new ProtocolException('Invalid username'); }
            $count = 0;
            foreach ($this->clients as $other) {
                if ($other !== $c && !$other->closing && $other->name !== '') {
                    ++$count;
                    if (strcasecmp($other->name,$username) === 0) { $this->disconnect($c,'That username is already connected'); return; }
                }
            }
            if ($count >= $this->config->maxPlayers) { $this->disconnect($c,'Server is full'); return; }
            $c->name = $username; $c->uuid = Binary::offlineUuid($username); $c->loginStarted = true;
            if ($this->config->compressionThreshold >= 0) {
                $c->send($this->version,'login_compression',Binary::writeVarInt($this->config->compressionThreshold));
                $c->compression = $this->config->compressionThreshold;
            }
            $c->send($this->version,'login_finished',Packets::loginFinished($c->uuid,$c->name,random_bytes(16)));
        } elseif ($name === 'login_acknowledged' && $c->loginStarted) {
            $r->end(); $c->state = 'configuration';
            $c->send($this->version,'custom_payload',Binary::writeString('minecraft:brand').Binary::writeString('PHP Minecraft Server'));
            $c->send($this->version,'update_enabled_features',"\1".Binary::writeString('minecraft:vanilla'));
            // No shared-pack assumption: always send complete NBT from the official codecs.
            $c->send($this->version,'select_known_packs',"\0"); $c->knownPacksRequested = true;
        } else { throw new ProtocolException('Unexpected login packet'); }
    }
    private function configuration(Client $c, string $name, Binary $r): void {
        if ($name === 'select_known_packs') {
            if (!$c->knownPacksRequested || $c->registriesSent || $r->varInt() !== 0) { throw new ProtocolException('Unexpected known packs response'); }
            $r->end();
            foreach ($this->version->registryPackets() as $payload) { $c->send($this->version,'registry_data',$payload); }
            $c->send($this->version,'update_tags',$this->version->tags());
            $c->send($this->version,'finish_configuration'); $c->registriesSent = true;
        } elseif ($name === 'finish_configuration' && $c->registriesSent) {
            $r->end(); $c->state = 'play'; $this->join($c);
        } elseif (!in_array($name,['client_information','custom_payload','resource_pack'],true)) { throw new ProtocolException('Unexpected configuration packet'); }
    }
    /** @return array<int,Client> */
    private function players(): array { return array_filter($this->clients,static fn(Client $c): bool => $c->state === 'play' && !$c->closing); }
    private function join(Client $c): void {
        $c->entityId = $this->nextEntity++;
        $this->restorePlayer($c);
        $this->withDimension($c->dimension,fn()=>$this->joinWorld($c));
    }
    private function joinWorld(Client $c):void {
        $c->send($this->version,'login',Packets::playLogin($this->version,$c->entityId,$this->config->maxPlayers,$this->config->viewDistance,$c->gameMode,$this->world->generator==='flat' && $c->dimension==='overworld',$this->world->hashedSeed(),$c->dimension,array_keys($this->worlds)));
        $c->send($this->version,'change_difficulty',chr($this->config->difficulty)."\1");
        $this->abilities($c);
        $c->send($this->version,'set_default_spawn_position',Packets::spawn($this->worlds['overworld']->spawn()));
        $this->health($c);
        $this->syncInventory($c);
        $c->send($this->version,'set_held_slot',Binary::writeVarInt($c->heldSlot));
        $this->sendClock($c);
        $this->sendWeather($c);
        $this->commands($c);
        foreach ($this->players() as $other) {
            $c->send($this->version,'player_info_update',Packets::playerInfo($other->uuid,$other->name,$other->gameMode));
            if ($other !== $c) {
                $other->send($this->version,'player_info_update',Packets::playerInfo($c->uuid,$c->name,$c->gameMode));
                $this->spawnPlayer($c,$other); $this->spawnPlayer($other,$c);
            }
        }
        $c->send($this->version,'game_event',"\x0d".pack('G',0));
        $this->teleport($c,[$c->x,$c->y,$c->z]);
        if($c->dead){$c->send($this->version,'player_combat_kill',Binary::writeVarInt($c->entityId).Nbt::text($c->name.' died'));}
        $this->broadcast($c->name.' joined the game');
        $c->send($this->version,'system_chat',Packets::chat('Use /help. Your progress and block edits are saved.'));
        self::log($c->name.' joined from '.$c->peer);
    }
    private function spawnPlayer(Client $recipient, Client $player): void {
        if($recipient->dimension!==$player->dimension){return;}
        $recipient->send($this->version,'add_entity',Packets::addPlayer($player->entityId,$player->uuid,$this->version->blocks['player_entity_type'],$player->x,$player->y,$player->z,$player->yaw,$player->pitch));
    }
    private function teleport(Client $c,?array $position=null): void {
        if($c->dimension!==$this->world->dimension){$this->withDimension($c->dimension,fn()=>$this->teleport($c,$position));return;}
        [$c->x,$c->y,$c->z] = $position??$this->world->spawn();
        $c->fallDistance=0;$c->mining=null;
        $c->teleport = ++$c->teleportCounter; $c->teleportAt = self::now();
        $c->send($this->version,'player_position',Packets::position($c->teleport,$c->x,$c->y,$c->z,$c->yaw,$c->pitch));
        $this->scheduleChunks($c);
    }
    private function scheduleChunks(Client $c): void {
        $cx = (int)floor($c->x/16); $cz = (int)floor($c->z/16);
        $c->chunkX = $cx; $c->chunkZ = $cz; $wanted = []; $queue = [];
        $view = $this->config->viewDistance;
        for ($z=$cz-$view;$z<=$cz+$view;++$z) { for ($x=$cx-$view;$x<=$cx+$view;++$x) {
            $key=$x.':'.$z; $wanted[$key]=true;
            if (!isset($c->loadedChunks[$key])) { $queue[$key]=[$x,$z]; }
        } }
        uasort($queue,static fn(array $a,array $b): int => (($a[0]-$cx)**2+($a[1]-$cz)**2) <=> (($b[0]-$cx)**2+($b[1]-$cz)**2));
        foreach ($c->loadedChunks as $key => [$x,$z]) {
            if (!isset($wanted[$key])) { $c->send($this->version,'forget_level_chunk',pack('NN',$z,$x)); unset($c->loadedChunks[$key]); }
        }
        $c->queuedChunks = $queue;
        $c->send($this->version,'set_chunk_cache_center',Binary::writeVarInt($cx).Binary::writeVarInt($cz));
    }
    private function play(Client $c, string $name, Binary $r): void {
        switch ($name) {
            case 'accept_teleportation':
                $confirmation=Packets::readTeleportConfirmation($r);$id=$confirmation['id'];
                if ($c->teleport === null || $id !== $c->teleport) { throw new ProtocolException('Invalid teleport confirmation'); }
                $c->teleport = null; return;
            case 'keep_alive':
                $id=$r->long();$r->end();
                if ($c->keepAlive === null || $id !== $c->keepAlive) { throw new ProtocolException('Invalid keep-alive'); }
                $c->keepAlive=null; return;
            case 'chunk_batch_received':
                $rate=$r->float();$r->end();
                if (!is_finite($rate) || $rate <= 0) { throw new ProtocolException('Invalid chunk batch acknowledgement'); }
                $c->awaitingBatch=false; return;
            case 'move_player_pos': case 'move_player_pos_rot': case 'move_player_rot': case 'move_player_status_only':
                $this->movement($c,$name,$r);return;
            case 'chat':
                $message=$r->string(256);
                // Signed chat is received but relayed as unsigned system chat.
                $r->long();$r->long(); if ($r->bool()) { $r->bytes(256); }
                $r->varInt();$r->bytes(3);$r->byte();$r->end();
                if (self::now()-$c->chatAt<0.5) { return; } $c->chatAt=self::now();
                if (preg_match('/[\x00-\x1f\x7f\x{00a7}]/u',$message)) { throw new ProtocolException('Invalid chat characters'); }
                $this->broadcast('<'.$c->name.'> '.$message);return;
            case 'chat_command':
                $command=$r->string(256);$r->end();$this->command($c,$command);return;
            case 'player_action': $this->dig($c,$r);return;
            case 'use_item_on': $this->place($c,$r);return;
            case 'use_item':
                $hand=$r->varInt();$sequence=$r->varInt();$r->float();$r->float();$r->end();
                if ($hand<0 || $hand>1 || $sequence<0) { throw new ProtocolException('Invalid use item'); }
                $this->useHeldItem($c,$hand);
                $c->send($this->version,'block_changed_ack',Binary::writeVarInt($sequence));return;
            case 'set_carried_item':
                $slot=$r->short();$r->end();if ($slot<0 || $slot>8) { throw new ProtocolException('Invalid hotbar slot'); }$c->heldSlot=$slot;$c->eating=null;return;
            case 'set_creative_mode_slot': $this->creativeSlot($c,$r);return;
            case 'ping_request': $payload=$r->bytes(8);$r->end();$c->send($this->version,'pong_response',$payload);return;
            case 'player_abilities': $flags=$r->byte();$r->end();$c->flying=$c->gameMode===3 || ($c->gameMode===1 && ($flags&2)!==0);$c->fallDistance=0;$this->abilities($c);return;
            case 'client_command': $action=$r->varInt();$r->end();if($action===0){$this->respawnPlayer($c);}return;
            case 'container_click': $this->inventoryClick($c,$r);return;
            case 'container_close': $window=$r->varInt();$r->end();if($window===$c->windowId){$this->closeWindow($c);$this->syncInventory($c);}return;
            case 'player_input': $flags=$r->byte();$r->end();$c->sprinting=($flags&64)!==0;$c->sneaking=($flags&32)!==0;return;
            case 'attack': $id=$r->varInt();$r->end();$this->attackEntity($c,$id);return;
            default:
                // Optional client telemetry and unsupported interactions have no world effects.
                return;
        }
    }
    private function movement(Client $c,string $name,Binary $r): void {
        $x=$c->x;$y=$c->y;$z=$c->z;$yaw=$c->yaw;$pitch=$c->pitch;
        if ($name==='move_player_pos' || $name==='move_player_pos_rot') { $x=$r->double();$y=$r->double();$z=$r->double(); }
        if ($name==='move_player_rot' || $name==='move_player_pos_rot') { $yaw=$r->float();$pitch=$r->float(); }
        $flags=$r->byte();$r->end();
        foreach ([$x,$y,$z,$yaw,$pitch] as $v) { if (!is_finite($v)) { throw new ProtocolException('Non-finite movement'); } }
        if (abs($x)>29999900 || abs($z)>29999900 || abs($y)>20000000 || $flags>3) { throw new ProtocolException('Movement out of bounds'); }
        if ($c->teleport!==null) { return; }
        if (($x-$c->x)**2+($y-$c->y)**2+($z-$c->z)**2>256**2) { $this->teleport($c);return; }
        if($c->dead){return;}
        $oldY=$c->y;$distance=sqrt(($x-$c->x)**2+($z-$c->z)**2);
        $c->x=$x;$c->y=$y;$c->z=$z;$c->yaw=$yaw;$c->pitch=$pitch;$c->ground=($flags&1)!==0;
        $this->survivalMovement($c,$oldY,$distance);
        if ($y < -80 && in_array($c->gameMode,[1,3],true)) { $this->teleport($c); }
        if ((int)floor($c->x/16)!==$c->chunkX || (int)floor($c->z/16)!==$c->chunkZ) { $this->scheduleChunks($c); }
        foreach ($this->players() as $other) { if ($other!==$c && $other->dimension===$c->dimension) { $other->send($this->version,'teleport_entity',Packets::teleportEntity($c->entityId,$c->x,$c->y,$c->z,$c->yaw,$c->pitch,$c->ground)); } }
    }
    private function editable(Client $c,int $x,int $y,int $z): bool {
        return !$c->dead && in_array($c->gameMode,[0,1],true) && $c->teleport===null && $y>=$this->world->minY && $y<$this->world->minY+$this->world->height && isset($c->loadedChunks[($x>>4).':'.($z>>4)])
            && ($x+0.5-$c->x)**2+($y+0.5-($c->y+1.62))**2+($z+0.5-$c->z)**2<=64;
    }
    private function dig(Client $c,Binary $r): void {
        $action=$r->varInt();[$x,$y,$z]=$r->position();$face=$r->byte();$sequence=$r->varInt();$r->end();
        if($action<0 || $action>8 || $face>5 || $sequence<0){throw new ProtocolException('Invalid digging action');}
        if($action===2){$c->mining=null;}
        elseif($action===6){$c->eating=null;}
        elseif($action===4 || $action===5){$slot=36+$c->heldSlot;if(isset($c->inventory[$slot]) && $c->gameMode!==3){$stack=$c->inventory[$slot];$count=$action===4?$stack['count']:1;$this->dropStack($c->x,$c->y+0.5,$c->z,array_replace($stack,['count'=>$count]));\Jva\World\Inventory::consume($c->inventory,$slot,$count);$this->syncInventory($c);}}
        elseif($action===7){[$main,$off]=[$c->inventory[36+$c->heldSlot]??null,$c->inventory[45]??null];if($off===null){unset($c->inventory[36+$c->heldSlot]);}else{$c->inventory[36+$c->heldSlot]=$off;}if($main===null){unset($c->inventory[45]);}else{$c->inventory[45]=$main;}$this->syncInventory($c);}
        elseif($action===0 && $this->editable($c,$x,$y,$z)){
            $state=$this->world->get($x,$y,$z);
            if($c->gameMode===1){$this->breakBlock($c,$x,$y,$z);}
            elseif(!$this->version->catalog->air($state)){$c->mining=['position'=>[$x,$y,$z],'state'=>$state,'started'=>$this->ticks,'duration'=>$this->miningTicks($c,$state)];}
        }
        elseif($action===3 && $c->mining!==null && $c->mining['position']===[$x,$y,$z] && $this->ticks-$c->mining['started']>=$c->mining['duration']-1){$this->breakBlock($c,$x,$y,$z);$c->mining=null;}
        if($action<=3){$this->blockUpdate($c,$x,$y,$z);}
        $c->send($this->version,'block_changed_ack',Binary::writeVarInt($sequence));
    }
    private function place(Client $c,Binary $r): void {
        $hand=$r->varInt();[$x,$y,$z]=$r->position();$face=$r->varInt();$cursor=[$r->float(),$r->float(),$r->float()];
        foreach($cursor as $value){if(!is_finite($value) || $value<0 || $value>1){throw new ProtocolException('Invalid hit position');}}
        $r->bool();$r->bool();$sequence=$r->varInt();$r->end();
        if($face<0 || $face>5 || $hand<0 || $hand>1 || $sequence<0){throw new ProtocolException('Invalid placement');}
        $slot=$hand===0?36+$c->heldSlot:45;$stack=$c->inventory[$slot]??null;
        $clicked=$this->world->get($x,$y,$z);$name=$this->version->catalog->blockName($clicked);
        $within=!$c->dead && ($x+0.5-$c->x)**2+($y+0.5-$c->y-1.62)**2+($z+0.5-$c->z)**2<=64;
        if($within && $name==='end_portal_frame' && $stack!==null && $this->version->catalog->itemName($stack['item'])==='ender_eye' && $this->insertEndEye($c,$x,$y,$z,$slot)){$c->send($this->version,'block_changed_ack',Binary::writeVarInt($sequence));return;}
        if($within && !$c->sneaking && $c->gameMode!==3){
            $type=match($name){'crafting_table'=>'crafting','furnace'=>'furnace','chest','barrel'=>'chest',default=>null};
            if($type!==null){$this->openWindow($c,$type,[$x,$y,$z]);$c->send($this->version,'block_changed_ack',Binary::writeVarInt($sequence));return;}
            $properties=$this->version->catalog->properties($clicked);
            if(isset($properties['open'])){
                $properties['open']=$properties['open']==='true'?'false':'true';$this->setBlock($x,$y,$z,$this->version->catalog->state($name,$properties));
                if(str_ends_with($name,'_door')){$otherY=$y+(($properties['half']??'lower')==='lower'?1:-1);$other=$this->version->catalog->properties($this->world->get($x,$otherY,$z));if($this->version->catalog->blockName($this->world->get($x,$otherY,$z))===$name){$other['open']=$properties['open'];$this->setBlock($x,$otherY,$z,$this->version->catalog->state($name,$other));}}
                $c->send($this->version,'block_changed_ack',Binary::writeVarInt($sequence));return;
            }
            if(str_ends_with($name,'_bed') && $c->dimension==='overworld'){$c->personalSpawn=[$x+0.5,(float)$y+1,$z+0.5];$this->savePlayer($c);$this->tell($c,'Respawn point set');$c->send($this->version,'block_changed_ack',Binary::writeVarInt($sequence));return;}
        }
        [$dx,$dy,$dz]=[[0,-1,0],[0,1,0],[0,0,-1],[0,0,1],[-1,0,0],[1,0,0]][$face];
        $replace=$this->version->catalog->blocks[$name]['replaceable'];
        [$tx,$ty,$tz]=$replace?[$x,$y,$z]:[$x+$dx,$y+$dy,$z+$dz];
        $itemName=$stack===null?null:$this->version->catalog->itemName($stack['item']);
        if($itemName==='flint_and_steel' && $this->ignitePortal($c,$tx,$ty,$tz,$slot)){$c->send($this->version,'block_changed_ack',Binary::writeVarInt($sequence));return;}
        if($itemName==='water_bucket' && $c->dimension==='the_nether'){$this->tell($c,'Water evaporates in the Nether.');$c->send($this->version,'block_changed_ack',Binary::writeVarInt($sequence));return;}
        $blockName=match($itemName){'water_bucket'=>'water','lava_bucket'=>'lava',default=>$itemName};
        $block=$this->version->catalog->blocks[$blockName??'']??null;
        if($block!==null && $stack!==null && $this->editable($c,$tx,$ty,$tz) && $this->version->catalog->blocks[$this->version->catalog->blockName($this->world->get($tx,$ty,$tz))]['replaceable']){
            $properties=[];$facing=['south','west','north','east'][((int)floor($c->yaw/90+0.5))&3];
            if(isset($block['properties']['axis'])){$properties['axis']=$face<2?'y':($face<4?'z':'x');}
            if(isset($block['properties']['facing']) && in_array($facing,$block['properties']['facing']['values'],true)){$properties['facing']=$facing;}
            if(isset($block['properties']['half']) && in_array('top',$block['properties']['half']['values'],true)){$properties['half']=$face===0 || ($face>1 && $cursor[1]>0.5)?'top':'bottom';}
            if(str_ends_with($blockName,'_slab')){$properties['type']=$face===0 || ($face>1 && $cursor[1]>0.5)?'top':'bottom';}
            if(isset($block['properties']['waterlogged'])){$properties['waterlogged']=$this->version->catalog->blockName($this->world->get($tx,$ty,$tz))==='water'?'true':'false';}
            $blocked=false;
            if($block['full_cube']){foreach($this->localPlayers() as $player){if($player->gameMode!==3 && !$player->dead && $tx+1>$player->x-0.3 && $tx<$player->x+0.3 && $tz+1>$player->z-0.3 && $tz<$player->z+0.3 && $ty+1>$player->y && $ty<$player->y+1.8){$blocked=true;break;}}}
            $bed=str_ends_with($blockName,'_bed');$bedOffset=null;
            if($bed){$properties['part']='foot';$bedOffset=['north'=>[0,-1],'south'=>[0,1],'west'=>[-1,0],'east'=>[1,0]][$facing];if(!$this->editable($c,$tx+$bedOffset[0],$ty,$tz+$bedOffset[1]) || !$this->version->catalog->air($this->world->get($tx+$bedOffset[0],$ty,$tz+$bedOffset[1]))){$blocked=true;}}
            $door=str_ends_with($blockName,'_door');if($door && ($ty>=$this->world->minY+$this->world->height-1 || !$this->version->catalog->air($this->world->get($tx,$ty+1,$tz)))){$blocked=true;}
            if(!$blocked){
                $this->setBlock($tx,$ty,$tz,$this->version->catalog->state($blockName,$properties));
                if($bed){$head=$properties;$head['part']='head';$this->setBlock($tx+$bedOffset[0],$ty,$tz+$bedOffset[1],$this->version->catalog->state($blockName,$head));}
                if($door){$properties['half']='upper';$this->setBlock($tx,$ty+1,$tz,$this->version->catalog->state($blockName,$properties));}
                if($c->gameMode===0){\Jva\World\Inventory::consume($c->inventory,$slot);if(str_ends_with($itemName,'_bucket')){$this->giveStack($c,['item'=>$this->version->blocks['items']['bucket'],'count'=>1]);}$this->syncInventory($c);}
            }
        }
        $this->blockUpdate($c,$tx,$ty,$tz);$c->send($this->version,'block_changed_ack',Binary::writeVarInt($sequence));
    }
    private function creativeSlot(Client $c,Binary $r): void {
        $slot=$r->short();$count=$r->varInt();if($c->gameMode!==1){$this->syncInventory($c);return;}
        if($slot===-1){return;}if($slot<1 || $slot>45 || $count<0 || $count>99){throw new ProtocolException('Invalid creative slot');}
        $item=-1;$accepted=true;
        if($count>0){$item=$r->varInt();$added=$r->varInt();$removed=$r->varInt();$info=$this->version->catalog->item($item);$accepted=$info!==null && $count<=$info['max_stack'] && $added===0 && $removed===0;if($added===0 && $removed===0){$r->end();}}
        else{$r->end();}
        if($accepted && $count>0){$c->inventory[$slot]=['item'=>$item,'count'=>$count];}else{unset($c->inventory[$slot]);}
        $c->send($this->version,'container_set_slot',"\0".Binary::writeVarInt(++$c->inventoryRevision).pack('n',$slot).Packets::stack($c->inventory[$slot]??null,$this->version->blocks['component_damage']));
        if(!$accepted){$this->tell($c,'Custom component patches are not supported in creative inventory.');}
    }
    private function setBlock(int $x,int $y,int $z,int $state): void {
        $this->world->set($x,$y,$z,$state);
        if($this->world->generator==='terrain'){$this->pendingLight[($x>>4).':'.($z>>4)]=[$x>>4,$z>>4];}
        foreach ($this->localPlayers() as $player) { if (isset($player->loadedChunks[($x>>4).':'.($z>>4)])) { $this->blockUpdate($player,$x,$y,$z); } }
    }
    private function blockUpdate(Client $c,int $x,int $y,int $z): void {
        $c->send($this->version,'block_update',Binary::writePosition($x,$y,$z).Binary::writeVarInt($this->world->get($x,$y,$z)));
        $type=$this->world->blockEntityType($this->world->get($x,$y,$z));
        if($type!==null){$c->send($this->version,'block_entity_data',Binary::writePosition($x,$y,$z).Binary::writeVarInt($type)."\x0a\0");}
    }
    private function broadcast(string $message): void {
        foreach ($this->players() as $player) { $player->send($this->version,'system_chat',Packets::chat($message)); }
    }
    private function tick(float $now): void {
        ++$this->worldAge;if($this->daylightCycle){++$this->dayTime;}
        $this->tickDimensions();
        if($this->ticks%200===0){$this->saveWorld();}
        foreach ($this->clients as $id=>$c) {
            if ($c->closing) {
                if ($c->output==='' || $now-$c->closingAt>2) { $this->remove($id); }
                continue;
            }
            if (($c->state!=='play' && $now-$c->connectedAt>30) || $now-$c->lastRead>60 || ($c->keepAlive!==null && $now-$c->lastKeepAlive>30) || ($c->teleport!==null && $now-$c->teleportAt>30) || ($c->awaitingBatch && $now-$c->batchAt>30)) { $this->disconnect($c,'Connection timed out');continue; }
            if ($c->state!=='play') { continue; }
            try {
                if ($c->keepAlive===null && $now-$c->lastKeepAlive>=15) {
                    $c->keepAlive=random_int(1,PHP_INT_MAX);$c->lastKeepAlive=$now;
                    $c->send($this->version,'keep_alive',pack('J',$c->keepAlive));
                }
                $this->withDimension($c->dimension,function()use($c,$now):void{
                $this->tickPortals($c);
                if($c->dimension!==$this->world->dimension){return;}
                $this->tickSurvival($c);
                if ($this->ticks%20===0) { $this->sendClock($c); }
                if ($c->queuedChunks!==[] && !$c->awaitingBatch && strlen($c->output)<1048576) {
                    $c->send($this->version,'chunk_batch_start');$count=0;
                    foreach (array_slice($c->queuedChunks,0,2,true) as $key=>[$x,$z]) {
                        $c->send($this->version,'level_chunk_with_light',$this->world->chunk($x,$z));
                        $c->loadedChunks[$key]=[$x,$z];$this->naturalAnimals($x,$z);unset($c->queuedChunks[$key]);++$count;
                    }
                    $c->send($this->version,'chunk_batch_finished',Binary::writeVarInt($count));$c->awaitingBatch=true;$c->batchAt=$now;
                }
                });
            } catch (ProtocolException $e) { $this->disconnect($c,$e->getMessage()); }
        }
    }
    private function closeAfterWrite(Client $c): void { $c->closing=true;$c->closingAt=self::now(); }
    private function disconnect(Client $c,string $reason): void {
        if ($c->closing) { return; }
        if (in_array($c->state,['login','configuration','play'],true)) {
            $payload=$c->state==='login'?Binary::writeString(json_encode(['text'=>$reason],JSON_THROW_ON_ERROR)):Nbt::text($reason);
            try { $c->send($this->version,$c->state==='login'?'login_disconnect':'disconnect',$payload); } catch (ProtocolException) { $c->output=''; }
        }
        $this->closeAfterWrite($c);
    }
    private function remove(int $id): void {
        $c=$this->clients[$id]??null;if ($c===null) { return; }
        unset($this->clients[$id]);
        if($c->state==='play'){$this->closeWindow($c);$this->savePlayer($c);}
        fclose($c->socket);
        if ($c->state==='play') {
            foreach ($this->players() as $other) {
                try {
                    $other->send($this->version,'remove_entities',"\1".Binary::writeVarInt($c->entityId));
                    $other->send($this->version,'player_info_remove',"\1".$c->uuid);
                } catch (ProtocolException) { $this->closeAfterWrite($other); }
            }
            $this->broadcast($c->name.' left the game');self::log($c->name.' disconnected');
        }
    }
}
