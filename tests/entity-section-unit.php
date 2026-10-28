<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use PhpMinecraftJavaServer\Protocol\Version;
use PhpMinecraftJavaServer\World\EntitySectionIndex;

$sectionPath = getenv('PHP_MC_ENTITY_SECTION_REFERENCE') ?: __DIR__ . '/../reference/26.3/entity-section-queries.json';
$sectionReference = json_decode(file_get_contents($sectionPath), true, 512, JSON_THROW_ON_ERROR);
if ($sectionReference['version'] !== Version::NAME || $sectionReference['protocol'] !== Version::PROTOCOL
    || count($sectionReference['events']) !== 857) {
    throw new RuntimeException('The pinned native section query fixture is required');
}
$sectionVector = static fn (array $bits): array => array_map(static fn (string $value): float => unpack('E', hex2bin($value))[1], $bits);
$sectionIndex = new EntitySectionIndex();
$sectionChecks = 0;
foreach ($sectionReference['events'] as $index => $event) {
    switch ($event['op']) {
        case 'upsert':
            $sectionIndex->upsert($event['id'], $sectionVector($event['position_bits']), $sectionVector($event['bounds_bits']));
            break;
        case 'remove':
            $sectionIndex->remove($event['id']);
            break;
        case 'visibility':
            $sectionIndex->setAccessible($event['section'], $event['accessible']);
            break;
        default:
            throw new RuntimeException('Unknown native section operation');
    }
    foreach ($event['queries'] as $queryIndex => $query) {
        $actual = $sectionIndex->query($sectionVector($query['bounds_bits']));
        if ($actual !== $query['ids']) {
            throw new RuntimeException('Native section result/order differs at event ' . $index . '/' . $queryIndex
                . ': expected ' . json_encode($query['ids']) . ', got ' . json_encode($actual));
        }
        ++$sectionChecks;
    }
}
echo 'PASS: ' . $sectionChecks . " complete native section query results and ordering checks across 857 membership/visibility events\n";
unset($sectionReference);
