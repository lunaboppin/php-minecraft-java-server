<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\Protocol\Binary;
use PhpMinecraftJavaServer\Protocol\Packets;
use PhpMinecraftJavaServer\World\Collision;
use PhpMinecraftJavaServer\World\Generation\Float32;

/**
 * Bows and the arrows they shoot (BowItem, AbstractArrow): a player draws a bow while holding use,
 * and letting go shoots an arrow with the power of the draw, using up an arrow unless the player has
 * infinite materials. Arrows fall and slow each tick, hurt the first entity they meet by their speed,
 * stick in the first block, and are picked up again once they have stopped shaking.
 */
trait Arrows
{
    /** ItemTags.ARROWS, by the entity each shoots; tipped arrows' effects are not applied yet. */
    private const ARROW_ITEMS = ['arrow' => 'arrow', 'tipped_arrow' => 'arrow', 'spectral_arrow' => 'spectral_arrow'];

    /** BowItem.use: a bow is drawn when there is an arrow to shoot, or the player has infinite materials. */
    private function drawBow(Client $c, int $hand, int $slot): bool
    {
        $stack = $c->inventory[$slot];
        if ($this->version->catalog->itemName($stack['item']) !== 'bow') {
            return false;
        }
        if ($c->gameMode !== 1 && $this->arrowSlot($c) === null) {
            return true;
        }
        $c->drawing = ['hand' => $hand, 'item' => $stack['item'], 'start' => $this->ticks];
        $this->setUsingItem($c, $hand);
        return true;
    }

    /**
     * LivingEntity.updatingUsingItem: the draw goes on while the hand holds the same item, and
     * stops when it no longer does.
     */
    private function tickBowUse(Client $c): void
    {
        if ($c->drawing === null) {
            return;
        }
        $stack = $c->inventory[$c->drawing['hand'] === 0 ? 36 + $c->heldSlot : 45] ?? null;
        if ($c->dead || $stack === null || $stack['item'] !== $c->drawing['item']) {
            $this->stopUsingItem($c);
        }
    }

    /**
     * BowItem.releaseUsing: the power of the draw, at least a tenth of the full draw, shoots an
     * arrow three times as fast, critical at full power; the bow wears by one and the shot sounds.
     */
    private function releaseBow(Client $c): void
    {
        $drawing = $c->drawing;
        if ($drawing === null) {
            return;
        }
        $this->stopUsingItem($c);
        $bowSlot = $drawing['hand'] === 0 ? 36 + $c->heldSlot : 45;
        $bow = $c->inventory[$bowSlot] ?? null;
        if ($bow === null || $bow['item'] !== $drawing['item'] || $c->dead) {
            return;
        }
        $arrowSlot = $this->arrowSlot($c);
        if ($arrowSlot === null && $c->gameMode !== 1) {
            return;
        }
        $power = self::bowPower($this->ticks - $drawing['start']);
        if ($power < 0.1) {
            return;
        }
        // ProjectileWeaponItem.useAmmo: players with infinite materials, and Infinity's plain arrows,
        // shoot a copy that cannot be picked up for another.
        $arrow = $arrowSlot === null ? ['item' => $this->version->catalog->items['arrow']['id'], 'count' => 1] : ['count' => 1] + $c->inventory[$arrowSlot];
        $free = $c->gameMode === 1 || (isset($bow['enchantments']['minecraft:infinity']) && $this->version->catalog->itemName($arrow['item']) === 'arrow');
        if (!$free) {
            \PhpMinecraftJavaServer\World\Inventory::consume($c->inventory, $arrowSlot, 1);
        }
        $this->shootArrow($c, $arrow, Float32::round($power * 3.0), $power === 1.0, $free ? 'creative_only' : 'allowed', $bow);
        // ItemStack.hurtAndBreak: players with infinite materials do not wear their tools.
        if ($c->gameMode !== 1 && ($durability = $this->version->catalog->item($bow['item'])['durability'] ?? 0) > 0) {
            $bow['damage'] = ($bow['damage'] ?? 0) + 1;
            if ($bow['damage'] >= $durability) {
                $this->awardStat($c, 'broken', 'bow');
                unset($c->inventory[$bowSlot]);
            } else {
                $c->inventory[$bowSlot] = $bow;
            }
        }
        $pitch = Float32::round(Float32::round(1.0 / Float32::round(Float32::round(self::randomUnit() * 0.4) + 1.2)) + Float32::round($power * 0.5));
        $this->arrowSound('entity.arrow.shoot', 7, $c->x, $c->y, $c->z, $pitch);
        $this->awardStat($c, 'used', 'bow');
        $this->syncInventory($c);
    }

