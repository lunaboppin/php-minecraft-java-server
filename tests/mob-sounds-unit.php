<?php

declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
use PhpMinecraftJavaServer\World\MobAudio;
use PhpMinecraftJavaServer\World\Generation\LegacyRandom;
use PhpMinecraftJavaServer\Protocol\Packets;
use PhpMinecraftJavaServer\Protocol\Version;

$soundFixture = json_decode(file_get_contents(__DIR__ . '/../reference/26.3/mob-sounds.json'), true, 512, JSON_THROW_ON_ERROR);
$soundChecks = 0;
$soundVersion = new Version();
foreach ($soundFixture['mobs'] as $kind => $info) {
    foreach ($info['pitches'] as $sample) {
        $actual = bin2hex(pack('G', MobAudio::pitch(new LegacyRandom((int) $sample['seed']), $info['pitch_method'])));
        if ($actual !== $sample['bits']) {
            throw new RuntimeException('Official voice pitch mismatch: ' . $kind . ' seed ' . $sample['seed']);
        }
        ++$soundChecks;
    }
    foreach (['hurt', 'death'] as $phase) {
        foreach ($info[$phase]['ranges'] ?? [] as $sample) {
            if (pack('G', MobAudio::range($info[$phase], $sample['volume'])) !== pack('G', $sample['range'])) {
                throw new RuntimeException('Official sound range mismatch: ' . $kind . ' ' . $phase);
            }
            ++$soundChecks;
        }
    }
    foreach ($info['variants'] ?? [] as $variant => $voice) {
        $actual = bin2hex(Packets::mobSoundVariant($soundVersion, 1, $kind, $variant));
        if ($actual !== $voice['metadata_hex']) {
            throw new RuntimeException('Official sound variant metadata mismatch: ' . $kind . ' ' . $variant);
        }
        ++$soundChecks;
    }
    foreach ($info['selections'] ?? [] as $sample) {
        $actual = MobAudio::variant($info, new LegacyRandom((int) $sample['seed']));
        if ($actual !== $sample['variant']) {
            throw new RuntimeException('Official sound variant selection mismatch: ' . $kind);
        }
        ++$soundChecks;
    }
}
foreach ($soundFixture['fixtures'] as $sample) {
    $cow = $soundFixture['mobs']['cow'];
    $actual = bin2hex(Packets::sound($cow['hurt']['id'], $cow['category'], ...array_merge($sample['position'], [0.4, 1.125, -42])));
    if ($actual !== $sample['hex']) {
        throw new RuntimeException('Official positional sound codec mismatch ' . json_encode($sample['position']));
    }
    ++$soundChecks;
}
$soundObservations = json_decode(
    file_get_contents(__DIR__ . '/../reference/26.3/mob-sound-observations.json'),
    true,
    512,
    JSON_THROW_ON_ERROR
);
$soundVersionInfo = json_decode(file_get_contents(__DIR__ . '/../reference/26.3/version.json'), true, 512, JSON_THROW_ON_ERROR);
if ($soundObservations['server_sha1'] !== $soundVersionInfo['server_sha1']) {
    throw new RuntimeException('Mob sound observations came from a different reference server');
}
foreach ($soundObservations['observations'] as $observation) {
    if ($observation['kind'] === 'ender_dragon') {
        continue;
    }
    $info = $soundFixture['mobs'][$observation['kind']];
    foreach (['hurt', 'death'] as $phase) {
        $matches = array_filter(
            $observation[$phase],
            static fn (array $event): bool => $event['name'] === $info[$phase]['name']
                && $event['volume'] === $info['volume']
                && $event['category'] === $info['category']
        );
        if (count($matches) !== 1) {
            throw new RuntimeException('Default getter disagrees with official TCP audio: ' . $observation['kind'] . ' ' . $phase);
        }
        ++$soundChecks;
    }
}
echo 'PASS: ' . $soundChecks . " official mob voice, range and sound codec checks\n";
