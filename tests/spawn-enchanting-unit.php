<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use PhpMinecraftJavaServer\Protocol\Version;
use PhpMinecraftJavaServer\World\Generation\LegacyRandom;
use PhpMinecraftJavaServer\World\Spawning\DifficultyEnchantmentProvider;
use PhpMinecraftJavaServer\World\Spawning\EnchantmentSelection;
use PhpMinecraftJavaServer\World\Spawning\MobEquipmentEnchanting;

$enchantingPath = getenv('PHP_MC_ENCHANTING_REFERENCE') ?: __DIR__ . '/../reference/26.3/enchanting.json';
$enchantingReference = json_decode(file_get_contents($enchantingPath), true, 512, JSON_THROW_ON_ERROR);
$enchantingRules = json_decode(file_get_contents(dirname($enchantingPath) . '/enchanting-rules.json'), true, 512, JSON_THROW_ON_ERROR);
if ($enchantingReference['version'] !== Version::NAME || $enchantingReference['protocol'] !== Version::PROTOCOL
    || count($enchantingReference['enchantments']) !== 43 || count($enchantingReference['items']) !== 100
    || count($enchantingReference['available_cases']) !== 17202 || count($enchantingReference['selection_cases']) !== 32400
    || count($enchantingReference['provider_cases']) !== 9600 || count($enchantingReference['mob_cases']) !== 3456) {
    throw new RuntimeException('The pinned actual Java enchanting reference is required');
}
$enchantingChecks = 0;
$enchantingAssert = static function (mixed $actual, mixed $expected, string $context) use (&$enchantingChecks): void {
    if ($actual !== $expected) {
        throw new RuntimeException($context . ': expected ' . json_encode($expected) . ', got ' . json_encode($actual));
    }
    ++$enchantingChecks;
};
$enchantingRegistry = json_decode(file_get_contents(__DIR__ . '/../reference/26.3/dynamic-registries.json'), true, 512, JSON_THROW_ON_ERROR)['minecraft:enchantment'];
foreach ($enchantingRules['enchantments'] as $name => $definition) {
    $enchantingAssert($enchantingRegistry[$definition['id']], $name, 'Client-synchronized enchantment registry ID');
}
foreach ($enchantingReference['items'] as $item) {
    if ($item['mode'] !== 'default') {
        continue;
    }
    $default = $enchantingRules['default_items'][$item['id']];
    foreach (['name', 'enchantability', 'book', 'stored_book', 'can_store', 'primary'] as $field) {
        $enchantingAssert($default[$field], $item[$field], 'Runtime item rules ' . $item['name'] . '/' . $field);
    }
}
$enchantingCanonical = static function (array $stack): array {
    foreach (['enchantments', 'storedEnchantments'] as $component) {
        if (isset($stack[$component])) {
            ksort($stack[$component]);
        }
    }
    return $stack;
};
foreach ($enchantingReference['available_cases'] as $index => $case) {
    $actual = EnchantmentSelection::available($case['cost'], $enchantingReference['items'][$case['item']], $enchantingRules['pools'][$case['pool']], $enchantingRules);
    $enchantingAssert($actual, $enchantingReference['selections'][$case['selection']], 'Available enchantments ' . $index);
}
foreach ($enchantingReference['selection_cases'] as $index => $case) {
    $random = new LegacyRandom((int) $case['seed']);
    $actual = EnchantmentSelection::select($random, $enchantingReference['items'][$case['item']], $case['cost'], $enchantingRules['pools'][$case['pool']], $enchantingRules);
    $enchantingAssert($actual, $enchantingReference['selections'][$case['selection']], 'Weighted enchantment selection ' . $index);
    $enchantingAssert((string) (clone $random)->nextLong(), $case['random_next'], 'Selection RNG tail ' . $index);
}
foreach ($enchantingReference['provider_cases'] as $index => $case) {
    $stack = $enchantingReference['states'][$case['initial']];
    $item = $enchantingReference['items'][$case['item']];
    $item['can_store'] = $case['can_store'];
    $random = new LegacyRandom((int) $case['seed']);
    foreach ($case['frames'] as $repeat => $frame) {
        DifficultyEnchantmentProvider::apply($stack, $item, $enchantingRules['provider'], $random, unpack('G', hex2bin($case['special_bits']))[1], $enchantingRules);
        // ItemEnchantments is a map; holder identity hash order is not an
        // observable ordering contract for its semantic entries.
        $enchantingAssert($enchantingCanonical($stack), $enchantingReference['states'][$frame['state']], 'Provider merge ' . $index . '/' . $repeat);
        $enchantingAssert((string) (clone $random)->nextLong(), $frame['random_next'], 'Provider RNG tail ' . $index . '/' . $repeat);
    }
}
foreach ($enchantingReference['mob_owners'] as $kind => $owners) {
    foreach ($owners as $method => $owner) {
        $enchantingAssert($owner, 'net.minecraft.world.entity.Mob', 'Supported native mob enchanting owner ' . $kind . '/' . $method);
    }
}
foreach ($enchantingReference['mob_cases'] as $index => $case) {
    $equipment = [];
    $metadata = [];
    foreach ($case['initial'] as $slot => $state) {
        $equipment[$slot] = $enchantingReference['states'][$state];
        $metadata[$slot] = $enchantingReference['slot_metadata'][$case['metadata'][$slot]];
    }
    $random = new LegacyRandom((int) $case['seed']);
    foreach ($case['frames'] as $repeat => $frame) {
        MobEquipmentEnchanting::apply($equipment, $metadata, $random, unpack('G', hex2bin($case['special_bits']))[1], $enchantingRules);
        foreach ($frame['equipment'] as $slot => $state) {
            $enchantingAssert($enchantingCanonical($equipment[$slot]), $enchantingReference['states'][$state], 'Mob enchanting ' . $index . '/' . $repeat . '/' . $slot);
        }
        $enchantingAssert((string) (clone $random)->nextLong(), $frame['random_next'], 'Mob enchanting RNG tail ' . $index . '/' . $repeat);
    }
}
echo 'PASS: ' . $enchantingChecks . " actual Java enchantment candidate, selection, provider, mob equipment and RNG checks\n";
unset($enchantingReference, $enchantingRules);