    /** BowItem.getPowerForTime: the draw's ticks as a fraction of a second, eased, at most 1. */
    private static function bowPower(int $ticks): float
    {
        $power = Float32::round($ticks / 20.0);
        $power = Float32::round(Float32::round(Float32::round($power * $power) + Float32::round($power * 2.0)) / 3.0);
        return min($power, 1.0);
    }

    /**
     * Player.getProjectile for a bow: an arrow in the offhand, then the main hand, then the first
     * in the inventory from the hotbar on.
     */
    private function arrowSlot(Client $c): ?int
    {
        foreach ([45, 36 + $c->heldSlot, ...range(36, 44), ...range(9, 35)] as $slot) {
            $stack = $c->inventory[$slot] ?? null;
            if ($stack !== null && isset(self::ARROW_ITEMS[$this->version->catalog->itemName($stack['item'])])) {
                return $slot;
            }
        }
        return null;
    }

    /**
     * AbstractArrow from a shooter, shot by Projectile.shootFromRotation: at the eyes less 0.1,
     * along the look with a spread of 1 at the given speed, plus the shooter's own movement.
     */
    private function shootArrow(Client $owner, array $stack, float $speed, bool $critical, string $pickup, array $bow): int
    {
        $enchantments = $bow['enchantments'] ?? [];
        $id = $this->nextEntity++;
        $type = self::ARROW_ITEMS[$this->version->catalog->itemName($stack['item'])];
        $eye = $owner->sneaking && !$owner->flying ? 1.27 : 1.62;
        $radians = Float32::round(M_PI / 180.0);
        $yaw = Float32::round(Float32::round($owner->yaw) * $radians);
        $pitch = Float32::round(Float32::round($owner->pitch) * $radians);
        $direction = [
            Float32::round(-$this->mthSin($yaw) * $this->mthCos($pitch)),
            Float32::round(-$this->mthSin($pitch)),
            Float32::round($this->mthCos($yaw) * $this->mthCos($pitch)),
        ];
        $length = sqrt($direction[0] ** 2 + $direction[1] ** 2 + $direction[2] ** 2);
        $velocity = [];
        foreach ($direction as $axis => $value) {
            $velocity[$axis] = (($length < 1.0E-5 ? 0.0 : $value / $length) + 0.0172275 * (self::randomUnit() - self::randomUnit())) * $speed;
        }
        $known = $owner->knownMovement;
        $this->projectiles[$id] = ['id' => $id, 'uuid' => random_bytes(16), 'type' => $type, 'stack' => $stack, 'owner' => $owner->uuid,
            'x' => $owner->x, 'y' => $owner->y + Float32::round($eye) - Float32::round(0.1), 'z' => $owner->z,
            'velocity' => [$velocity[0] + $known[0], $velocity[1] + ($owner->ground ? 0.0 : $known[1]), $velocity[2] + $known[2]],
            'yaw' => Float32::round(self::mthAtan2($velocity[0], $velocity[2]) * 180.0 / Float32::round(M_PI)),
            'pitch' => Float32::round(self::mthAtan2($velocity[1], sqrt($velocity[0] ** 2 + $velocity[2] ** 2)) * 180.0 / Float32::round(M_PI)),
            'ticks' => 0, 'leftOwner' => false, 'water' => false, 'needsSync' => true, 'dirty' => false,
            'critical' => $critical, 'inGround' => false, 'shake' => 0, 'life' => 0, 'pickup' => $pickup, 'lastState' => null, 'changed' => [],
            // Power adds 1 to the base damage of 2 and half for each level above the first, Punch
            // knocks back by its level, and Flame sets the arrow alight for 100 ticks.
            'damage' => 2.0 + (isset($enchantments['minecraft:power']) ? 1.0 + 0.5 * ($enchantments['minecraft:power'] - 1) : 0.0),
            'punch' => (float) ($enchantments['minecraft:punch'] ?? 0), 'fire' => isset($enchantments['minecraft:flame']) ? 100 : 0, 'burning' => isset($enchantments['minecraft:flame'])];
        foreach ($this->localPlayers() as $player) {
            $this->showProjectile($player, $id);
        }
        return $id;
    }

