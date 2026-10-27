<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use PhpMinecraftJavaServer\Protocol\Binary;
use PhpMinecraftJavaServer\Protocol\Components\EnchantmentComponent;
use PhpMinecraftJavaServer\Protocol\ProtocolException;
use PhpMinecraftJavaServer\Protocol\Version;

$componentPath = getenv('PHP_MC_ENCHANTMENT_COMPONENT_REFERENCE') ?: __DIR__ . '/../reference/26.3/enchantment-components.json';
$componentReference = json_decode(file_get_contents($componentPath), true, 512, JSON_THROW_ON_ERROR);
if ($componentReference['version'] !== Version::NAME || $componentReference['protocol'] !== Version::PROTOCOL
    || count($componentReference['registry']) !== 43 || count($componentReference['cases']) !== 419) {
    throw new RuntimeException('The pinned actual Java enchantment component fixture is required');
}
$componentCodec = new EnchantmentComponent($componentReference['registry']);
$componentChecks = 0;
$componentAssert = static function (mixed $actual, mixed $expected, string $context) use (&$componentChecks): void {
    if ($actual !== $expected) {
        throw new RuntimeException($context . ': expected ' . json_encode($expected) . ', got ' . json_encode($actual));
    }
    ++$componentChecks;
};
foreach ($componentReference['cases'] as $case) {
    $bytes = hex2bin($case['hex']);
    $input = new Binary($bytes);
    try {
        $values = $componentCodec->read($input);
        $accepted = true;
    } catch (ProtocolException) {
        $accepted = false;
    }
    $componentAssert($accepted, $case['accepted'], 'Native component acceptance ' . $case['name']);
    if (!$accepted) {
        continue;
    }
    $canonical = $values;
    ksort($canonical);
    $componentAssert($canonical, $case['enchantments'], 'Native enchantment map ' . $case['name']);
    $componentAssert($input->remaining(), $case['remaining'], 'Native component boundary ' . $case['name']);
    $encoded = $componentCodec->write($values);
    if (!str_starts_with($case['name'], 'duplicate-')) {
        $componentAssert(bin2hex($encoded), substr($case['hex'], 0, (strlen($bytes) - $case['remaining']) * 2), 'Native component bytes ' . $case['name']);
    }
    $roundTrip = new Binary($encoded);
    $componentAssert($componentCodec->read($roundTrip), $values, 'Component re-encoding ' . $case['name']);
    $roundTrip->end();
}
echo 'PASS: ' . $componentChecks . " actual Java enchantment component bytes, maps, boundaries and rejection checks\n";
unset($componentReference);
