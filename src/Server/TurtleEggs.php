<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\Protocol\Packets;

/**
 * TurtleEggBlock: eggs on sand crack and hatch into baby turtles, almost surely around dawn and
 * rarely otherwise; anything that may grief standing on them (players, unless sneaking) breaks
 * one now and then, and breaking the block takes only one egg.
 */
trait TurtleEggs
{
    /** EnvironmentAttributes.TURTLE_EGG_HATCH_CHANCE from the overworld's day timeline: 1 from tick 21062 to 21905, otherwise 0.002. */
    private function turtleEggHatchChance(): float
    {
        $time = (($this->dayTime % 24000) + 24000) % 24000;
        return $this->world->dimension === 'overworld' && $time >= 21062 && $time < 21905 ? 1.0 : 0.002;
    }

    private function turtleEggSound(int $x, int $y, int $z, string $sound): void
    {
        $id = $this->version->blocks['sounds'][$sound];
        $payload = Packets::sound($id, 4, $x + 0.5, $y + 0.5, $z + 0.5, 0.7, 0.9 + self::randomUnit() * 0.2, unpack('J', random_bytes(8))[1]);
        foreach ($this->localPlayers() as $player) {
            if (($player->x - $x - 0.5) ** 2 + ($player->y - $y - 0.5) ** 2 + ($player->z - $z - 0.5) ** 2 < 256.0) {
                $player->send($this->version, 'sound', $payload);
            }
        }
    }

    /** TurtleEggBlock.randomTick: on sand, by the hatch chance, the eggs crack or hatch. */
    private function turtleEggRandomTick(int $x, int $y, int $z, int $state): void
    {
        $catalog = $this->version->catalog;
        if (!(self::randomUnit() < $this->turtleEggHatchChance()) || !$this->fireBlocks()->inTag($this->world->get($x, $y - 1, $z), 'sand')) {
            return;
        }
        $p = $catalog->properties($state);
        $hatch = (int) $p['hatch'];
        if ($hatch < 2) {
            $this->turtleEggSound($x, $y, $z, 'entity.turtle.egg_crack');
            $this->setBlock($x, $y, $z, $catalog->state('turtle_egg', ['hatch' => (string) ($hatch + 1)] + $p));
            return;
        }
        $this->turtleEggSound($x, $y, $z, 'entity.turtle.egg_hatch');
        $this->setBlock($x, $y, $z, $catalog->state('air'));
        for ($i = 0; $i < (int) $p['eggs']; ++$i) {
            $this->levelEvent(2001, [$x, $y, $z], $state);
            $this->spawnAnimal('turtle', $x + 0.3 + $i * 0.2, (float) $y, $z + 0.3, ['baby' => true, 'home' => [$x, $y, $z]]);
        }
    }

    /** TurtleEggBlock.decreaseEggs: one egg breaks, or the block when it was the last. */
    private function decreaseTurtleEggs(int $x, int $y, int $z, int $state): void
    {
        $catalog = $this->version->catalog;
        $this->turtleEggSound($x, $y, $z, 'entity.turtle.egg_break');
        $eggs = (int) $catalog->properties($state)['eggs'];
        // Level.destroyBlock does nothing where a player's break already left air.
        if ($eggs > 1 || $catalog->blockName($this->world->get($x, $y, $z)) === 'turtle_egg') {
            $this->levelEvent(2001, [$x, $y, $z], $state);
            $this->setBlock($x, $y, $z, $eggs <= 1 ? $catalog->state('air') : $catalog->state('turtle_egg', ['eggs' => (string) ($eggs - 1)] + $catalog->properties($state)));
        }
    }

    /** TurtleEggBlock.onPlace: eggs set on sand show their particles. */
    private function turtleEggPlaced(int $x, int $y, int $z): void
    {
        if ($this->version->catalog->blockName($this->world->get($x, $y, $z)) === 'turtle_egg' && $this->fireBlocks()->inTag($this->world->get($x, $y - 1, $z), 'sand')) {
            $this->levelEvent(2012, [$x, $y, $z], 15);
        }
    }

    /**
     * TurtleEggBlock.stepOn from LivingEntity.aiStep, every tick a player stands on the eggs:
     * one in a hundred breaks one unless the player sneaks or spawn protection covers them.
     */
    private function playerStepOn(Client $c): void
    {
        if (!$c->ground || $c->gameMode === 3 || $c->sneaking) {
            return;
        }
        [$x, $y, $z] = [(int) floor($c->x), (int) floor($c->y - 0.2), (int) floor($c->z)];
        $state = $this->world->get($x, $y, $z);
        if ($this->version->catalog->blockName($state) === 'turtle_egg' && $this->editable($c, $x, $y, $z) && random_int(0, 99) === 0) {
            $this->decreaseTurtleEggs($x, $y, $z, $state);
        }
    }
}
