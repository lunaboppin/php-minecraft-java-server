<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\World\Generation\Float32;

/**
 * Dispensers and droppers (DispenserBlock and DropperBlock): a rising redstone signal, at the
 * block or the one above it, fires one item from a random slot four ticks later. A dropper passes
 * it into the container it faces or drops it; a dispenser drops it too, primes TNT, and leaves
 * the items it would use some other way (projectiles, buckets, boats and the rest) where they are.
 */
trait Dispensers
{
    private const DISPENSE_FACING = ['down' => [0, -1, 0], 'up' => [0, 1, 0], 'north' => [0, 0, -1], 'south' => [0, 0, 1], 'west' => [-1, 0, 0], 'east' => [1, 0, 0]];

    /** Direction.get3DDataValue, for the dispense smoke. */
    private const DISPENSE_DATA = ['down' => 0, 'up' => 1, 'north' => 2, 'south' => 3, 'west' => 4, 'east' => 5];

    /**
     * The items DispenseItemBehavior.bootStrap gives their own behaviour, which this server does
     * not dispense yet: they stay in the dispenser.
     */
    private const SPECIAL_DISPENSES = [
        'experience_bottle', 'splash_potion', 'lingering_potion',
        'firework_rocket', 'fire_charge', 'wind_charge', 'armor_stand', 'chest', 'lava_bucket', 'water_bucket', 'powder_snow_bucket', 'salmon_bucket', 'cod_bucket',
        'pufferfish_bucket', 'tropical_fish_bucket', 'axolotl_bucket', 'sulfur_cube_bucket', 'tadpole_bucket', 'bucket', 'flint_and_steel', 'bone_meal',
        'wither_skeleton_skull', 'carved_pumpkin', 'glass_bottle', 'glowstone', 'shears', 'brush', 'honeycomb', 'potion', 'minecart', 'chest_minecart',
        'furnace_minecart', 'tnt_minecart', 'hopper_minecart', 'command_block_minecart',
    ];

    /** DispenserBlock.neighborChanged: power at the block or above it triggers a dispense in four ticks. */
    private function dispenserNeighborChanged(array $position): void
    {
        $catalog = $this->version->catalog;
        $state = $this->world->get(...$position);
        $properties = $catalog->properties($state);
        $trigger = $this->bestNeighborSignal($position) > 0 || $this->bestNeighborSignal([$position[0], $position[1] + 1, $position[2]]) > 0;
        $triggered = $properties['triggered'] === 'true';
        if ($trigger && !$triggered) {
            $this->scheduleBlock('dispense', ...$position, delay: 4);
            $this->setBlock(...[...$position, $catalog->state($catalog->blockName($state), ['triggered' => 'true'] + $properties)]);
        } elseif (!$trigger && $triggered) {
            $this->setBlock(...[...$position, $catalog->state($catalog->blockName($state), ['triggered' => 'false'] + $properties)]);
        }
    }

    /** DispenserBlockEntity.getRandomSlot: each filled slot in turn replaces the pick with odds 1 in how many came before. */
    private static function randomDispenseSlot(array $slots): int
    {
        $pick = -1;
        $odds = 1;
        for ($slot = 0; $slot < 9; ++$slot) {
            if (isset($slots[$slot]) && random_int(0, $odds++ - 1) === 0) {
                $pick = $slot;
            }
        }
        return $pick;
    }

    /** DispenserBlock.dispenseFrom and DropperBlock's: fire an item from a random slot, or click when empty. */
    private function dispenseFrom(int $x, int $y, int $z): void
    {
        $catalog = $this->version->catalog;
        $state = $this->world->get($x, $y, $z);
        $name = $catalog->blockName($state);
        if ($name !== 'dispenser' && $name !== 'dropper') {
            return;
        }
        $container = $this->hopperContainer([$x, $y, $z]);
        $stacks = $this->containerStacks($container);
        $slot = self::randomDispenseSlot($stacks);
        if ($slot < 0) {
            $this->levelEvent(1001, [$x, $y, $z], 0);
            return;
        }
        $facing = $catalog->properties($state)['facing'];
        [$dx, $dy, $dz] = self::DISPENSE_FACING[$facing];
        $stack = $stacks[$slot];
        if ($name === 'dropper') {
            $target = $this->hopperContainer([$x + $dx, $y + $dy, $z + $dz]);
            if ($target !== null) {
                $opposite = ['down' => 'up', 'up' => 'down', 'north' => 'south', 'south' => 'north', 'west' => 'east', 'east' => 'west'][$facing];
                if ($this->addToContainer(null, $target, array_replace($stack, ['count' => 1]), $opposite) === null) {
                    $this->takeFromContainer($container, $slot);
                }
                return;
            }
            $this->dispenseItem($x, $y, $z, $facing, $stack);
            $this->takeFromContainer($container, $slot);
            return;
        }
        $item = $catalog->itemName($stack['item']);
        if ($item === 'tnt') {
            // DispenseItemBehavior's TNT: primed in front, unless TNT may not explode.
            $primed = $this->gameRule('tnt_explodes') === 'true';
            if ($primed) {
                $this->primeTnt($x + $dx, $y + $dy, $z + $dz);
                $this->takeFromContainer($container, $slot);
            }
            $this->levelEvent($primed ? 1000 : 1001, [$x, $y, $z], 0);
            $this->levelEvent(2000, [$x, $y, $z], self::DISPENSE_DATA[$facing]);
            return;
        }
        if (in_array($item, ['arrow', 'tipped_arrow', 'spectral_arrow', 'snowball', 'egg', 'blue_egg', 'brown_egg'], true)) {
            $this->dispenseProjectile($x, $y, $z, $facing, $stack);
            $this->takeFromContainer($container, $slot);
            return;
        }
        if (in_array($item, self::SPECIAL_DISPENSES, true)) {
            return;
        }
        $this->dispenseItem($x, $y, $z, $facing, $stack);
        $this->takeFromContainer($container, $slot);
    }

