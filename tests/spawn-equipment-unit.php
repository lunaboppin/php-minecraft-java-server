<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use PhpMinecraftJavaServer\Protocol\Packets;
use PhpMinecraftJavaServer\Protocol\Version;
use PhpMinecraftJavaServer\World\Generation\LegacyRandom;
use PhpMinecraftJavaServer\World\Generation\PositionalRandom;
use PhpMinecraftJavaServer\World\Generation\RandomSource;
use PhpMinecraftJavaServer\World\Spawning\DrownedEquipmentInitialization;
use PhpMinecraftJavaServer\World\Spawning\EquipmentInitializer;
use PhpMinecraftJavaServer\World\Spawning\MobEquipmentInitialization;
use PhpMinecraftJavaServer\World\Spawning\ZombieEquipmentInitialization;
use PhpMinecraftJavaServer\World\Spawning\ZombifiedPiglinEquipmentInitialization;

final class EquipmentScriptedRandom extends RandomSource
{
    private int $position = 0;

    public function __construct(private readonly array $script)
    {
    }

    private function step(string $method): array
    {
        $row = $this->script[$this->position++] ?? throw new RuntimeException('Unexpected equipment RNG call: ' . $method);
        if ($row['method'] !== $method) {
            throw new RuntimeException('Equipment RNG method order differs');
        }
        return $row;
    }

    public function nextInt(?int $bound = null): int
    {
        $row = $this->step('int');
        if ($row['bound'] !== $bound) {
            throw new RuntimeException('Equipment RNG bound differs');
        }
        return $row['value'];
    }

    public function nextFloat(): float
    {
        return unpack('G', hex2bin($this->step('float')['bits']))[1];
    }

    public function nextDouble(): float
    {
        throw new RuntimeException('Unexpected equipment double draw');
    }

    public function nextLong(): int
    {
        throw new RuntimeException('Unexpected equipment long draw');
    }

    public function nextBoolean(): bool
    {
        throw new RuntimeException('Unexpected equipment boolean draw');
    }

    public function setSeed(int $seed): void
    {
        throw new RuntimeException('Unexpected equipment RNG reset');
    }

    public function fork(): RandomSource
    {
        throw new RuntimeException('Unexpected equipment RNG fork');
    }

    public function forkPositional(): PositionalRandom
    {
        throw new RuntimeException('Unexpected equipment positional RNG fork');
    }

    public function complete(): bool
    {
        return $this->position === count($this->script);
    }
}

$equipmentReference = json_decode(file_get_contents(getenv('PHP_MC_EQUIPMENT_REFERENCE') ?: __DIR__ . '/../reference/26.3/mob-equipment.json'), true, 512, JSON_THROW_ON_ERROR);
if ($equipmentReference['version'] !== Version::NAME || $equipmentReference['protocol'] !== Version::PROTOCOL
    || count($equipmentReference['cases']) !== 10368 || count($equipmentReference['scripted_cases']) !== 624
    || count($equipmentReference['owners']) !== 6 || count($equipmentReference['contexts']) !== 12) {
    throw new RuntimeException('The complete pinned native equipment reference is required');
}
$equipmentVersion = new Version();
$equipmentDamageComponent = $equipmentVersion->blocks['component_damage'];
$equipmentChecks = 0;
$equipmentAssert = static function (mixed $actual, mixed $expected, string $context) use (&$equipmentChecks): void {
    if ($actual !== $expected) {
        throw new RuntimeException($context . ': expected ' . json_encode($expected) . ', got ' . json_encode($actual));
    }
    ++$equipmentChecks;
};
$equipmentMethods = [
    'net.minecraft.world.entity.Mob' => MobEquipmentInitialization::class,
    'net.minecraft.world.entity.monster.zombie.Zombie' => ZombieEquipmentInitialization::class,
    'net.minecraft.world.entity.monster.zombie.Drowned' => DrownedEquipmentInitialization::class,
    'net.minecraft.world.entity.monster.zombie.ZombifiedPiglin' => ZombifiedPiglinEquipmentInitialization::class,
];
$equipmentDecodeSnapshot = static function (int $id) use ($equipmentReference): array {
    $state = [];
    foreach ($equipmentReference['snapshots'][$id]['slots'] as $slot => $stackId) {
        $state[$slot] = $equipmentReference['stacks'][$stackId]['state'];
    }
    return $state;
};
foreach ($equipmentReference['stacks'] as $index => $row) {
    $equipmentAssert(bin2hex(Packets::stack($row['state'], $equipmentDamageComponent)), $row['hex'], 'Native item codec ' . $index);
}
foreach ($equipmentReference['snapshots'] as $index => $row) {
    $equipmentAssert(bin2hex(Packets::equipment(17, $equipmentDecodeSnapshot($index), $equipmentDamageComponent)), $row['packet_hex'], 'Native equipment codec ' . $index);
}
$equipmentCompare = static function (array $actual, int $snapshot, string $context) use ($equipmentDecodeSnapshot, $equipmentAssert): void {
    $expected = $equipmentDecodeSnapshot($snapshot);
    foreach ($expected as $slot => $stack) {
        $equipmentAssert($actual[$slot] ?? null, $stack, 'Equipment slot ' . $slot . ' ' . $context);
    }
};
foreach ($equipmentReference['cases'] as $index => $case) {
    $method = $equipmentMethods[$equipmentReference['owners'][$case['kind']]] ?? throw new RuntimeException('Unimplemented native equipment method');
    if (!is_a($method, EquipmentInitializer::class, true)) {
        throw new RuntimeException('Equipment phase contract is missing');
    }
    $equipment = $equipmentDecodeSnapshot($case['initial']);
    $random = new LegacyRandom((int) $case['seed']);
    $context = $equipmentReference['contexts'][$case['context']];
    foreach ($case['frames'] as $repeat => $frame) {
        $method::populate($equipment, $random, $context['world_difficulty'], unpack('G', hex2bin($context['special_bits']))[1], $equipmentReference['constants']);
        $equipmentCompare($equipment, $frame['snapshot'], $case['kind'] . '/' . $index . '/' . $repeat);
        $equipmentAssert((string) (clone $random)->nextLong(), $frame['random_next'], 'Unconsumed RNG tail ' . $index . '/' . $repeat);
    }
}
foreach ($equipmentReference['scripted_cases'] as $index => $case) {
    $method = $equipmentMethods[$equipmentReference['owners'][$case['kind']]] ?? throw new RuntimeException('Unimplemented scripted native equipment method');
    $equipment = $equipmentDecodeSnapshot($case['initial']);
    $random = new EquipmentScriptedRandom($case['script']);
    $context = $equipmentReference['contexts'][$case['context']];
    $method::populate($equipment, $random, $context['world_difficulty'], unpack('G', hex2bin($context['special_bits']))[1], $equipmentReference['constants']);
    $equipmentCompare($equipment, $case['snapshot'], 'Scripted ' . $case['kind'] . '/' . $case['name'] . '/' . $index);
    $equipmentAssert($random->complete(), true, 'Complete native scripted draw consumption ' . $index);
}
echo 'PASS: ' . $equipmentChecks . " actual Java equipment selection, preservation, RNG and packet-byte checks\n";
unset($equipmentReference, $equipmentDecodeSnapshot, $equipmentCompare);
