<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\Protocol\Binary;
use PhpMinecraftJavaServer\World\Generation\Float32;

/**
 * ServerPlayerGameMode's block breaking: starting, stopping and aborting, the delayed destroy
 * after an early stop, and Player.getDestroySpeed from the exported tool rules.
 */
trait Mining
{
    private function miningReference(): array
    {
        static $mining = null;
        if ($mining === null) {
            $mining = json_decode((string) file_get_contents($this->version->directory . '/mining.json'), true, 512, JSON_THROW_ON_ERROR);
            foreach ($mining['items'] as &$tool) {
                foreach ($tool['rules'] as &$rule) {
                    $rule['blocks'] = array_flip($rule['blocks']);
                }
            }
        }
        return $mining;
    }

    /** The held item's tool component, or null for items without one. */
    private function heldTool(Client $c): ?array
    {
        $held = $c->inventory[36 + $c->heldSlot]['item'] ?? null;
        return $held === null ? null : ($this->miningReference()['items'][$this->version->catalog->itemName($held)] ?? null);
    }

    /** Tool.getMiningSpeed: the first matching rule with a speed, else the default. */
    private function toolSpeed(?array $tool, string $block): float
    {
        foreach ($tool['rules'] ?? [] as $rule) {
            if (isset($rule['speed'], $rule['blocks'][$block])) {
                return (float) $rule['speed'];
            }
        }
        return (float) ($tool['default_speed'] ?? 1.0);
    }

    /** Tool.isCorrectForDrops: the first matching rule that decides it. */
    private function toolCorrect(?array $tool, string $block): bool
    {
        foreach ($tool['rules'] ?? [] as $rule) {
            if (isset($rule['correct'], $rule['blocks'][$block])) {
                return $rule['correct'];
            }
        }
        return false;
    }

    /** Player.hasCorrectToolForDrops. */
    private function correctToolForDrops(Client $c, string $block): bool
    {
        return !($this->miningReference()['blocks'][$block]['requires_correct_tool'] ?? false) || $this->toolCorrect($this->heldTool($c), $block);
    }

    /** BlockBehaviour.getDestroyProgress with Player.getDestroySpeed, in float arithmetic. */
    private function destroyProgress(Client $c, int $x, int $y, int $z, int $state): float
    {
        $block = $this->version->catalog->blockName($state);
        $hardness = (float) ($this->miningReference()['blocks'][$block]['destroy_time'] ?? 0.0);
        if ($hardness === -1.0) {
            return 0.0;
        }
        $speed = Float32::round($this->toolSpeed($this->heldTool($c), $block));
        if ($speed > 1.0) {
            $level = $c->inventory[36 + $c->heldSlot]['enchantments']['minecraft:efficiency'] ?? 0;
            $efficiency = $this->playerAttribute($c, 'mining_efficiency') + ($level > 0 ? (float) ($this->miningReference()['efficiency'][(string) min(255, $level)] ?? 0.0) : 0.0);
            $speed = Float32::round($speed + Float32::round($efficiency));
        }
        $haste = max($c->effects['minecraft:haste']['amplifier'] ?? -1, $c->effects['minecraft:conduit_power']['amplifier'] ?? -1);
        if ($haste >= 0) {
            $speed = Float32::round($speed * Float32::round(1.0 + Float32::round(($haste + 1) * Float32::round(0.2))));
        }
        if (isset($c->effects['minecraft:mining_fatigue'])) {
            $speed = Float32::round($speed * Float32::round(0.3 ** ($c->effects['minecraft:mining_fatigue']['amplifier'] + 1)));
        }
        $speed = Float32::round($speed * Float32::round($this->playerAttribute($c, 'block_break_speed')));
        if ($this->eyeInWater($c)) {
            $speed = Float32::round($speed * Float32::round($this->playerAttribute($c, 'submerged_mining_speed')));
        }
        if (!$c->ground) {
            $speed = Float32::round($speed / 5.0);
        }
        return Float32::round(Float32::round(fdiv($speed, $hardness)) / ($this->correctToolForDrops($c, $block) ? 30.0 : 100.0));
    }

    /** Entity.isEyeInFluid(WATER): the eye is below the water surface in its block. */
    private function eyeInWater(Client $c): bool
    {
        $eye = $c->y + ($c->sneaking && !$c->flying ? 1.27 : 1.62);
        [$x, $y, $z] = [(int) floor($c->x), (int) floor($eye), (int) floor($c->z)];
        $height = $this->waterHeight($x, $y, $z);
        return $height > 0.0 && $eye < $y + ($this->waterHeight($x, $y + 1, $z) > 0.0 ? 1.0 : $height);
    }

