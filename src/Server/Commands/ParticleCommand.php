<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Protocol\Binary;
use PhpMinecraftJavaServer\Server\Client;

trait ParticleCommand
{
    /**
     * ParticleCommand: send ClientboundLevelParticlesPacket to each viewer in the sender's
     * dimension whose block centre is within 32 blocks, or 512 when forced.
     */
    private function executeParticle(Client $sender, array $arguments, string $text = ''): void
    {
        // The particle argument may hold spaces inside its options, so it is read from the text.
        $reader = new StringReader(ltrim($text));
        $reader->readUnquotedString();
        $reader->skipWhitespace();
        $id = $reader->readIdentifier();
        $options = $reader->canRead() && $reader->peek() === '{' ? Snbt::read($reader) : ['type' => 'compound', 'value' => []];
        $rest = CommandArguments::parse('particle ' . substr($reader->string, $reader->cursor))[1];
        $rest = $rest === [''] ? [] : $rest;
        $payload = $this->particleOptions(substr($id, 10), $options['value']);
        $position = count($rest) >= 3 ? $this->particleVector(array_slice($rest, 0, 3), $sender, true) : [$this->sourcePosition($sender)[0], $this->sourcePosition($sender)[1], $this->sourcePosition($sender)[2]];
        $delta = count($rest) >= 6 ? $this->particleVector(array_slice($rest, 3, 3), $sender, false) : [0.0, 0.0, 0.0];
        $speed = (float) ($rest[6] ?? 0);
        $count = (int) ($rest[7] ?? 0);
        $force = ($rest[8] ?? 'normal') === 'force';
        $viewers = isset($rest[9]) ? $this->selectPlayers($rest[9], $sender) : $this->players();
        $packet = $payload . ($force ? "\1" : "\0") . "\0" . pack('E3', ...$position) . pack('G3', ...$delta) . pack('G3', $speed, $speed, $speed)
            . Binary::writeVarInt($count) . Binary::writeVarInt(0);
        $range = $force ? 512.0 : 32.0;
        $sent = 0;
        foreach ($viewers as $viewer) {
            // BlockPos.closerToCenterThan from the viewer's block.
            $distance = (floor($viewer->x) + 0.5 - $position[0]) ** 2 + (floor($viewer->y) + 0.5 - $position[1]) ** 2 + (floor($viewer->z) + 0.5 - $position[2]) ** 2;
            if ($viewer->dimension === $this->sourceDimension($sender) && $distance < $range * $range) {
                $viewer->send($this->version, 'level_particles', $packet);
                ++$sent;
            }
        }
        if ($sent === 0) {
            throw new CommandFailure(['translate' => 'commands.particle.failed']);
        }
        $this->sendSuccess($sender, ['translate' => 'commands.particle.success', 'with' => [['text' => $id]]], true);
    }

    /** Vec3Argument: world or local coordinates, absolute X and Z centred on the block when asked. */
    private function particleVector(array $arguments, Client $sender, bool $center): array
    {
        $offset = static fn (string $text): float => $center && !str_starts_with($text, '~') && !str_contains($text, '.') ? 0.5 : 0.0;
        return $this->localPosition($arguments, $sender) ?? [
            $this->coordinate($arguments[0], $this->sourcePosition($sender)[0]) + $offset($arguments[0]),
            $this->coordinate($arguments[1], $this->sourcePosition($sender)[1]),
            $this->coordinate($arguments[2], $this->sourcePosition($sender)[2]) + $offset($arguments[2]),
        ];
    }

