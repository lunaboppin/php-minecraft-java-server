<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Protocol;

/** Payloads for protocol 777; packet IDs come from Mojang's generated report. */
final class Packets
{
    /** ClientInformation uses signed view distance, wrapped enum IDs and nine fields in 26.3. */
    public static function readClientInformation(Binary $r): array
    {
        $language = $r->string(16);
        $view = $r->byte();
        if ($view >= 128) {
            $view -= 256;
        }
        $chat = $r->varInt();
        $colors = $r->byte() !== 0;
        $skin = $r->byte();
        $arm = $r->varInt();
        $filter = $r->byte() !== 0;
        $listing = $r->byte() !== 0;
        $particles = $r->varInt();
        $r->end();
        return ['language' => $language, 'viewDistance' => $view, 'chatVisibility' => (($chat % 3) + 3) % 3,
            'chatColors' => $colors, 'skinParts' => $skin, 'mainHand' => $arm === 1 ? 1 : 0,
            'textFiltering' => $filter, 'allowsListing' => $listing, 'particles' => (($particles % 3) + 3) % 3];
    }

    public static function playerAppearance(Catalog $catalog, int $entityId, int $skinParts, int $mainHand): string
    {
        $meta = $catalog->entityMetadata;
        return Binary::writeVarInt($entityId) . chr($meta['skin_index']) . Binary::writeVarInt($meta['byte_serializer']) . chr($skinParts)
            . chr($meta['main_hand_index']) . Binary::writeVarInt($meta['main_hand_serializer']) . Binary::writeVarInt($mainHand) . "\xff";
    }

    public static function profileProperties(array $properties): string
    {
        $out = Binary::writeVarInt(count($properties));
        foreach ($properties as $p) {
            $out .= Binary::writeString($p['name']) . Binary::writeString($p['value']) . chr((int) isset($p['signature']));
            if (isset($p['signature'])) {
                $out .= Binary::writeString($p['signature']);
            }
        }
        return $out;
    }

    public static function loginFinished(string $uuid, string $name, string $session, array $properties = []): string
    {
        return $uuid . Binary::writeString($name, 16) . self::profileProperties($properties) . $session;
    }

    public static function playLogin(Version $v, int $entityId, int $maxPlayers, int $view, int $gameMode = 1, bool $flat = true, int $hashedSeed = 0, string $dimension = 'overworld', array $levels = ['overworld'], ?int $simulation = null, bool $reducedDebugInfo = false, bool $immediateRespawn = false, bool $limitedCrafting = false): string
    {
        return pack('N', $entityId) . "\0" . Binary::writeVarInt(count($levels)) . implode('', array_map(static fn (string $key): string => Binary::writeString('minecraft:' . $key), $levels))
            . Binary::writeVarInt($maxPlayers) . Binary::writeVarInt($view) . Binary::writeVarInt($simulation ?? $view)
            // reducedDebugInfo, showDeathScreen (unless respawning immediately) and doLimitedCrafting.
            . chr((int) $reducedDebugInfo) . chr((int) !$immediateRespawn) . chr((int) $limitedCrafting) . Binary::writeVarInt($v->registryId('dimension_type', $dimension))
            . Binary::writeString('minecraft:' . $dimension) . pack('J', $hashedSeed)
            . Binary::writeVarInt($gameMode) . "\0\0" . chr((int) $flat) . "\0\0" . Binary::writeVarInt($dimension === 'the_nether' ? 32 : ($dimension === 'the_end' ? 0 : 63)) . "\0\0";
    }

    public static function stack(?array $stack, int $damageComponent = 0, ?ItemStackCodec $codec = null): string
    {
        if ($codec !== null) {
            return $codec->write($stack);
        }
        if ($stack === null || $stack['count'] <= 0) {
            return "\0";
        }
        $out = Binary::writeVarInt($stack['count']) . Binary::writeVarInt($stack['item']);
        $damage = $stack['damage'] ?? 0;
        return $out . ($damage > 0 ? "\1\0" . Binary::writeVarInt($damageComponent) . Binary::writeVarInt($damage) : "\0\0");
    }

    public static function inventory(
        int $window,
        int $revision,
        array $items,
        ?array $cursor,
        int $damageComponent,
        ?ItemStackCodec $codec = null
    ): string {
        $out = Binary::writeVarInt($window) . Binary::writeVarInt($revision) . Binary::writeVarInt(count($items));
        foreach ($items as $item) {
            $out .= self::stack($item, $damageComponent, $codec);
        }
        return $out . self::stack($cursor, $damageComponent, $codec);
    }

    public static function mobHealth(Catalog $catalog, int $entityId, float $health): string
    {
        return Binary::writeVarInt($entityId) . chr($catalog->mobs['health_index']) . Binary::writeVarInt($catalog->mobs['float_serializer']) . pack('G', $health) . "\xff";
    }

    public static function attributes(Catalog $catalog, int $entityId, array $attributes): string
    {
        $out = Binary::writeVarInt($entityId) . Binary::writeVarInt(count($attributes));
        foreach ($attributes as $name => $value) {
            $out .= Binary::writeVarInt($catalog->mobs['attribute_ids'][$name]) . pack('E', $value) . "\0";
        }
        return $out;
    }

