<?php
declare(strict_types=1);
namespace Jva\Protocol;

/** Payloads for protocol 777; packet IDs come from Mojang's generated report. */
final class Packets
{
    public static function loginFinished(string $uuid, string $name, string $session): string {
        return $uuid . Binary::writeString($name, 16) . "\0" . $session;
    }
    public static function playLogin(Version $v, int $entityId, int $maxPlayers, int $view, int $gameMode = 1, bool $flat = true, int $hashedSeed = 0, string $dimension = 'overworld',array $levels=['overworld']): string {
        return pack('N', $entityId) . "\0" . Binary::writeVarInt(count($levels)).implode('',array_map(static fn(string $key):string=>Binary::writeString('minecraft:'.$key),$levels))
            . Binary::writeVarInt($maxPlayers) . Binary::writeVarInt($view) . Binary::writeVarInt($view)
            . "\0\1\0" . Binary::writeVarInt($v->registryId('dimension_type', $dimension))
            . Binary::writeString('minecraft:'.$dimension) . pack('J', $hashedSeed)
            . Binary::writeVarInt($gameMode) . "\0\0" . chr((int)$flat) . "\0\0" . Binary::writeVarInt($dimension==='the_nether'?32:($dimension==='the_end'?0:63)) . "\0\0";
    }
    public static function stack(?array $stack, int $damageComponent = 0): string {
        if($stack===null || $stack['count']<=0){return "\0";}
        $out=Binary::writeVarInt($stack['count']).Binary::writeVarInt($stack['item']);
        $damage=$stack['damage']??0;
        return $out.($damage>0?"\1\0".Binary::writeVarInt($damageComponent).Binary::writeVarInt($damage):"\0\0");
    }
    public static function inventory(int $window,int $revision,array $items,?array $cursor,int $damageComponent):string {
        $out=Binary::writeVarInt($window).Binary::writeVarInt($revision).Binary::writeVarInt(count($items));
        foreach($items as $item){$out.=self::stack($item,$damageComponent);}return $out.self::stack($cursor,$damageComponent);
    }
    public static function respawn(Version $version,int $mode,bool $flat=true,int $hashedSeed=0,string $dimension='overworld'):string {
        return Binary::writeVarInt($version->registryId('dimension_type',$dimension)).Binary::writeString('minecraft:'.$dimension).pack('J',$hashedSeed).Binary::writeVarInt($mode)."\0\0".chr((int)$flat)."\0\0".Binary::writeVarInt($dimension==='the_nether'?32:($dimension==='the_end'?0:63))."\0";
    }
    public static function vitals(Catalog $catalog,int $entityId,int $air,int $flags):string {
        $meta=$catalog->entityMetadata;
        return Binary::writeVarInt($entityId).chr($meta['air_index']).Binary::writeVarInt($meta['int_serializer']).Binary::writeVarInt($air)
            .chr($meta['flags_index']).Binary::writeVarInt($meta['byte_serializer']).chr($flags)."\xff";
    }
    public static function moveRotation(float $x, float $y, float $z, float $yaw, float $pitch): string {
        return pack('EEEEEEGG', $x, $y, $z, 0, 0, 0, $yaw, $pitch);
    }
    public static function position(int $teleportId, float $x, float $y, float $z, float $yaw = 0, float $pitch = 0): string {
        return Binary::writeVarInt($teleportId) . self::moveRotation($x, $y, $z, $yaw, $pitch) . pack('N', 0);
    }
    /** Protocol 777 confirms the teleport ID, three doubles and two floats. */
    public static function readTeleportConfirmation(Binary $reader): array {
        $id = $reader->varInt();
        $x = $reader->double(); $y = $reader->double(); $z = $reader->double();
        $yaw = $reader->float(); $pitch = $reader->float();
        $reader->end();
        foreach ([$x, $y, $z, $yaw, $pitch] as $value) {
            if (!is_finite($value)) { throw new ProtocolException('Non-finite teleport confirmation'); }
        }
        if ($id < 0 || abs($x) > 29999900 || abs($z) > 29999900 || abs($y) > 20000000) {
            throw new ProtocolException('Teleport confirmation out of bounds');
        }
        return ['id' => $id, 'x' => $x, 'y' => $y, 'z' => $z, 'yaw' => $yaw, 'pitch' => $pitch];
    }
    public static function spawn(array $position = [0,64,0],string $dimension='overworld'): string {
        return Binary::writeString('minecraft:'.$dimension) . Binary::writePosition((int)floor($position[0]),(int)floor($position[1]),(int)floor($position[2])) . pack('GG', 0, 0);
    }
    public static function chat(string $text): string { return Nbt::text($text) . "\0"; }
    public static function time(int $ticks): string { return pack('J', $ticks) . "\0"; }
    public static function clockTime(Version $version, int $ticks, ?int $dayTime = null, bool $advancing = true): string {
        return pack('J',$ticks) . "\1" . Binary::writeVarInt($version->registryId('world_clock','overworld'))
            . Binary::writeVarLong($dayTime??($ticks+6000)) . pack('GG',0,$advancing?1:0);
    }
    public static function playerInfo(string $uuid, string $name, int $gameMode = 1): string {
        // ADD_PLAYER | UPDATE_GAME_MODE | UPDATE_LISTED | UPDATE_LATENCY | UPDATE_HAT
        return "\x9d\1" . $uuid . Binary::writeString($name,16) . "\0" . Binary::writeVarInt($gameMode) . "\1\0\1";
    }
    public static function teleportEntity(int $id, float $x, float $y, float $z, float $yaw, float $pitch, bool $ground): string {
        return Binary::writeVarInt($id) . self::moveRotation($x, $y, $z, $yaw, $pitch) . pack('N', 0) . chr((int)$ground);
    }
    public static function addPlayer(int $id, string $uuid, int $typeId, float $x, float $y, float $z, float $yaw, float $pitch): string {
        return Binary::writeVarInt($id) . $uuid . Binary::writeVarInt($typeId) . pack('EEE', $x,$y,$z)
            . "\0" . chr(((int)floor($pitch * 256 / 360)) & 255) . chr(((int)floor($yaw * 256 / 360)) & 255)
            . chr(((int)floor($yaw * 256 / 360)) & 255) . "\0";
    }
}
