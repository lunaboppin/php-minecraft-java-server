<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

/**
 * Campfire cooking (CampfireBlockEntity): a campfire holds up to four foods placed on it, each
 * cooking for its campfire recipe's time while the campfire is lit and cooling back twice as
 * fast while it is out; done, the cooked item drops beside it. Clients see the foods through the
 * block entity's update tag.
 */
trait Campfires
{
    /** The campfire_cooking recipe for an item, or null (RecipePropertySet.CAMPFIRE_INPUT). */
    private function campfireRecipe(int $item): ?array
    {
        return $this->crafting->smelt($item, 'campfire_cooking');
    }

    /**
     * CampfireBlock.useItemOn: a campfire input goes into the first empty slot, counting the
     * campfire as used. Returns null for other items, else whether the food went on.
     */
    private function placeCampfireFood(Client $c, int $slot, int $x, int $y, int $z): ?bool
    {
        $stack = $c->inventory[$slot] ?? null;
        $recipe = $stack === null ? null : $this->campfireRecipe($stack['item']);
        if ($recipe === null) {
            return null;
        }
        $data = $this->world->container([$x, $y, $z]);
        for ($index = 0; $index < 4; ++$index) {
            if (isset($data['slots'][$index])) {
                continue;
            }
            // CampfireBlockEntity.placeFood: one of the stack, kept in creative.
            $data['slots'][$index] = array_replace($stack, ['count' => 1]);
            $data['cooking'][$index] = 0;
            $data['cookingTotal'][$index] = $recipe['ticks'];
            $this->world->container([$x, $y, $z], $data);
            if ($c->gameMode !== 1) {
                \PhpMinecraftJavaServer\World\Inventory::consume($c->inventory, $slot);
                $this->syncInventory($c);
            }
            $this->awardStat($c, 'custom', 'interact_with_campfire');
            $this->blockChanged($x, $y, $z);
            return true;
        }
        return false;
    }

    /** CampfireBlockEntity.cookTick for lit campfires and cooldownTick for the rest. */
    private function tickCampfires(): void
    {
        $catalog = $this->version->catalog;
        foreach ($this->world->containerKeys() as $key) {
            $position = array_map('intval', explode(':', $key));
            [$x, $y, $z] = $position;
            $state = $this->world->get($x, $y, $z);
            $name = $catalog->blockName($state);
            if (($name !== 'campfire' && $name !== 'soul_campfire') || !$this->mobIsTicking(['x' => $x + 0.5, 'z' => $z + 0.5])) {
                continue;
            }
            $data = $this->world->container($position);
            $before = $data;
            if ($catalog->properties($state)['lit'] === 'true') {
                for ($index = 0; $index < 4; ++$index) {
                    $stack = $data['slots'][$index] ?? null;
                    if ($stack === null) {
                        continue;
                    }
                    $data['cooking'][$index] = ($data['cooking'][$index] ?? 0) + 1;
                    if ($data['cooking'][$index] >= ($data['cookingTotal'][$index] ?? 0)) {
                        $recipe = $this->campfireRecipe($stack['item']);
                        $this->dropContainerStack($x, $y, $z, $recipe === null ? $stack : $recipe['result']);
                        unset($data['slots'][$index]);
                        $this->blockChanged($x, $y, $z);
                    }
                }
            } else {
                for ($index = 0; $index < 4; ++$index) {
                    if (($data['cooking'][$index] ?? 0) > 0) {
                        $data['cooking'][$index] = max(0, min($data['cookingTotal'][$index] ?? 0, $data['cooking'][$index] - 2));
                    }
                }
            }
            if ($data !== $before) {
                $this->world->container($position, $data);
            }
        }
    }
}