    /**
     * AbstractArrow.tick: an arrow inside a block's shape is in the ground, where it despawns after
     * a minute or falls once the block goes; in flight it turns toward its motion, moves to the first
     * entity or block on its way, then slows and falls.
     */
    private function tickArrow(int $id, Collision $collision): void
    {
        $arrow = &$this->projectiles[$id];
        ++$arrow['ticks'];
        $owner = $this->projectileOwner($arrow);
        $position = [$arrow['x'], $arrow['y'], $arrow['z']];
        $block = [(int) floor($position[0]), (int) floor($position[1]), (int) floor($position[2])];
        $state = $this->world->get(...$block);
        if ($this->version->catalog->blockName($state) !== 'air') {
            foreach ($collision->shape($state) as $box) {
                if ($position[0] >= $box[0] + $block[0] && $position[0] < $box[3] + $block[0] && $position[1] >= $box[1] + $block[1] && $position[1] < $box[4] + $block[1]
                    && $position[2] >= $box[2] + $block[2] && $position[2] < $box[5] + $block[2]) {
                    $arrow['velocity'] = [0.0, 0.0, 0.0];
                    $this->setArrowInGround($arrow, true);
                    break;
                }
            }
        }
        if ($arrow['shake'] > 0) {
            --$arrow['shake'];
        }
        if ($arrow['inGround']) {
            // shouldFall: the block it stuck in changed, leaving nothing within 0.06 of it.
            $near = [$position[0] - 0.06, $position[1] - 0.06, $position[2] - 0.06, $position[0] + 0.06, $position[1] + 0.06, $position[2] + 0.06];
            if ($arrow['lastState'] !== $state && !array_filter($collision->boxes($this->world, $near), static fn (array $box): bool => self::intersects($box, $near))) {
                $this->setArrowInGround($arrow, false);
                $arrow['velocity'] = [$arrow['velocity'][0] * Float32::round(self::randomUnit() * 0.2), $arrow['velocity'][1] * Float32::round(self::randomUnit() * 0.2), $arrow['velocity'][2] * Float32::round(self::randomUnit() * 0.2)];
                $arrow['life'] = 0;
            } elseif (++$arrow['life'] >= 1200) {
                unset($arrow);
                $this->removeProjectile($id);
                return;
            }
            unset($arrow);
            $this->arrowTouches($id);
            return;
        }
        if ($arrow['water']) {
            $arrow['velocity'] = array_map(static fn (float $value): float => $value * Float32::round(0.6), $arrow['velocity']);
        }
        $velocity = $arrow['velocity'];
        $arrow['pitch'] = self::lerpRotation($arrow['pitch'], Float32::round(self::mthAtan2($velocity[1], sqrt($velocity[0] ** 2 + $velocity[2] ** 2)) * 180.0 / Float32::round(M_PI)));
        $arrow['yaw'] = self::lerpRotation($arrow['yaw'], Float32::round(self::mthAtan2($velocity[0], $velocity[2]) * 180.0 / Float32::round(M_PI)));
        if (!$arrow['leftOwner']) {
            $search = self::inflate(self::expandTowards($this->projectileBox($arrow), $velocity), 1.0);
            $arrow['leftOwner'] = $owner === null || $owner->dead || $owner->dimension !== $this->world->dimension || !self::intersects($search, $this->playerBox($owner));
        }
        // stepMoveAndHit: the nearest entity along the move by its position, else the block.
        $end = [$position[0] + $velocity[0], $position[1] + $velocity[1], $position[2] + $velocity[2]];
        $blockHit = $collision->clipBlocks($this->world, $position, $end);
        $to = $blockHit['location'] ?? $end;
        $entityHit = null;
        $nearest = PHP_FLOAT_MAX;
        $margin = max(0.0, min(0.3, Float32::round(($arrow['ticks'] - 2) / 20.0)));
        $search = self::inflate(self::expandTowards($this->projectileBox($arrow), $velocity), 1.0);
        foreach ($this->projectileTargets($arrow, $owner) as $target) {
            if (!self::intersects($search, $target['box'])) {
                continue;
            }
            $location = Collision::clipBox(self::inflate($target['box'], $margin), $position, $to);
            $box = $target['box'];
            $centre = [($box[0] + $box[3]) / 2, $box[1], ($box[2] + $box[5]) / 2];
            $distance = ($centre[0] - $position[0]) ** 2 + ($centre[1] - $position[1]) ** 2 + ($centre[2] - $position[2]) ** 2;
            if ($location !== null && $distance < $nearest) {
                [$nearest, $entityHit] = [$distance, ['entity' => $target, 'location' => $location]];
            }
        }
        $hit = $entityHit ?? ($blockHit === null ? null : ['block' => $blockHit, 'location' => $blockHit['location']]);
        [$arrow['x'], $arrow['y'], $arrow['z']] = $hit['location'] ?? $end;
        unset($arrow);
        if ($hit !== null) {
            $this->projectiles[$id]['needsSync'] = true;
            isset($hit['entity']) ? $this->arrowHitsEntity($id, $hit['entity']) : $this->arrowHitsBlock($id, $hit['block']);
        }
        if (!isset($this->projectiles[$id])) {
            return;
        }
        $arrow = &$this->projectiles[$id];
        if (!$arrow['water']) {
            $arrow['velocity'] = array_map(static fn (float $value): float => $value * Float32::round(0.99), $arrow['velocity']);
        }
        if (!$arrow['inGround']) {
            $arrow['velocity'][1] -= 0.05;
        }
        $arrow['water'] = $this->version->catalog->blockName($this->world->get((int) floor($arrow['x']), (int) floor($arrow['y']), (int) floor($arrow['z']))) === 'water';
        // Entity.baseTick: the fire burns down in flight, water puts it out, and the flag follows.
        $arrow['fire'] = $arrow['water'] ? 0 : max(0, $arrow['fire'] - 1);
        if ($arrow['burning'] !== $arrow['fire'] > 0) {
            $arrow['burning'] = $arrow['fire'] > 0;
            $arrow['changed'][0] = true;
        }
        if ($arrow['y'] < $this->world->minY - 64) {
            unset($arrow);
            $this->removeProjectile($id);
        }
    }