    /**
     * ClientboundUpdateAttributesPacket with modifiers: each attribute's base value and its
     * (identifier, amount, operation) modifiers.
     *
     * @param list<array{string, float, list<array{string, float, int}>}> $attributes
     */
    public static function attributeModifiers(Catalog $catalog, int $entityId, array $attributes): string
    {
        $out = Binary::writeVarInt($entityId) . Binary::writeVarInt(count($attributes));
        foreach ($attributes as [$name, $base, $modifiers]) {
            $out .= Binary::writeVarInt($catalog->mobs['attribute_ids'][$name]) . pack('E', $base) . Binary::writeVarInt(count($modifiers));
            foreach ($modifiers as [$id, $amount, $operation]) {
                $out .= Binary::writeString($id) . pack('E', $amount) . Binary::writeVarInt($operation);
            }
        }
        return $out;
    }

    /** EquipmentSlot ordinal with the high bit marking another slot, per the 26.3 codec. */
    public static function equipment(
        int $entityId,
        array $slots,
        int $damageComponent,
        ?ItemStackCodec $codec = null
    ): string {
        if ($slots === []) {
            throw new \InvalidArgumentException('Empty equipment update');
        }
        $out = Binary::writeVarInt($entityId);
        $remaining = count($slots);
        foreach ($slots as $slot => $stack) {
            $out .= chr($slot | (--$remaining > 0 ? 128 : 0)) . self::stack($stack, $damageComponent, $codec);
        }
        return $out;
    }

    public static function respawn(Version $version, int $mode, bool $flat = true, int $hashedSeed = 0, string $dimension = 'overworld'): string
    {
        return Binary::writeVarInt($version->registryId('dimension_type', $dimension)) . Binary::writeString('minecraft:' . $dimension) . pack('J', $hashedSeed) . Binary::writeVarInt($mode) . "\0\0" . chr((int) $flat) . "\0\0" . Binary::writeVarInt($dimension === 'the_nether' ? 32 : ($dimension === 'the_end' ? 0 : 63)) . "\0";
    }

    public static function vitals(Catalog $catalog, int $entityId, int $air, int $flags): string
    {
        $meta = $catalog->entityMetadata;
        return Binary::writeVarInt($entityId) . chr($meta['air_index']) . Binary::writeVarInt($meta['int_serializer']) . Binary::writeVarInt($air)
            . chr($meta['flags_index']) . Binary::writeVarInt($meta['byte_serializer']) . chr($flags) . "\xff";
    }

    public static function moveRotation(float $x, float $y, float $z, float $yaw, float $pitch, array $velocity = [0.0, 0.0, 0.0]): string
    {
        return pack('EEEEEEGG', $x, $y, $z, ...[...$velocity, $yaw, $pitch]);
    }

    public static function position(int $teleportId, float $x, float $y, float $z, float $yaw = 0, float $pitch = 0): string
    {
        return Binary::writeVarInt($teleportId) . self::moveRotation($x, $y, $z, $yaw, $pitch) . pack('N', 0);
    }

    /** Protocol 777 confirms the teleport ID, three doubles and two floats. */
    public static function readTeleportConfirmation(Binary $reader): array
    {
        $id = $reader->varInt();
        $x = $reader->double();
        $y = $reader->double();
        $z = $reader->double();
        $yaw = $reader->float();
        $pitch = $reader->float();
        $reader->end();
        foreach ([$x, $y, $z, $yaw, $pitch] as $value) {
            if (!is_finite($value)) {
                throw new ProtocolException('Non-finite teleport confirmation');
            }
        }
        if ($id < 0 || abs($x) > 30000000 || abs($z) > 30000000 || abs($y) > 20000000) {
            throw new ProtocolException('Teleport confirmation out of bounds');
        }
        return ['id' => $id, 'x' => $x, 'y' => $y, 'z' => $z, 'yaw' => $yaw, 'pitch' => $pitch];
    }

    public static function spawn(array $position = [0, 64, 0], string $dimension = 'overworld', float $yaw = 0.0, float $pitch = 0.0): string
    {
        return Binary::writeString('minecraft:' . $dimension) . Binary::writePosition((int) floor($position[0]), (int) floor($position[1]), (int) floor($position[2])) . pack('GG', $yaw, $pitch);
    }

    public static function chat(string $text): string
    {
        return Nbt::text($text) . "\0";
    }

    /** Player.getDisplayName() without team styling: click-to-message, insertion and entity hover. */
    /**
     * Player.getDisplayName: the name, formatted for the player's team (PlayerTeam.formatNameForTeam:
     * prefix, name and suffix in the team colour), then clickable, insertable and hoverable.
     *
     * @param array{prefix: array, suffix: array, color: ?string}|null $team
     */
    public static function playerName(string $name, string $uuid, ?array $team = null): array
    {
        $formatted = $team === null ? ['text' => $name] : ['text' => '', 'extra' => [$team['prefix'], ['text' => $name], $team['suffix']]] + ($team['color'] === null ? [] : ['color' => $team['color']]);
        return $formatted + [
            'insertion' => $name,
            'click_event' => ['action' => 'suggest_command', 'command' => '/tell ' . $name . ' '],
            'hover_event' => ['action' => 'show_entity', 'id' => 'minecraft:player', 'uuid' => $uuid, 'name' => $name],
        ];
    }