    /** FluidState.getOwnHeight for water: its amount over nine, or 0 without water. */
    private function waterHeight(int $x, int $y, int $z): float
    {
        $catalog = $this->version->catalog;
        $state = $this->world->get($x, $y, $z);
        $name = $catalog->blockName($state);
        $properties = $catalog->properties($state);
        if ($name === 'water') {
            $level = (int) ($properties['level'] ?? 0);
            return ($level === 0 || $level >= 8 ? 8 : 8 - $level) / 9.0;
        }
        $source = in_array($name, ['bubble_column', 'kelp', 'kelp_plant', 'seagrass', 'tall_seagrass'], true) || ($properties['waterlogged'] ?? 'false') === 'true';
        return $source ? 8 / 9.0 : 0.0;
    }

    /** Player.isWithinBlockInteractionRange: the eye's distance to the block's box. */
    private function withinBlockReach(Client $c, int $x, int $y, int $z, float $buffer): bool
    {
        $range = $this->playerAttribute($c, 'block_interaction_range') + ($c->gameMode === 1 ? 0.5 : 0.0) + $buffer;
        $eye = [$c->x, $c->y + ($c->sneaking && !$c->flying ? 1.27 : 1.62), $c->z];
        $distance = 0.0;
        foreach ([$x, $y, $z] as $axis => $low) {
            $distance += max(0.0, $low - $eye[$axis], $eye[$axis] - ($low + 1)) ** 2;
        }
        return $distance < $range * $range;
    }

    /** ServerLevel.destroyBlockProgress: the crack stage, for other players within 32 blocks. */
    private function destroyBlockProgress(Client $c, array $position, int $stage): void
    {
        $payload = Binary::writeVarInt($c->entityId) . Binary::writePosition(...$position) . chr($stage & 255);
        foreach ($this->localPlayers() as $player) {
            if ($player !== $c && ($position[0] - $player->x) ** 2 + ($position[1] - $player->y) ** 2 + ($position[2] - $player->z) ** 2 < 1024) {
                $player->send($this->version, 'block_destruction', $payload);
            }
        }
    }

    /** ServerPlayerGameMode.handleBlockBreakAction for starting, redirecting, aborting and stopping. */
    private function blockBreakAction(Client $c, int $action, int $x, int $y, int $z, int $face): void
    {
        $position = [$x, $y, $z];
        if (!$this->withinBlockReach($c, $x, $y, $z, 1.0)) {
            if ($c->mining !== null && $action === 2) {
                $this->abortDestroy($c, $position);
            }
            return;
        }
        if ($y > $this->world->minY + $this->world->height - 1) {
            $this->blockUpdate($c, $x, $y, $z);
            return;
        }
        if ($action === 0) {
            if ($this->underSpawnProtection($c, $x, $z)) {
                $this->spawnProtectionMessage($c, $x, $y, $z);
                return;
            }
            if (!$this->border($c->dimension)->contains($x, $z, $this->worldAge)) {
                $this->blockUpdate($c, $x, $y, $z);
                return;
            }
            if ($c->gameMode === 1) {
                $this->destroyAndAcknowledge($c, $x, $y, $z);
                return;
            }
            if ($c->gameMode !== 0) {
                $this->blockUpdate($c, $x, $y, $z);
                return;
            }
            $c->miningStarted = $c->miningTicks;
            $progress = 1.0;
            $state = $this->world->get($x, $y, $z);
            $air = $this->version->catalog->air($state);
            if (!$air) {
                if ($this->version->catalog->blockName($state) === 'note_block') {
                    $this->playNote($x, $y, $z);
                }
                $progress = $this->destroyProgress($c, $x, $y, $z, $state);
            }
            if (!$air && $progress >= 1.0) {
                $this->destroyAndAcknowledge($c, $x, $y, $z);
                return;
            }
            if ($c->mining !== null) {
                $this->blockUpdate($c, ...$c->miningPosition);
            }
            $c->mining = ['position' => $position, 'direction' => $face];
            $c->miningPosition = $position;
            $c->miningSent = self::floatToInt(Float32::round($progress * 10.0));
            $this->destroyBlockProgress($c, $position, $c->miningSent);
        } elseif ($action === 1) {
            if ($c->mining !== null) {
                $c->mining['direction'] = $face;
            }
        } elseif ($action === 3) {
            if ($c->miningPosition === $position) {
                $state = $this->world->get($x, $y, $z);
                if (!$this->version->catalog->air($state)) {
                    $progress = Float32::round($this->destroyProgress($c, $x, $y, $z, $state) * ($c->miningTicks - $c->miningStarted + 1));
                    if ($progress >= 0.7) {
                        $c->mining = null;
                        $this->destroyBlockProgress($c, $position, -1);
                        $this->destroyAndAcknowledge($c, $x, $y, $z);
                        return;
                    }
                    if ($c->delayedDestroy === null) {
                        $c->mining = null;
                        $c->delayedDestroy = ['position' => $position, 'started' => $c->miningStarted];
                    }
                }
            }
        } else {
            $this->abortDestroy($c, $position);
        }
    }