    /**
     * AbstractArrow.onHitEntity: its speed times its base damage of 2, rounded up, with a random
     * extra for a critical arrow; it is gone after a hit, and bounces back off what it cannot hurt.
     */
    private function arrowHitsEntity(int $id, array $target): void
    {
        $arrow = $this->projectiles[$id];
        $owner = $this->projectileOwner($arrow);
        $speed = Float32::round(sqrt($arrow['velocity'][0] ** 2 + $arrow['velocity'][1] ** 2 + $arrow['velocity'][2] ** 2));
        $damage = (int) ceil(min(max($speed * Float32::round($arrow['damage']), 0.0), 2.147483647E9));
        if ($arrow['critical']) {
            $damage = min($damage + random_int(0, intdiv($damage, 2) + 1), 2147483647);
        }
        $source = ['id' => $id, 'velocity' => $arrow['velocity']];
        $hurt = false;
        // A burning arrow sets what it hits alight for 5 seconds, which a failed hit takes back.
        $burning = $arrow['fire'] > 0;
        if (isset($target['player'])) {
            $fire = $target['player']->fireTicks;
            if ($burning) {
                $target['player']->fireTicks = max($fire, 100);
            }
            $hurt = $this->damage($target['player'], (float) $damage, 'minecraft:arrow', $owner, $source);
            if (!$hurt) {
                $target['player']->fireTicks = $fire;
            } elseif ($arrow['punch'] > 0.0) {
                $this->punchPlayer($target['player'], $arrow);
            }
            // The shooter hears its arrow hit another player.
            if ($hurt && $owner !== null && $owner !== $target['player']) {
                $owner->send($this->version, 'game_event', "\x06" . pack('G', 0.0));
            }
        } elseif (isset($target['mob'])) {
            $fire = $this->animals[$target['mob']]['fire'] ?? 0;
            if ($burning) {
                $this->animals[$target['mob']]['fire'] = max($fire, 100);
            }
            $hurt = $this->hurtMob($target['mob'], (float) $damage, 'minecraft:arrow', $owner, $source);
            if (isset($this->animals[$target['mob']])) {
                if (!$hurt) {
                    $this->animals[$target['mob']]['fire'] = $fire;
                } elseif ($arrow['punch'] > 0.0) {
                    $this->punchMob($this->animals[$target['mob']], $arrow);
                }
            }
        }
        if ($hurt) {
            $this->arrowSound('entity.arrow.hit', 6, $arrow['x'], $arrow['y'], $arrow['z'], $this->arrowHitPitch());
            $this->removeProjectile($id);
            return;
        }
        if (isset($target['other'])) {
            return;
        }
        // ProjectileDeflection.REVERSE at a fifth of the power: back at a tenth, turned about.
        $projectile = &$this->projectiles[$id];
        $projectile['velocity'] = [$projectile['velocity'][0] * -0.1, $projectile['velocity'][1] * -0.1, $projectile['velocity'][2] * -0.1];
        $projectile['yaw'] = Float32::round($projectile['yaw'] + Float32::round(170.0 + Float32::round(self::randomUnit() * 20.0)));
        $projectile['needsSync'] = true;
        if ($projectile['velocity'][0] ** 2 + $projectile['velocity'][1] ** 2 + $projectile['velocity'][2] ** 2 < 1.0E-7) {
            unset($projectile);
            if ($arrow['pickup'] === 'allowed') {
                $this->dropStack($arrow['x'], $arrow['y'] + 0.1, $arrow['z'], $arrow['stack'], 0, null, 10);
            }
            $this->removeProjectile($id);
        }
    }

