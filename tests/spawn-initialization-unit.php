<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use PhpMinecraftJavaServer\Protocol\Version;
use PhpMinecraftJavaServer\World\Generation\Float32;
use PhpMinecraftJavaServer\World\Generation\LegacyRandom;
use PhpMinecraftJavaServer\World\Spawning\MobInitialization;
use PhpMinecraftJavaServer\World\Spawning\ModifierMap;
use PhpMinecraftJavaServer\World\Spawning\RegionalDifficulty;
use PhpMinecraftJavaServer\World\Spawning\SpawnAttributes;
use PhpMinecraftJavaServer\World\Spawning\ZombieAttributes;

$spawnFixture = getenv('PHP_MC_SPAWN_REFERENCE') ?: __DIR__ . '/../reference/26.3/mob-initialization.json';
$spawnReference = json_decode(file_get_contents($spawnFixture), true, 512, JSON_THROW_ON_ERROR);
if ($spawnReference['version'] !== Version::NAME || $spawnReference['protocol'] !== Version::PROTOCOL) {
    throw new RuntimeException('The pinned official initialization reference is required');
}
$spawnDouble = static fn (string $bits): float => unpack('E', hex2bin($bits))[1];
$spawnFloat = static fn (string $bits): float => unpack('G', hex2bin($bits))[1];
$spawnChecks = 0;
$spawnAssert = static function (mixed $actual, mixed $expected, string $context) use (&$spawnChecks): void {
    if ($actual !== $expected) {
        throw new RuntimeException($context . ': expected ' . json_encode($expected) . ', got ' . json_encode($actual));
    }
    ++$spawnChecks;
};

foreach ($spawnReference['long_floats'] as $index => $row) {
    $spawnAssert(bin2hex(pack('G', Float32::fromLong((int) $row['input']))), $row['bits'], 'Java long-to-float ' . $index);
}
foreach ($spawnReference['regional_difficulty'] as $index => $row) {
    $difficulty = new RegionalDifficulty($row['difficulty'], (int) $row['day'], (int) $row['inhabited'], $spawnFloat($row['moon_bits']));
    $spawnAssert(bin2hex(pack('G', $difficulty->effective)), $row['effective_bits'], 'Regional difficulty ' . $index);
    $spawnAssert(bin2hex(pack('G', $difficulty->special)), $row['special_bits'], 'Special difficulty ' . $index);
}

$spawnMap = new ModifierMap();
foreach ($spawnReference['modifier_map'] as $index => $row) {
    if ($row['remove']) {
        $spawnMap->remove($row['id']);
    } else {
        $spawnMap->put($row['id'], ['id' => $row['id']]);
    }
    $spawnAssert(array_column($spawnMap->values(), 'id'), $row['order'], 'Native modifier map ' . $index);
}
foreach ($spawnReference['attribute_cases'] as $case) {
    $attributes = new SpawnAttributes($spawnReference['definitions'], [$case['name'] => $spawnDouble($case['initial_base_bits'][0])]);
    foreach ($case['frames'] as $index => $frame) {
        if ($frame['action'] === 'remove') {
            $attributes->remove($case['name'], $frame['id']);
        } elseif ($frame['action'] === 'base') {
            $attributes->setBase($case['name'], $spawnDouble($frame['base_bits'][0]));
        } else {
            $modifier = $frame['modifier'];
            $attributes->replace($case['name'], $modifier['id'], $spawnDouble($modifier['amount_bits'][0]), $modifier['operation'], $modifier['permanent']);
        }
        $spawnAssert(bin2hex(pack('E', $attributes->value($case['name']))), $frame['value_bits'][0], 'Attribute arithmetic ' . $case['name'] . '/' . $index);
    }
}

foreach ($spawnReference['attribute_phases'] as $index => $case) {
    $initial = $case['initial'] ?? $spawnReference['defaults'][$case['kind']];
    $bases = [];
    foreach ($initial['attributes'] as $name => $attribute) {
        $bases[$name] = $spawnDouble($attribute['base_value_bits'][0]);
    }
    $attributes = new SpawnAttributes($spawnReference['definitions'], $bases);
    foreach ($initial['attributes'] as $name => $attribute) {
        foreach ($attribute['modifiers'] as $modifier) {
            $attributes->add($name, $modifier['id'], $spawnDouble($modifier['amount_bits'][0]), $modifier['operation'], $modifier['permanent']);
        }
    }
    $worldRandom = new LegacyRandom((int) $case['seed']);
    $entityRandom = new LegacyRandom((int) $case['entity_seed']);
    $health = $spawnFloat($initial['health_bits']);
    foreach ($case['frames'] as $repeat => $frame) {
        $leftHanded = MobInitialization::initialize($attributes, $worldRandom);
        ZombieAttributes::age($attributes, $repeat === 0 ? $case['baby'] : !$case['baby']);
        ZombieAttributes::initialize($attributes, $entityRandom, $case['kind'], $spawnFloat($case['special_bits']), $case['reason'], $health);
        $actual = $attributes->snapshot();
        $expected = array_replace($initial['attributes'], $frame['attributes']);
        $spawnAssert(array_keys($actual), array_keys($expected), 'Attribute names ' . $index . '/' . $repeat);
        foreach ($expected as $name => $attribute) {
            $spawnAssert(bin2hex(pack('E', $actual[$name]['base'])), $attribute['base_value_bits'][0], 'Base ' . $index . '/' . $repeat . '/' . $name);
            $spawnAssert(bin2hex(pack('E', $actual[$name]['value'])), $attribute['base_value_bits'][1], 'Value ' . $index . '/' . $repeat . '/' . $name);
            $modifiers = [];
            foreach ($actual[$name]['modifiers'] as $modifier) {
                $modifiers[] = ['id' => $modifier['id'], 'operation' => $modifier['operation'], 'amount_bits' => [bin2hex(pack('E', $modifier['amount']))], 'permanent' => $modifier['permanent']];
            }
            $spawnAssert($modifiers, $attribute['modifiers'], 'Modifiers ' . $index . '/' . $repeat . '/' . $name);
        }
        $spawnAssert($leftHanded, $frame['left_handed'], 'Handedness ' . $index . '/' . $repeat);
        $spawnAssert(bin2hex(pack('G', $health)), $frame['health_bits'], 'Health ' . $index . '/' . $repeat);
    }
    $spawnAssert((string) $worldRandom->nextLong(), $case['world_random_next'], 'Shared RNG tail ' . $index);
    $spawnAssert((string) $entityRandom->nextLong(), $case['entity_random_next'], 'Entity RNG tail ' . $index);
}
if (count($spawnReference['attribute_phases']) !== 1920) {
    throw new RuntimeException('The complete five-family initialization phase matrix is required');
}
echo 'PASS: ' . $spawnChecks . " actual Java long-cast, regional difficulty, modifier-map and zombie attribute-phase checks\n";
unset($spawnReference);
