<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use PhpMinecraftJavaServer\Protocol\Binary;
use PhpMinecraftJavaServer\Protocol\ItemStackCodec;
use PhpMinecraftJavaServer\Protocol\Packets;
use PhpMinecraftJavaServer\Protocol\ProtocolException;
use PhpMinecraftJavaServer\Protocol\Version;

$stackPath = getenv('PHP_MC_EQUIPMENT_STACK_REFERENCE') ?: __DIR__ . '/../reference/26.3/equipment-stacks.json';
$stackReference = json_decode(file_get_contents($stackPath), true, 512, JSON_THROW_ON_ERROR);
$stackRules = json_decode(file_get_contents(dirname($stackPath) . '/equipment-stack-rules.json'), true, 512, JSON_THROW_ON_ERROR);
$stackEnchantingRules = json_decode(file_get_contents(__DIR__ . '/../reference/26.3/enchanting-rules.json'), true, 512, JSON_THROW_ON_ERROR);
if ($stackReference['version'] !== Version::NAME || $stackReference['protocol'] !== Version::PROTOCOL
    || count($stackReference['cases']) !== 418 || count($stackReference['equipment_packets']) !== 17
    || count($stackReference['comparison_rows']) !== 246) {
    throw new RuntimeException('The pinned actual Java equipment stack reference is required');
}
$stackCodec = new ItemStackCodec($stackRules, $stackEnchantingRules);
$stackChecks = 0;
$stackAssert = static function (mixed $actual, mixed $expected, string $context) use (&$stackChecks): void {
    if ($actual !== $expected) {
        throw new RuntimeException($context . ': expected ' . json_encode($expected) . ', got ' . json_encode($actual));
    }
    ++$stackChecks;
};
$stackCanonical = static function (?array $stack): ?array {
    if ($stack !== null) {
        foreach (['enchantments', 'storedEnchantments'] as $field) {
            if (isset($stack[$field])) {
                ksort($stack[$field]);
            }
        }
        ksort($stack);
    }
    return $stack;
};
$stackRequests = [];
$stackComparisons = [];
foreach ($stackReference['cases'] as $case) {
    $input = new Binary(hex2bin($case['hex']));
    try {
        $stack = $stackCodec->read($input);
        $accepted = true;
    } catch (ProtocolException) {
        $accepted = false;
    }
    $stackAssert($accepted, $case['accepted'], 'Native stack acceptance ' . $case['name']);
    if (!$accepted) {
        continue;
    }
    $stackAssert($stack, $stackCanonical($case['stack']), 'Native stack patch ' . $case['name']);
    $stackAssert($input->remaining(), $case['remaining'], 'Native stack boundary ' . $case['name']);
    $metadata = $stackCodec->enchantingMetadata($stack);
    foreach ($case['metadata'] as $field => $value) {
        $stackAssert($metadata[$field], $value, 'Current component metadata ' . $case['name'] . '/' . $field);
    }
    $encoded = $stackCodec->write($stack);
    $roundTrip = new Binary($encoded);
    $stackAssert($stackCodec->read($roundTrip), $stack, 'Stack re-encoding ' . $case['name']);
    $roundTrip->end();
    $nativeReencoded = new Binary(hex2bin($case['reencoded_hex']));
    $stackAssert($stackCodec->read($nativeReencoded), $stack, 'Native canonical patch ' . $case['name']);
    $nativeReencoded->end();
    $stackRequests[] = ['kind' => 'stack', 'name' => $case['name'], 'hex' => bin2hex($encoded), 'stack' => $stack];
    if (isset($case['comparison_index'])) {
        $stackComparisons[$case['comparison_index']] = $case['initial'];
    }
}
foreach ($stackReference['comparison_rows'] as $first => $row) {
    $stackAssert(strlen($row), 246, 'Complete native equality row');
    foreach ($stackComparisons as $second => $stack) {
        $stackAssert(
            $stackCodec->same($stackComparisons[$first], $stack),
            $row[$second] === '1',
            'Native same-item/component comparison ' . $first . '/' . $second
        );
    }
}
foreach ($stackReference['equipment_packets'] as $index => $packet) {
    $input = new Binary(hex2bin($packet['hex']));
    $stackAssert($input->varInt(), 17, 'Native equipment entity');
    $equipment = [];
    do {
        $slot = $input->byte();
        $ordinal = $slot & 127;
        $equipment[$ordinal] = $stackCodec->read($input);
        $expected = $ordinal % 2 === 0 ? $stackCanonical($packet['stack']) : null;
        $stackAssert($equipment[$ordinal], $expected, 'Complete native equipment slot ' . $index . '/' . $ordinal);
    } while (($slot & 128) !== 0);
    $input->end();
    $stackAssert(array_keys($equipment), range(0, 7), 'All eight native equipment slots');
    $encoded = Packets::equipment(17, $equipment, 0, $stackCodec);
    $stackRequests[] = ['kind' => 'equipment', 'name' => (string) $index, 'hex' => bin2hex($encoded), 'entity' => 17, 'slots' => $equipment];
}
if ($output = getenv('PHP_MC_EQUIPMENT_STACK_OUTPUT')) {
    file_put_contents($output, json_encode($stackRequests, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n");
}
echo 'PASS: ' . $stackChecks . " actual Java stack patches, component metadata, equality and equipment checks\n";
unset($stackReference, $stackRules, $stackEnchantingRules, $stackRequests);