    /**
     * AbstractArrow.onHitBlock: the block reacts (a bell rings), the arrow backs off 0.05 along each
     * axis it was moving on, stops and sticks, shaking for 7 ticks, and is no longer critical.
     */
    private function arrowHitsBlock(int $id, array $hit): void
    {
        $arrow = &$this->projectiles[$id];
        [$x, $y, $z] = $hit['position'];
        $arrow['lastState'] = $this->world->get($x, $y, $z);
        $name = $this->version->catalog->blockName($arrow['lastState']);
        if ($name === 'bell') {
            $this->useBell($x, $y, $z, $hit['face'], $hit['location'][1] - $y);
        } elseif ($name === 'tnt' && $arrow['fire'] > 0 && $this->gameRule('tnt_explodes') === 'true') {
            // TntBlock.onProjectileHit: a burning arrow primes it.
            $this->primeTnt($x, $y, $z, $this->projectileOwner($arrow));
            $this->setBlock($x, $y, $z, $this->version->catalog->state('air'));
        }
        $back = Float32::round(0.05);
        foreach (['x', 'y', 'z'] as $axis => $key) {
            $arrow[$key] -= ($arrow['velocity'][$axis] <=> 0.0) * $back;
        }
        $arrow['velocity'] = [0.0, 0.0, 0.0];
        $this->arrowSound('entity.arrow.hit', 6, $arrow['x'], $arrow['y'], $arrow['z'], $this->arrowHitPitch());
        $this->setArrowInGround($arrow, true);
        $arrow['shake'] = 7;
        if ($arrow['critical']) {
            $arrow['critical'] = false;
            $arrow['changed'][8] = true;
        }
    }

    /**
     * AbstractArrow.playerTouch: a stopped arrow that no longer shakes goes to a player whose box,
     * grown by 1 sideways and 0.5 up and down, it touches, if it can be picked up by them.
     */
    private function arrowTouches(int $id): void
    {
        $arrow = $this->projectiles[$id];
        if ($arrow['shake'] > 0 || $arrow['pickup'] === 'disallowed') {
            return;
        }
        $box = $this->projectileBox($arrow);
        foreach ($this->localPlayers() as $player) {
            if ($player->dead || $player->gameMode === 3) {
                continue;
            }
            $reach = $this->playerBox($player);
            if (!self::intersects($box, [$reach[0] - 1.0, $reach[1] - 0.5, $reach[2] - 1.0, $reach[3] + 1.0, $reach[4] + 0.5, $reach[5] + 1.0])) {
                continue;
            }
            if ($arrow['pickup'] === 'creative_only' ? $player->gameMode !== 1 : $this->giveStack($player, $arrow['stack']) !== 0) {
                continue;
            }
            foreach ($this->localPlayers() as $viewer) {
                if (isset($viewer->entities[$id])) {
                    $viewer->send($this->version, 'take_item_entity', Binary::writeVarInt($id) . Binary::writeVarInt($player->entityId) . Binary::writeVarInt(1));
                }
            }
            $this->syncInventory($player);
            $this->removeProjectile($id);
            return;
        }
    }

    private function setArrowInGround(array &$arrow, bool $inGround): void
    {
        if ($arrow['inGround'] !== $inGround) {
            $arrow['inGround'] = $inGround;
            $arrow['changed'][10] = true;
        }
    }

