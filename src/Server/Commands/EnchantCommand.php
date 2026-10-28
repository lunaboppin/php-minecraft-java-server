<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Protocol\Packets;
use PhpMinecraftJavaServer\Server\Client;

trait EnchantCommand
{
    /**
     * EnchantCommand: enchant each target's main-hand item. A single target reports why it could
     * not be enchanted; with several, only a total failure is reported.
     */
    private function executeEnchant(Client $sender, array $arguments): void
    {
        $definitions = $this->enchantmentDefinitions();
        $id = str_contains($arguments[1], ':') ? $arguments[1] : 'minecraft:' . $arguments[1];
        $definition = $definitions[$id];
        $level = isset($arguments[2]) ? (int) $arguments[2] : 1;
        if ($level > $definition['max_level']) {
            throw new CommandFailure(['translate' => 'commands.enchant.failed.level', 'with' => [$level, $definition['max_level']]]);
        }
        $targets = $this->selectEntities($arguments[0], $sender);
        $single = count($targets) === 1;
        $enchanted = [];
        foreach ($targets as $target) {
            $player = $target['client'] ?? null;
            $stack = $player === null ? null : ($player->inventory[36 + $player->heldSlot] ?? null);
            if ($stack === null) {
                if ($single) {
                    throw new CommandFailure(['translate' => 'commands.enchant.failed.itemless', 'with' => [['text' => $player?->name ?? $this->englishName($this->version->catalog->displayNames['entities'][$target['type']]['translate'] ?? 'entity.' . str_replace(':', '.', $target['type']))]]]);
                }
                continue;
            }
            $item = 'minecraft:' . $this->version->catalog->itemName($stack['item']);
            $existing = array_keys($stack['enchantments'] ?? []);
            $compatible = array_filter($existing, fn (string $other): bool => !$this->enchantmentsCompatible($other, $id)) === [];
            if (!in_array($item, $this->enchantmentIds($definition['supported_items'], 'minecraft:item'), true) || !$compatible) {
                if ($single) {
                    throw new CommandFailure(['translate' => 'commands.enchant.failed.incompatible', 'with' => [['text' => $this->englishName($this->version->catalog->displayNames['items'][$item]['name']['translate'])]]]);
                }
                continue;
            }
            // ItemEnchantments.Mutable.upgrade keeps the higher level.
            $stack['enchantments'][$id] = max($stack['enchantments'][$id] ?? 0, $level);
            $player->inventory[36 + $player->heldSlot] = $stack;
            $this->syncInventory($player);
            $enchanted[] = $target;
        }
        if ($enchanted === []) {
            throw new CommandFailure(['translate' => 'commands.enchant.failed']);
        }
        $name = $this->enchantmentName($id, $level);
        $message = count($enchanted) === 1
            ? ['translate' => 'commands.enchant.success.single', 'with' => [$name, Packets::playerName($enchanted[0]['client']->name, $enchanted[0]['client']->uuid)]]
            : ['translate' => 'commands.enchant.success.multiple', 'with' => [$name, count($enchanted)]];
        $this->sendSuccess($sender, $message, true);
    }

    /** @return array<string, array{description: array, supported_items: string|array, exclusive_set?: string|array, max_level: int}> */
    private function enchantmentDefinitions(): array
    {
        static $definitions = null;
        return $definitions ??= json_decode((string) file_get_contents($this->version->directory . '/enchantments.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /** Enchantment.getFullname: the description in grey (red for curses) and the level unless it is the only one. */
    private function enchantmentName(string $id, int $level): array
    {
        $definition = $this->enchantmentDefinitions()[$id];
        $curse = in_array(substr($id, 10), $this->version->registryTags('minecraft:enchantment')['minecraft:curse'] ?? [], true);
        $name = $definition['description'] + ['color' => $curse ? 'red' : 'gray'];
        if ($level !== 1 || $definition['max_level'] !== 1) {
            $name['extra'] = [['text' => ' '], ['translate' => 'enchantment.level.' . $level]];
        }
        return $name;
    }

    /** Enchantment.areCompatible: different enchantments outside each other's exclusive sets. */
    private function enchantmentsCompatible(string $first, string $second): bool
    {
        $definitions = $this->enchantmentDefinitions();
        return $first !== $second
            && !in_array($second, $this->enchantmentIds($definitions[$first]['exclusive_set'] ?? [], 'minecraft:enchantment'), true)
            && !in_array($first, $this->enchantmentIds($definitions[$second]['exclusive_set'] ?? [], 'minecraft:enchantment'), true);
    }

    /** A HolderSet: an id, a list of ids or a #tag, as full ids. */
    private function enchantmentIds(string|array $set, string $registry): array
    {
        if (is_array($set)) {
            return $set;
        }
        if (!str_starts_with($set, '#')) {
            return [$set];
        }
        return array_map(static fn (string $name): string => 'minecraft:' . $name, $this->version->registryTags($registry)[substr($set, 1)] ?? []);
    }

    /** Component.getString of a translated name, in the server's en_us language. */
    private function englishName(string $key): string
    {
        static $names = null;
        $names ??= json_decode((string) file_get_contents($this->version->directory . '/en-us-names.json'), true, 512, JSON_THROW_ON_ERROR);
        return $names[$key] ?? $key;
    }
}