    /** ParticleTypes.STREAM_CODEC: the type's registry ID, then its options decoded from the compound. */
    private function particleOptions(string $type, array $tag): string
    {
        $payload = Binary::writeVarInt($this->version->blocks['particle_types'][$type]);
        $invalid = static fn (string $message): CommandFailure => new CommandFailure(['translate' => 'particle.invalidOptions', 'with' => [['text' => $message]]]);
        // RecordCodecBuilder reports every missing field, the last first.
        $required = ['dust' => ['color', 'scale'], 'dust_color_transition' => ['from_color', 'to_color', 'scale'], 'sculk_charge' => ['roll'], 'shriek' => ['delay'],
            'geyser' => ['water_blocks'], 'geyser_plume' => ['water_blocks'], 'geyser_base' => ['water_blocks', 'burst_impulse_base'], 'geyser_poof' => ['water_blocks', 'burst_impulse_base'],
            'entity_effect' => ['color'], 'tinted_leaves' => ['color'], 'flash' => ['color'], 'block' => ['block_state'], 'block_marker' => ['block_state'],
            'falling_dust' => ['block_state'], 'dust_pillar' => ['block_state'], 'block_crumble' => ['block_state']][$type] ?? [];
        $missing = array_reverse(array_values(array_diff($required, array_keys($tag))));
        if ($missing !== []) {
            $map = Snbt::toText(['type' => 'compound', 'value' => $tag]);
            throw $invalid(implode('; ', array_map(static fn (string $key): string => 'No key ' . $key . ' in MapLike[' . $map . ']', $missing)));
        }
        $require = static fn (string $key): array => $tag[$key];
        $number = static fn (array $value): float => is_numeric($value['value']) && $value['type'] !== 'string' ? (float) $value['value']
            : throw $invalid('Not a number: ' . Snbt::toText($value));
        $channel = static fn (float $value): int => (int) floor(\PhpMinecraftJavaServer\World\Generation\Float32::round($value) * 255.0) & 0xff;
        // ExtraCodecs.RGB_COLOR_CODEC and ARGB_COLOR_CODEC: an int, or a list of float channels.
        $color = static function (array $value, bool $alpha) use ($number, $channel): int {
            if ($value['type'] !== 'list') {
                return (int) $number($value);
            }
            $channels = array_map($number, $value['value']);
            $argb = $alpha ? ($channel($channels[3] ?? 0.0) << 24) | ($channel($channels[0] ?? 0.0) << 16) | ($channel($channels[1] ?? 0.0) << 8) | $channel($channels[2] ?? 0.0)
                : (0xff << 24) | ($channel($channels[0] ?? 0.0) << 16) | ($channel($channels[1] ?? 0.0) << 8) | $channel($channels[2] ?? 0.0);
            return $argb >= 0x80000000 ? $argb - 0x100000000 : $argb;
        };
        $scale = static function () use ($require, $number, $invalid): float {
            $value = \PhpMinecraftJavaServer\World\Generation\Float32::round($number($require('scale')));
            if ($value < \PhpMinecraftJavaServer\World\Generation\Float32::round(0.01) || $value > 4.0) {
                throw $invalid('Value must be within range [0.01;4.0]: ' . self::javaFloatText($value));
            }
            return $value;
        };
        $int = static fn (int $value): string => pack('N', $value & 0xffffffff);
        switch ($type) {
            case 'block':
            case 'block_marker':
            case 'falling_dust':
            case 'dust_pillar':
            case 'block_crumble':
                $state = $require('block_state');
                if ($state['type'] === 'string') {
                    $name = str_starts_with($state['value'], 'minecraft:') ? substr($state['value'], 10) : $state['value'];
                    return $payload . Binary::writeVarInt($this->version->catalog->state($name));
                }
                // BlockState.CODEC: the block's ID, or a compound dispatched on "id" with optional properties.
                if (!isset($state['value']['id'])) {
                    throw $invalid('Failed to parse either. First: Failed to parse either. First: Not a string; Second: No key id in MapLike[' . Snbt::toText($state) . ']; Second: Not a string');
                }
                $name = (string) $state['value']['id']['value'];
                $name = str_starts_with($name, 'minecraft:') ? substr($name, 10) : $name;
                $block = $this->version->catalog->blocks[$name]['properties'] ?? [];
                $properties = [];
                foreach ($state['value']['properties']['value'] ?? [] as $key => $value) {
                    if (isset($block[$key]) && in_array((string) $value['value'], array_map('strval', $block[$key]['values']), true)) {
                        $properties[$key] = (string) $value['value'];
                    }
                }
                return $payload . Binary::writeVarInt($this->version->catalog->state($name, $properties));
            case 'dust':
                return $payload . $int($color($require('color'), false)) . pack('G', $scale());
            case 'dust_color_transition':
                return $payload . $int($color($require('from_color'), false)) . $int($color($require('to_color'), false)) . pack('G', $scale());
            case 'entity_effect':
            case 'tinted_leaves':
            case 'flash':
                return $payload . $int($color($require('color'), true));
            case 'effect':
            case 'instant_effect':
                return $payload . $int(isset($tag['color']) ? $color($tag['color'], false) : -1) . pack('G', isset($tag['power']) ? $number($tag['power']) : 1.0);
            case 'dragon_breath':
                return $payload . pack('G', isset($tag['power']) ? $number($tag['power']) : 1.0);
            case 'sculk_charge':
                return $payload . pack('G', $number($require('roll')));
            case 'shriek':
                return $payload . Binary::writeVarInt((int) $number($require('delay')));
            case 'geyser':
            case 'geyser_plume':
                return $payload . $int((int) $number($require('water_blocks')));
            case 'geyser_base':
            case 'geyser_poof':
                return $payload . $int((int) $number($require('water_blocks'))) . pack('G', $number($require('burst_impulse_base')));
            case 'item':
            case 'vibration':
            case 'trail':
                throw new \InvalidArgumentException('Particle options for ' . $type . ' are not supported yet');
            default:
                return $payload;
        }
    }

    /** Float.toString for the float's shortest round-tripping digits. */
    private static function javaFloatText(float $value): string
    {
        if ($value == floor($value) && abs($value) < 1e7) {
            return sprintf('%.1f', $value);
        }
        for ($digits = 1; $digits <= 9; ++$digits) {
            $text = sprintf('%.' . $digits . 'G', $value);
            if (\PhpMinecraftJavaServer\World\Generation\Float32::round((float) $text) === $value) {
                break;
            }
        }
        return str_contains($text, 'E') ? $text : rtrim(rtrim($text, '0'), '.') . (str_contains($text, '.') ? '' : '.0');
    }
}