    /** The arrow's tracked data: its critical flag at 8 and whether it is in the ground at 10, all of it or the changed entries. */
    private function arrowMetadata(array $arrow, bool $changedOnly): string
    {
        $entries = '';
        if ($changedOnly ? isset($arrow['changed'][0]) : $arrow['burning']) {
            $entries .= "\0" . Binary::writeVarInt(0) . ($arrow['burning'] ? "\1" : "\0");
        }
        if ($changedOnly ? isset($arrow['changed'][8]) : $arrow['critical']) {
            $entries .= "\x08" . Binary::writeVarInt(0) . ($arrow['critical'] ? "\1" : "\0");
        }
        if ($changedOnly ? isset($arrow['changed'][10]) : $arrow['inGround']) {
            $entries .= "\x0a" . Binary::writeVarInt(8) . ($arrow['inGround'] ? "\1" : "\0");
        }
        return $entries === '' ? '' : Binary::writeVarInt($arrow['id']) . $entries . "\xff";
    }

    /**
     * AbstractArrow.doKnockback: Punch pushes along the arrow's flight by its level times 0.6, less
     * the knockback resistance, and lifts by 0.1.
     */
    private function punchVector(array $arrow, float $resistance): ?array
    {
        [$x, , $z] = $arrow['velocity'];
        $length = sqrt($x * $x + $z * $z);
        $scale = $arrow['punch'] * 0.6 * max(0.0, 1.0 - $resistance);
        if ($length < 1.0E-5 || $scale <= 0.0) {
            return null;
        }
        return [$x / $length * $scale, 0.1, $z / $length * $scale];
    }

    private function punchMob(array &$mob, array $arrow): void
    {
        $push = $this->punchVector($arrow, (float) ($this->mobAttributes($mob)['knockback_resistance'] ?? 0.0));
        if ($push !== null) {
            $mob['velocity'] = [$mob['velocity'][0] + $push[0], $mob['velocity'][1] + $push[1], $mob['velocity'][2] + $push[2]];
            $mob['needsSync'] = true;
            $mob['syncVelocity'] = true;
        }
    }

    /** Entity.push on a player marks it hurt, so its motion with the push goes out with the knockback. */
    private function punchPlayer(Client $c, array $arrow): void
    {
        $push = $this->punchVector($arrow, $this->playerAttribute($c, 'knockback_resistance'));
        if ($push === null) {
            return;
        }
        [$vx, $vy, $vz] = $c->knockbackVelocity ?? [0.0, $c->velocityY, 0.0];
        [$vx, $vy, $vz] = [$vx + $push[0], $vy + $push[1], $vz + $push[2]];
        $c->velocityY = $vy;
        $c->knockbackVelocity = [$vx, $vy, $vz];
        $c->knockbackMotion = Binary::writeVarInt($c->entityId) . \PhpMinecraftJavaServer\Protocol\LowPrecisionVector::encode($vx, $vy, $vz);
    }

    private function arrowHitPitch(): float
    {
        return Float32::round(1.2 / Float32::round(Float32::round(self::randomUnit() * 0.2) + 0.9));
    }

    private function arrowSound(string $sound, int $category, float $x, float $y, float $z, float $pitch): void
    {
        $payload = Packets::sound($this->version->blocks['sounds'][$sound], $category, $x, $y, $z, 1.0, $pitch, unpack('J', random_bytes(8))[1]);
        foreach ($this->localPlayers() as $player) {
            if (($player->x - $x) ** 2 + ($player->y - $y) ** 2 + ($player->z - $z) ** 2 < 16 ** 2) {
                $player->send($this->version, 'sound', $payload);
            }
        }
    }

    /**
     * LivingEntity.startUsingItem: the living entity flags (index 8) say the player is using an
     * item, and with which hand, to it and everyone tracking it.
     */
    private function setUsingItem(Client $c, int $hand): void
    {
        $this->sendLivingFlags($c, 1 | ($hand === 1 ? 2 : 0));
    }

    /** LivingEntity.stopUsingItem. */
    private function stopUsingItem(Client $c): void
    {
        $c->drawing = null;
        $this->sendLivingFlags($c, 0);
    }

    private function sendLivingFlags(Client $c, int $flags): void
    {
        $payload = Binary::writeVarInt($c->entityId) . "\x08" . Binary::writeVarInt(0) . chr($flags) . "\xff";
        foreach ($this->localPlayers() as $player) {
            if ($player === $c || isset($player->entities[$c->entityId])) {
                $player->send($this->version, 'set_entity_data', $payload);
            }
        }
    }
}