    public static function systemChat(array $component): string
    {
        return Nbt::component($component) . "\0";
    }

    /** Dedicated servers report an unlocked difficulty; clients only lock it in singleplayer. */
    public static function difficulty(int $difficulty): string
    {
        return chr($difficulty) . "\0";
    }

    public static function time(int $ticks): string
    {
        return pack('J', $ticks) . "\0";
    }

    /**
     * set_time: the game time and, for each listed clock, its total ticks, partial tick and rate.
     *
     * @param list<array{string, int, float, float}> $clocks
     */
    public static function clockTime(Version $version, int $ticks, array $clocks = []): string
    {
        $payload = pack('J', $ticks) . Binary::writeVarInt(count($clocks));
        foreach ($clocks as [$clock, $total, $partialTick, $rate]) {
            $payload .= Binary::writeVarInt($version->registryId('world_clock', $clock)) . Binary::writeVarLong($total) . pack('GG', $partialTick, $rate);
        }
        return $payload;
    }

    /**
     * ClientboundPlayerInfoUpdatePacket.createPlayerInitializing: every action (mask 0xff) for each
     * player, without a chat session or display name, listed, at latency and list order 0.
     *
     * @param list<array{uuid: string, name: string, gameMode: int, hat: bool, properties: array}> $players
     */
    public static function playerInfoInitializing(array $players): string
    {
        $payload = "\xff" . Binary::writeVarInt(count($players));
        foreach ($players as $player) {
            $payload .= $player['uuid'] . Binary::writeString($player['name'], 16) . self::profileProperties($player['properties'])
                . "\0" . Binary::writeVarInt($player['gameMode']) . "\1" . Binary::writeVarInt(0) . "\0" . Binary::writeVarInt(0) . chr((int) $player['hat']);
        }
        return $payload;
    }

    /** ClientboundServerDataPacket: the MOTD as a component, without a server icon. */
    public static function serverData(string $motd): string
    {
        return Nbt::component(['text' => $motd]) . "\0";
    }

    /** ClientboundDamageEventPacket: the damage type, cause and direct entity ids (plus one), and no position. */
    public static function damageEvent(Version $version, int $id, int $cause = -1, string $type = 'player_attack'): string
    {
        return Binary::writeVarInt($id) . Binary::writeVarInt($version->registryId('damage_type', $type)) . Binary::writeVarInt($cause + 1) . Binary::writeVarInt($cause + 1) . "\0";
    }

    public static function hurtAnimation(int $id, float $yaw): string
    {
        return Binary::writeVarInt($id) . pack('G', $yaw);
    }

    public static function entityMotion(int $id, float $x, float $y, float $z): string
    {
        return Binary::writeVarInt($id) . LowPrecisionVector::encode($x, $y, $z);
    }

    public static function sound(int $sound, int $category, float $x, float $y, float $z, float $volume, float $pitch, int $seed): string
    {
        // The official packet casts multiplied coordinates toward zero, including negatives.
        return Binary::writeVarInt($sound + 1) . Binary::writeVarInt($category)
            . pack('NNNGGJ', ((int) ($x * 8)) & 0xffffffff, ((int) ($y * 8)) & 0xffffffff, ((int) ($z * 8)) & 0xffffffff, $volume, $pitch, $seed);
    }

    public static function mobSoundVariant(Version $version, int $id, string $kind, string $variant): string
    {
        $info = $version->catalog->mobSounds['mobs'][$kind];
        return Binary::writeVarInt($id) . chr($info['variant_index']) . Binary::writeVarInt($info['variant_serializer'])
            . Binary::writeVarInt($version->registryId($kind . '_sound_variant', preg_replace('/^minecraft:/', '', $variant))) . "\xff";
    }

    public static function headRotation(int $entityId, float $yaw): string
    {
        return Binary::writeVarInt($entityId) . chr(((int) floor(fmod($yaw, 360) * 256 / 360)) & 255);
    }

    public static function teleportEntity(int $id, float $x, float $y, float $z, float $yaw, float $pitch, bool $ground, array $velocity = [0.0, 0.0, 0.0]): string
    {
        return Binary::writeVarInt($id) . self::moveRotation($x, $y, $z, $yaw, $pitch, $velocity) . pack('N', 0) . chr((int) $ground);
    }

    public static function addPlayer(int $id, string $uuid, int $typeId, float $x, float $y, float $z, float $yaw, float $pitch, array $velocity = [0.0, 0.0, 0.0]): string
    {
        return Binary::writeVarInt($id) . $uuid . Binary::writeVarInt($typeId) . pack('EEE', $x, $y, $z)
            . LowPrecisionVector::encode(...$velocity) . chr(((int) floor($pitch * 256 / 360)) & 255) . chr(((int) floor($yaw * 256 / 360)) & 255)
            . chr(((int) floor($yaw * 256 / 360)) & 255) . "\0";
    }
}