    private function abortDestroy(Client $c, array $position): void
    {
        $c->mining = null;
        if ($c->miningPosition !== $position && $c->miningPosition !== null) {
            $this->destroyBlockProgress($c, $c->miningPosition, -1);
        }
        $this->destroyBlockProgress($c, $position, -1);
    }

    /** ServerPlayerGameMode.destroyAndAck: a refused break shows the client the block again. */
    private function destroyAndAcknowledge(Client $c, int $x, int $y, int $z): void
    {
        if (!$this->playerDestroyBlock($c, $x, $y, $z)) {
            $this->blockUpdate($c, $x, $y, $z);
        }
    }

    /** ServerPlayerGameMode.destroyBlock: creative tools that may not break blocks, then the break. */
    private function playerDestroyBlock(Client $c, int $x, int $y, int $z): bool
    {
        if ($c->gameMode === 1 && !($this->heldTool($c)['can_destroy_blocks_in_creative'] ?? true)) {
            return false;
        }
        // Block.playerWillDestroy: the break particles for everyone else within 64 blocks.
        $payload = pack('N', 2001) . Binary::writePosition($x, $y, $z) . pack('N', $this->world->get($x, $y, $z)) . "\0";
        foreach ($this->localPlayers() as $player) {
            if ($player !== $c && ($x - $player->x) ** 2 + ($y - $player->y) ** 2 + ($z - $player->z) ** 2 < 4096) {
                $player->send($this->version, 'level_event', $payload);
            }
        }
        $this->breakBlock($c, $x, $y, $z);
        return true;
    }

    /** ServerPlayerGameMode.tick: the delayed destroy after an early stop, or the crack and hit events. */
    private function tickMining(Client $c): void
    {
        ++$c->miningTicks;
        if ($c->delayedDestroy !== null) {
            [$x, $y, $z] = $position = $c->delayedDestroy['position'];
            $state = $this->world->get($x, $y, $z);
            if ($this->version->catalog->air($state)) {
                $c->delayedDestroy = null;
            } elseif ($this->incrementDestroyProgress($c, $position, $state, $c->delayedDestroy['started']) >= 1.0) {
                // As in 26.3, the delayed destroy multiplies by its start tick, not the ticks since.
                $c->delayedDestroy = null;
                $this->playerDestroyBlock($c, $x, $y, $z);
            }
        } elseif ($c->mining !== null) {
            [$x, $y, $z] = $position = $c->mining['position'];
            $state = $this->world->get($x, $y, $z);
            if ($this->version->catalog->air($state)) {
                $this->destroyBlockProgress($c, $position, -1);
                $c->miningSent = -1;
                $c->mining = null;
            } else {
                $ticks = $c->miningTicks - $c->miningStarted;
                $this->incrementDestroyProgress($c, $position, $state, $ticks);
                $this->levelEvent($ticks % 4 === 0 ? 2020 : 2019, $position, $c->mining['direction']);
            }
        }
    }

    /** Java's f2i: NaN is 0 and values beyond the int range saturate. */
    private static function floatToInt(float $value): int
    {
        return is_nan($value) ? 0 : (int) max(-2147483648.0, min(2147483647.0, $value));
    }

    private function incrementDestroyProgress(Client $c, array $position, int $state, int $ticks): float
    {
        $progress = Float32::round($this->destroyProgress($c, ...[...$position, $state]) * ($ticks + 1));
        $stage = self::floatToInt(Float32::round($progress * 10.0));
        if ($stage !== $c->miningSent) {
            $this->destroyBlockProgress($c, $position, $stage);
            $c->miningSent = $stage;
        }
        return $progress;
    }
}
