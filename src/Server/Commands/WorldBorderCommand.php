<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Protocol\Binary;
use PhpMinecraftJavaServer\Protocol\NbtDouble;
use PhpMinecraftJavaServer\Server\Client;
use PhpMinecraftJavaServer\World\WorldBorder;

trait WorldBorderCommand
{
    /** WorldBorderCommand on the sender's dimension. */
    private function executeWorldBorder(Client $sender, array $arguments): void
    {
        $dimension = $sender->dimension;
        $border = $this->border($dimension);
        // String.format(Locale.ROOT, "%.Nf"): half-up decimal rounding.
        $format = static fn (float $value, int $decimals): array => ['text' => number_format($value, $decimals, '.', '')];
        switch ($arguments[0] ?? '') {
            case 'get':
                $this->sendSuccess($sender, ['translate' => 'commands.worldborder.get', 'with' => [$format($border->size($this->worldAge), 0)]], false);
                return;
            case 'set':
            case 'add':
                $distance = (float) $arguments[1];
                $ticks = isset($arguments[2]) ? $this->timeArgument($arguments[2], 0) : 0;
                $current = $border->size($this->worldAge);
                $size = $arguments[0] === 'add' ? $current + $distance : $distance;
                if ($size === $current) {
                    throw new CommandFailure(['translate' => 'commands.worldborder.set.failed.nochange']);
                }
                if ($size < 1.0) {
                    throw new CommandFailure(['translate' => 'commands.worldborder.set.failed.small']);
                }
                if ($size > WorldBorder::MAX_SIZE) {
                    throw new CommandFailure(['translate' => 'commands.worldborder.set.failed.big', 'with' => [new NbtDouble(WorldBorder::MAX_SIZE)]]);
                }
                if ($ticks > 0) {
                    $border->lerpSizeBetween($current, $size, $ticks, $this->worldAge);
                    $this->borderChanged($dimension, 'set_border_lerp_size', self::borderLerpPacket($current, $size, $ticks));
                    $this->sendSuccess($sender, ['translate' => $size > $current ? 'commands.worldborder.set.grow' : 'commands.worldborder.set.shrink', 'with' => [$format($size, 1), $format($ticks / 20.0, 2)]], true);
                    return;
                }
                $border->setSize($size);
                $this->borderChanged($dimension, 'set_border_size', self::borderSizePacket($size));
                $this->sendSuccess($sender, ['translate' => 'commands.worldborder.set.immediate', 'with' => [$format($size, 1)]], true);
                return;
            case 'center':
                // Vec2Argument centres absolute block coordinates.
                $center = static fn (string $text): float => !str_starts_with($text, '~') && !str_contains($text, '.') ? 0.5 : 0.0;
                $x = $this->coordinate($arguments[1], $sender->x) + $center($arguments[1]);
                $z = $this->coordinate($arguments[2], $sender->z) + $center($arguments[2]);
                if ($x === $border->centerX && $z === $border->centerZ) {
                    throw new CommandFailure(['translate' => 'commands.worldborder.center.failed']);
                }
                if (abs($x) > WorldBorder::ABSOLUTE_MAX || abs($z) > WorldBorder::ABSOLUTE_MAX) {
                    throw new CommandFailure(['translate' => 'commands.worldborder.set.failed.far', 'with' => [new NbtDouble(WorldBorder::ABSOLUTE_MAX)]]);
                }
                [$border->centerX, $border->centerZ] = [$x, $z];
                $this->borderChanged($dimension, 'set_border_center', pack('EE', $x, $z));
                $this->sendSuccess($sender, ['translate' => 'commands.worldborder.center.success', 'with' => [$format($x, 2), $format($z, 2)]], true);
                return;
            case 'damage':
                $value = unpack('G', pack('G', (float) $arguments[2]))[1];
                $field = $arguments[1] === 'amount' ? 'damagePerBlock' : 'safeZone';
                if ($value === $border->$field) {
                    throw new CommandFailure(['translate' => 'commands.worldborder.damage.' . $arguments[1] . '.failed']);
                }
                $border->$field = $value;
                $this->borderChanged($dimension);
                $this->sendSuccess($sender, ['translate' => 'commands.worldborder.damage.' . $arguments[1] . '.success', 'with' => [$format($value, 2)]], true);
                return;
            case 'warning':
                $distance = $arguments[1] === 'distance';
                $value = $distance ? (int) $arguments[2] : $this->timeArgument($arguments[2], 0);
                $field = $distance ? 'warningBlocks' : 'warningTime';
                if ($value === $border->$field) {
                    throw new CommandFailure(['translate' => 'commands.worldborder.warning.' . $arguments[1] . '.failed']);
                }
                $border->$field = $value;
                $this->borderChanged($dimension, $distance ? 'set_border_warning_distance' : 'set_border_warning_delay', Binary::writeVarInt($value));
                $this->sendSuccess($sender, ['translate' => 'commands.worldborder.warning.' . $arguments[1] . '.success', 'with' => [$distance ? $value : $format($value / 20.0, 2)]], true);
                return;
        }
        throw new \InvalidArgumentException('Usage: /worldborder add|set|center|damage|get|warning');
    }
}
