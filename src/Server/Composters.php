<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

/**
 * Composters (ComposterBlock): each compostable item may add a layer, always into an empty
 * composter; at seven layers it ripens a second later, and a ripe composter gives bone meal.
 * Hoppers fill it from above and take the bone meal from below.
 */
trait Composters
{
    private ?array $compostables = null;

    /** Compostable.layers: how many layers the item adds now, or null when it is not compostable. */
    private function compostLayers(int $item, int $level): ?int
    {
        $this->compostables ??= json_decode((string) file_get_contents($this->version->directory . '/compostables.json'), true, 512, JSON_THROW_ON_ERROR);
        $provider = $this->compostables['items']['minecraft:' . $this->version->catalog->itemName($item)] ?? null;
        return $provider === null ? null : $this->contextInt($this->compostables['providers'][$provider], $level);
    }

    /** A context int provider: a constant, a number dispatcher on the composter's level, or a weighted list. */
    private function contextInt(int|array $provider, int $level): int
    {
        if (is_int($provider)) {
            return $provider;
        }
        if ($provider['type'] === 'minecraft:number_dispatcher') {
            foreach ($provider['cases'] as $case) {
                if ((int) $case['condition']['state']['level'] === $level) {
                    return $this->contextInt($case['value'], $level);
                }
            }
            return $this->contextInt($provider['default'], $level);
        }
        $roll = random_int(0, array_sum(array_column($provider['distribution'], 'weight')) - 1);
        foreach ($provider['distribution'] as $entry) {
            $roll -= $entry['weight'];
            if ($roll < 0) {
                return $this->contextInt($entry['data'], $level);
            }
        }
        return 0;
    }

    /** ComposterBlock.addLayer: the layers the item adds, ripening in a second at seven. Returns whether any were added. */
    private function addCompostLayer(array $position, int $item): bool
    {
        $level = (int) $this->version->catalog->properties($this->world->get(...$position))['level'];
        $layers = $this->compostLayers($item, $level) ?? 0;
        if ($layers <= 0) {
            return false;
        }
        $level = min(7, $level + $layers);
        $this->setBlock(...[...$position, $this->version->catalog->state('composter', ['level' => (string) $level])]);
        if ($level === 7) {
            $this->scheduleBlock('composter', ...$position, delay: 20);
        }
        return true;
    }

    /**
     * ComposterBlock.useItemOn and useWithoutItem: a compostable item goes in while there is room
     * (level event 1500 says whether it added a layer) and a ripe composter gives its bone meal.
     */
    private function useComposter(Client $c, int $x, int $y, int $z, int $slot): bool
    {
        $catalog = $this->version->catalog;
        $level = (int) $catalog->properties($this->world->get($x, $y, $z))['level'];
        $stack = $c->inventory[$slot] ?? null;
        if ($level < 8 && $stack !== null && $this->compostLayers($stack['item'], $level) !== null) {
            if ($level < 7) {
                $added = $this->addCompostLayer([$x, $y, $z], $stack['item']);
                $this->levelEvent(1500, [$x, $y, $z], $added ? 1 : 0);
                $this->awardStat($c, 'used', $catalog->itemName($stack['item']));
                if ($c->gameMode !== 1) {
                    \PhpMinecraftJavaServer\World\Inventory::consume($c->inventory, $slot);
                    $this->syncInventory($c);
                }
            }
            return true;
        }
        if ($level !== 8) {
            return false;
        }
        // ComposterBlock.extractProduce: bone meal from just above the middle, picked up after 10 ticks.
        $this->dropStack($x + 0.5 + (self::randomUnit() - 0.5) * 0.699999988079071, $y + 1.01, $z + 0.5 + (self::randomUnit() - 0.5) * 0.699999988079071, ['item' => $this->version->blocks['items']['bone_meal'], 'count' => 1], 0, null, 10);
        $this->setBlock($x, $y, $z, $catalog->state('composter', ['level' => '0']));
        $this->blockSoundAt($x, $y, $z, 'block.composter.empty');
        return true;
    }

    /** ComposterBlock.tick: seven layers ripen with the ready sound. */
    private function tickComposter(int $x, int $y, int $z): void
    {
        $state = $this->world->get($x, $y, $z);
        if ($this->version->catalog->blockName($state) === 'composter' && $this->version->catalog->properties($state)['level'] === '7') {
            $this->setBlock($x, $y, $z, $this->version->catalog->state('composter', ['level' => '8']));
            $this->blockSoundAt($x, $y, $z, 'block.composter.ready');
        }
    }

    /** ComposterBlock.onPlace: a composter set at seven layers ripens a second later. */
    private function composterChanged(int $x, int $y, int $z): void
    {
        $state = $this->world->get($x, $y, $z);
        if ($this->version->catalog->blockName($state) === 'composter' && $this->version->catalog->properties($state)['level'] === '7' && !isset($this->blockTicks['composter:' . $x . ':' . $y . ':' . $z])) {
            $this->scheduleBlock('composter', $x, $y, $z, 20);
        }
    }
}
