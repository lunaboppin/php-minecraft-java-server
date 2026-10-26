<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use PhpMinecraftJavaServer\Protocol\Version;
use PhpMinecraftJavaServer\World\Generation\LegacyRandom;
use PhpMinecraftJavaServer\World\Spawning\ChickenJockeyInitialization;
use PhpMinecraftJavaServer\World\Spawning\MobInitialization;
use PhpMinecraftJavaServer\World\Spawning\SpawnAttributes;
use PhpMinecraftJavaServer\World\Spawning\SpawnGroupData;
use PhpMinecraftJavaServer\World\Spawning\ZombieAttributes;
use PhpMinecraftJavaServer\World\Spawning\ZombieGroupData;
use PhpMinecraftJavaServer\World\Spawning\ZombieSpawnEnvironment;
use PhpMinecraftJavaServer\World\Spawning\ZombieSpawnGroup;

final class SpawnGroupTestEnvironment implements ZombieSpawnEnvironment
{
    public array $events = [];

    public array $child = [];

    public ?SpawnAttributes $childAttributes = null;

    public ?int $vehicle = null;

    public int $queries = 0;

    public int $added = 0;

    public bool $baby;

    public function __construct(
        private readonly array $case,
        private readonly array $reference,
        private readonly array $definitions,
        private readonly LegacyRandom $random,
        private readonly SpawnAttributes $parentAttributes
    ) {
        $this->baby = $case['initial_baby'];
    }

    public function makeBaby(): void
    {
        $this->baby = true;
        ZombieAttributes::age($this->parentAttributes, true);
    }

    public function findChickens(): array
    {
        $this->events[] = ['type' => 'query'];
        $query = $this->case['queries'][$this->queries++] ?? throw new RuntimeException('Unexpected world query');
        return $query['eligible'];
    }

    public function createChicken(): ?int
    {
        $this->events[] = ['type' => 'initialize_chicken'];
        $bases = [];
        foreach ($this->reference['chicken_initial_attributes'] as $name => $attribute) {
            $bases[$name] = unpack('E', hex2bin($attribute['base_value_bits'][0]))[1];
        }
        $this->childAttributes = new SpawnAttributes($this->definitions, $bases);
        $this->child = ChickenJockeyInitialization::initialize(
            $this->childAttributes,
            $this->random,
            $this->reference['chicken_visual_candidates']['minecraft:plains'],
            $this->reference['chicken_sound_variants']
        );
        return 200;
    }

    public function rideChicken(int $id): bool
    {
        $this->events[] = ['type' => 'ride', 'id' => $id];
        $this->vehicle = $id;
        return true;
    }

    public function addChicken(int $id): void
    {
        $this->events[] = ['type' => 'add', 'id' => $id];
        ++$this->added;
    }
}