    /**
     * ProjectileDispenseBehavior with ProjectileItem.DispenseConfig.DEFAULT: the arrow, snowball or
     * egg from just inside the front face, shot along it at 1.1 with an uncertainty of 6, owned by
     * no one (a dispensed arrow may be picked up), with level events 1002 and 2000.
     */
    private function dispenseProjectile(int $x, int $y, int $z, string $facing, array $stack): void
    {
        [$dx, $dy, $dz] = self::DISPENSE_FACING[$facing];
        $velocity = [];
        foreach ([$dx, $dy, $dz] as $axis => $step) {
            $velocity[$axis] = ($step + 0.0172275 * 6.0 * (self::randomUnit() - self::randomUnit())) * 1.100000023841858;
        }
        $item = $this->version->catalog->itemName($stack['item']);
        $id = $this->nextEntity++;
        $projectile = ['id' => $id, 'uuid' => random_bytes(16), 'type' => $item === 'spectral_arrow' ? 'spectral_arrow' : (str_ends_with($item, 'arrow') ? 'arrow' : self::THROWN[$item]),
            'stack' => array_replace($stack, ['count' => 1]), 'owner' => null, 'x' => $x + 0.5 + 0.7 * $dx, 'y' => $y + 0.5 + 0.7 * $dy, 'z' => $z + 0.5 + 0.7 * $dz,
            'velocity' => $velocity, 'yaw' => Float32::round(self::mthAtan2($velocity[0], $velocity[2]) * 180.0 / Float32::round(M_PI)),
            'pitch' => Float32::round(self::mthAtan2($velocity[1], sqrt($velocity[0] ** 2 + $velocity[2] ** 2)) * 180.0 / Float32::round(M_PI)),
            'ticks' => 0, 'leftOwner' => true, 'water' => false, 'needsSync' => true, 'dirty' => !isset(self::ARROW_TYPES[$item === 'tipped_arrow' ? 'arrow' : $item])];
        if (str_ends_with($item, 'arrow')) {
            $projectile += ['critical' => false, 'inGround' => false, 'shake' => 0, 'life' => 0, 'pickup' => 'allowed', 'lastState' => null, 'changed' => [],
                'damage' => 2.0, 'punch' => 0.0, 'fire' => 0, 'burning' => false, 'effects' => []];
        }
        $this->projectiles[$id] = $projectile;
        foreach ($this->localPlayers() as $player) {
            $this->showProjectile($player, $id);
        }
        $this->levelEvent(1002, [$x, $y, $z], 0);
        $this->levelEvent(2000, [$x, $y, $z], self::DISPENSE_DATA[$facing]);
    }

    /**
     * DefaultDispenseItemBehavior: one item thrown from just inside the front face, pushed out at
     * 0.2 to 0.3 with a little spread and a 0.2 hop, with the click and the smoke.
     */
    private function dispenseItem(int $x, int $y, int $z, string $facing, array $stack): void
    {
        [$dx, $dy, $dz] = self::DISPENSE_FACING[$facing];
        $triangle = static fn (float $mean, float $spread): float => $mean + $spread * (self::randomUnit() - self::randomUnit());
        $power = self::randomUnit() * 0.1 + 0.2;
        $velocity = [$triangle($dx * $power, 0.0172275 * 6), $triangle(0.2, 0.0172275 * 6), $triangle($dz * $power, 0.0172275 * 6)];
        $this->dropStack($x + 0.5 + 0.7 * $dx, $y + 0.5 + 0.7 * $dy - ($dy !== 0 ? 0.125 : 0.15625), $z + 0.5 + 0.7 * $dz, array_replace($stack, ['count' => 1]), 0, $velocity);
        $this->levelEvent(1000, [$x, $y, $z], 0);
        $this->levelEvent(2000, [$x, $y, $z], self::DISPENSE_DATA[$facing]);
    }
}