$groupReference = json_decode(file_get_contents(getenv('PHP_MC_GROUP_REFERENCE') ?: __DIR__ . '/../reference/26.3/zombie-spawn-groups.json'), true, 512, JSON_THROW_ON_ERROR);
$groupMath = json_decode(file_get_contents(__DIR__ . '/../reference/26.3/mob-initialization.json'), true, 512, JSON_THROW_ON_ERROR);
$groupDefinitions = $groupMath['definitions'];
$groupDefaults = $groupMath['defaults'];
unset($groupMath);
if ($groupReference['version'] !== Version::NAME || $groupReference['protocol'] !== Version::PROTOCOL
    || count($groupReference['cases']) !== 12000 || count($groupReference['chicken_cases']) !== 1072
    || count($groupReference['chicken_visual_candidates']) !== 67) {
    throw new RuntimeException('The complete pinned zombie group reference is required');
}
$groupChecks = 0;
$groupAssert = static function (mixed $actual, mixed $expected, string $context) use (&$groupChecks): void {
    if ($actual !== $expected) {
        throw new RuntimeException($context . ': expected ' . json_encode($expected) . ', got ' . json_encode($actual));
    }
    ++$groupChecks;
};
$groupStateFields = [
    'variant' => 'variant',
    'soundVariant' => 'sound_variant',
    'leftHanded' => 'left_handed',
    'baby' => 'baby',
    'chickenJockey' => 'jockey',
];
$groupAssertAttributes = static function (array $actual, array $expected, string $context) use ($groupAssert): void {
    $groupAssert(array_keys($actual), array_keys($expected), 'Attribute roster ' . $context);
    foreach ($expected as $name => $attribute) {
        $row = $actual[$name];
        $groupAssert(
            [bin2hex(pack('E', $row['base'])), bin2hex(pack('E', $row['value']))],
            $attribute['base_value_bits'],
            'Attribute ' . $name . ' ' . $context
        );
        $modifiers = [];
        foreach ($row['modifiers'] as $modifier) {
            $modifiers[] = [
                'id' => $modifier['id'],
                'operation' => $modifier['operation'],
                'amount_bits' => [bin2hex(pack('E', $modifier['amount']))],
                'permanent' => $modifier['permanent'],
            ];
        }
        $groupAssert($modifiers, $attribute['modifiers'], 'Modifiers ' . $name . ' ' . $context);
    }
};
$groupBranches = ['none' => 0, 'existing' => 0, 'new' => 0];
foreach ($groupReference['cases'] as $index => $case) {
    $bases = [];
    foreach ($groupDefaults[$case['kind']]['attributes'] as $name => $attribute) {
        $bases[$name] = unpack('E', hex2bin($attribute['base_value_bits'][0]))[1];
    }
    $attributes = new SpawnAttributes($groupDefinitions, $bases);
    ZombieAttributes::age($attributes, $case['initial_baby']);
    $random = new LegacyRandom((int) $case['seed']);
    $leftHanded = MobInitialization::initialize($attributes, $random);
    if ($case['group'] < 0) {
        $input = null;
    } elseif ($case['group'] === 3) {
        $input = new class () implements SpawnGroupData {};
    } else {
        $input = new ZombieGroupData($case['group'] > 0, $case['group'] !== 2);
    }
    $environment = new SpawnGroupTestEnvironment($case, $groupReference, $groupDefinitions, $random, $attributes);
    $state = ZombieSpawnGroup::initialize(
        $random,
        $input,
        unpack('G', hex2bin($case['special_bits']))[1],
        $case['reason'],
        $case['initial_baby'],
        $case['initial_pickup'],
        $environment
    );
    $prefix = $case['prefix'];
    $context = $case['kind'] . '/' . $index;
    $groupAssert($state['group'] === $input, $case['same_group'], 'Group identity ' . $context);
    if (isset($case['group_baby'])) {
        $groupAssert($state['group'] instanceof ZombieGroupData, true, 'Group type ' . $context);
        $groupAssert($state['group']->baby, $case['group_baby'], 'Group baby flag ' . $context);
        $groupAssert($state['group']->canSpawnJockey, $case['group_jockey'], 'Group jockey flag ' . $context);
    }
    $groupAssert($state['baby'], $prefix['baby'], 'Baby decision ' . $context);
    $groupAssert($environment->baby, $prefix['baby'], 'Baby world effect ' . $context);
    $groupAssert($state['pickupLoot'], $prefix['pickup_loot'], 'Pickup ' . $context);
    $groupAssert($leftHanded, $prefix['left_handed'], 'Handedness ' . $context);
    $doors = $state['breakDoors'] === null ? $case['initial_doors'] : ($prefix['ground_navigation'] && $state['breakDoors']);
    $groupAssert($doors, $prefix['break_doors'], 'Navigation door state ' . $context);
    $groupAssert($state['vehicle'], $prefix['vehicle'], 'Vehicle decision ' . $context);
    $groupAssert($environment->vehicle, $prefix['vehicle'], 'Riding world effect ' . $context);
    $groupAssert($environment->events, $case['events'], 'World effect order ' . $context);
    $groupAssert($environment->queries, count($case['queries']), 'Queries ' . $context);
    $groupAssert($environment->added, $case['created'], 'Added entities ' . $context);
    $groupAssert((string) $random->nextLong(), $prefix['world_random_next'], 'RNG tail ' . $context);
    $branch = $state['source'] ?? 'none';
    ++$groupBranches[$branch];
    if (isset($case['chicken'])) {
        $expected = $case['chicken'];
        foreach ($groupStateFields as $field => $referenceField) {
            $groupAssert($environment->child[$field], $expected[$referenceField], 'Chicken ' . $field . ' ' . $context);
        }
        $groupAssertAttributes(
            $environment->childAttributes->snapshot(),
            array_replace($groupReference['chicken_initial_attributes'], $expected['attributes']),
            'Chicken ' . $context
        );
    }
}
foreach ($groupReference['chicken_cases'] as $case) {
    $bases = [];
    foreach ($groupReference['chicken_initial_attributes'] as $name => $attribute) {
        $bases[$name] = unpack('E', hex2bin($attribute['base_value_bits'][0]))[1];
    }
    $attributes = new SpawnAttributes($groupDefinitions, $bases);
    $random = new LegacyRandom((int) $case['seed']);
    $state = ChickenJockeyInitialization::initialize($attributes, $random, $groupReference['chicken_visual_candidates'][$case['biome']], $groupReference['chicken_sound_variants']);
    $context = $case['biome'] . '/' . $case['seed'];
    foreach ($groupStateFields as $field => $referenceField) {
        $groupAssert($state[$field], $case['state'][$referenceField], 'Independent chicken ' . $field . ' ' . $context);
    }
    $groupAssertAttributes(
        $attributes->snapshot(),
        array_replace($groupReference['chicken_initial_attributes'], $case['state']['attributes']),
        'Independent chicken ' . $context
    );
    $groupAssert((string) $random->nextLong(), $case['world_random_next'], 'Independent chicken RNG tail ' . $context);
}
echo 'PASS: ' . $groupChecks . ' actual Java zombie group and fresh chicken initialization checks; branches ' . json_encode($groupBranches, JSON_THROW_ON_ERROR) . "\n";
unset($groupReference, $groupDefaults, $environment);
