<?php

declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
use PhpMinecraftJavaServer\Protocol\Binary;
use PhpMinecraftJavaServer\Protocol\Framing;
use PhpMinecraftJavaServer\Protocol\Nbt;
use PhpMinecraftJavaServer\Protocol\Packets;
use PhpMinecraftJavaServer\Protocol\ProtocolException;
use PhpMinecraftJavaServer\Protocol\Version;
use PhpMinecraftJavaServer\World\FlatWorld;
use PhpMinecraftJavaServer\World\Palette;
use PhpMinecraftJavaServer\Server\Config;

$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    ++$checks;
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
}
function rejects(callable $fn, string $message): void
{
    try {
        $fn();
    } catch (ProtocolException|InvalidArgumentException|TypeError $e) {
        check(true, $message);
        return;
    }
    check(false, $message);
}
foreach ([0, 1, 127, 128, 255, 2147483647, -1, -2147483648] as $n) {
    check((new Binary(Binary::writeVarInt($n)))->varInt() === $n, 'VarInt ' . $n);
}
check(bin2hex(Binary::writeVarInt(-1)) === 'ffffffff0f', 'Signed VarInt vector');
for ($i = 0; $i < 1000; $i++) {
    $n = random_int(-2147483648, 2147483647);
    check((new Binary(Binary::writeVarInt($n)))->varInt() === $n, 'random VarInt');
}
foreach ([PHP_INT_MIN, -1, 0, 127, 128, PHP_INT_MAX] as $n) {
    check((new Binary(Binary::writeVarLong($n)))->varLong() === $n, 'VarLong ' . $n);
}
rejects(fn () => (new Binary(str_repeat("\xff", 9) . "\2"))->varLong(), 'VarLong overflow');
foreach (["\x80", "\xff\xff\xff\xff\xff\0", "\xff\xff\xff\xff\x10"] as $bad) {
    rejects(fn () => (new Binary($bad))->varInt(), 'malformed VarInt');
}
foreach (["", 'hello', "A😀\0é\n"] as $s) {
    check((new Binary(Binary::writeString($s)))->string() === $s, 'UTF-8 round trip');
}
rejects(fn () => (new Binary("\1\xff"))->string(), 'invalid UTF-8');
rejects(fn () => (new Binary(Binary::writeVarInt(-1)))->string(), 'negative string');
rejects(fn () => Binary::writeString('😀', 1), 'UTF-16 character limit');
check((new Binary(Binary::writeString('😀', 2)))->string(2) === '😀', 'UTF-16 supplementary pair');
check(bin2hex(Nbt::text("\0😀")) === '080008c080eda0bdedb880', 'NBT modified UTF-8');
foreach ([[-33554432, -2048, -33554432], [-1, -64, -1], [0, 0, 0], [33554431, 2047, 33554431]] as $pos) {
    check((new Binary(Binary::writePosition(...$pos)))->position() === $pos, 'signed packed position');
}
foreach ([PHP_INT_MIN, -1, 0, 1, PHP_INT_MAX] as $n) {
    check((new Binary(Binary::writeLong($n)))->long() === $n, 'signed long');
}
foreach ([-1, 0, 1, 256] as $threshold) {
    foreach (['', 'a', str_repeat('Minecraft', 2000)] as $payload) {
        $frame = Framing::encode(4, $payload, $threshold);
        $buffer = '';
        for ($i = 0; $i < strlen($frame) - 1; $i++) {
            $buffer .= $frame[$i];
            check(Framing::extract($buffer, $threshold) === null, 'partial frame remains buffered');
        }
        $buffer .= $frame[-1];
        $decoded = Framing::extract($buffer, $threshold);
        check($decoded === Binary::writeVarInt(4) . $payload && $buffer === '', 'frame round trip');
    }
}
$buffer = Framing::encode(0) . Framing::encode(1, 'test');
check(Framing::extract($buffer) === "\0" && Framing::extract($buffer) === "\1test", 'coalesced frames');
foreach (["\0", "\x80\x80\x80"] as $invalid) {
    rejects(function () use ($invalid) {
        $b = $invalid;
        Framing::extract($b);
    }, 'invalid frame length');
}
$badCompression = ["\1\0", Binary::writeVarInt(256) . 'garbage', Binary::writeVarInt(256) . gzcompress(str_repeat('x', 257)), Binary::writeVarInt(256) . gzcompress(str_repeat('x', 255)), Binary::writeVarInt(256) . gzcompress(str_repeat('x', 256)) . 'junk', Binary::writeVarInt(256) . substr(gzcompress(str_repeat('x', 256)), 0, -1), Binary::writeVarInt(1) . gzcompress('x')];
foreach ($badCompression as $bad) {
    rejects(function () use ($bad) {
        $b = Binary::writeVarInt(strlen($bad)) . $bad;
        Framing::extract($b, 256);
    }, 'malformed compressed stream');
}
$reference = __DIR__ . '/../reference/26.3/';
foreach (json_decode(file_get_contents($reference . 'checksums.json'), true, 512, JSON_THROW_ON_ERROR) as $file => $hash) {
    check(hash_file('sha256', $reference . $file) === $hash, 'reference integrity: ' . $file);
}
$configPath = tempnam(sys_get_temp_dir(), 'php-minecraft-config-');
try {
    file_put_contents($configPath, '{}');
    check(Config::load($configPath)->port === 25565, 'empty config object uses defaults');
    file_put_contents($configPath, '[]');
    rejects(fn () => Config::load($configPath), 'configuration array rejected');
    file_put_contents($configPath, '{"unknownOption":true}');
    rejects(fn () => Config::load($configPath), 'unsupported configuration rejected');
} finally {
    unlink($configPath);
}
check(Config::load(null, ['onlineMode' => true])->onlineMode, 'explicit online mode');
$v = new Version();
$f = json_decode(file_get_contents(__DIR__ . '/../reference/26.3/fixtures.json'), true, 512, JSON_THROW_ON_ERROR);
$settingsBytes = hex2bin($f['client_information']);
check(Packets::readClientInformation(new Binary($settingsBytes)) === ['language' => 'en_gb', 'viewDistance' => 4, 'chatVisibility' => 0, 'chatColors' => true, 'skinParts' => 127, 'mainHand' => 0, 'textFiltering' => false, 'allowsListing' => true, 'particles' => 0], 'official client information fixture');
rejects(fn () => Packets::readClientInformation(new Binary(substr($settingsBytes, 0, -1))), 'truncated settings rejected');
rejects(fn () => Packets::readClientInformation(new Binary($settingsBytes . "\0")), 'trailing settings data rejected');
$wrapped = Packets::readClientInformation(new Binary(Binary::writeString('en_us') . "\xff" . Binary::writeVarInt(-1) . "\2\0" . Binary::writeVarInt(5) . "\0\1" . Binary::writeVarInt(4)));
check($wrapped['viewDistance'] === -1 && $wrapped['chatVisibility'] === 2 && $wrapped['mainHand'] === 0 && $wrapped['particles'] === 1 && $wrapped['chatColors'] === true, 'official signed distance, wrapped enums, zero fallback arm and nonzero boolean');
$confirmationBytes = hex2bin($f['client_teleport_confirmation']);
check(Packets::readTeleportConfirmation(new Binary($confirmationBytes)) === ['id' => 1, 'x' => 0.5, 'y' => 64.0, 'z' => 0.5, 'yaw' => 0.0, 'pitch' => 0.0], 'official teleport confirmation including position and rotation');
rejects(fn () => Packets::readTeleportConfirmation(new Binary("\1")), 'legacy ID-only teleport confirmation rejected');
rejects(fn () => Packets::readTeleportConfirmation(new Binary($confirmationBytes . "\0")), 'extra teleport data rejected');
rejects(fn () => Packets::readTeleportConfirmation(new Binary("\1" . pack('EEEGG', NAN, 64, 0.5, 0, 0))), 'non-finite teleport rejected');
$uuid = hex2bin('5627dd98e6be3c21b8a8e92344183641');
$fixtures = [
    'login_finished' => Packets::loginFinished($uuid, 'Steve', pack('JJ', 0, 1)),
    'play_login' => Packets::playLogin($v, 1, 20, 2),
    'position' => Packets::position(1, 0.5, 64, 0.5),
    'spawn' => Packets::spawn(), 'chat' => Packets::chat('Hello'), 'time' => Packets::time(100),
    'entity_teleport' => Packets::teleportEntity(1, 0.5, 64, 0.5, 0, 0, true),
    'entity_spawn' => Packets::addPlayer(1, $uuid, $v->blocks['player_entity_type'], 0.5, 64, 0.5, 0, 0),
    'time_overworld' => Packets::clockTime($v, 100),
    'light' => FlatWorld::light(),
    'empty_section' => Palette::section(array_fill(0, 4096, 0), $v->registryId('worldgen/biome', 'plains'), 16),
];
$fixtures['head_rotation'] = Packets::headRotation(1, 90);
$fixtures['player_appearance'] = Packets::playerAppearance($v->catalog, 1, 127, 0);
$fixtures['vitals'] = Packets::vitals($v->catalog, 1, 150, 1);
$fixtures['respawn_survival'] = Packets::respawn($v, 0);
$fixtures['death'] = Binary::writeVarInt(1) . Nbt::text('Steve died');
$fixtures['inventory_empty'] = Packets::inventory(0, 1, array_fill(0, 46, null), null, $v->blocks['component_damage']);
$fixtures['open_chest'] = "\1" . Binary::writeVarInt($v->blocks['menus']['generic_9x3']) . Nbt::text('Chest');
$fixtures['item_metadata'] = "\2" . chr($v->catalog->itemMetadataIndex) . Binary::writeVarInt($v->catalog->itemMetadataSerializer) . Packets::stack(['item' => $v->blocks['items']['stone'], 'count' => 1], $v->blocks['component_damage']) . "\xff";
$fixtures['damaged_pickaxe'] = Packets::stack(['item' => $v->blocks['items']['iron_pickaxe'], 'count' => 1, 'damage' => 7], $v->blocks['component_damage']);
$fixtures['mob_attributes'] = Packets::attributes($v->catalog, 1, ['max_health' => 10.0]);
$states = array_fill(0, 4096, 0);
for ($y = 0; $y < 4; $y++) {
    for ($i = 0; $i < 256; $i++) {
        $states[$y * 256 + $i] = $v->blocks['states'][$y === 0 ? 'bedrock' : ($y === 3 ? 'grass_block' : 'dirt')];
    }
}
$fixtures['flat_section'] = Palette::section($states, $v->registryId('worldgen/biome', 'plains'), 16);
foreach ($fixtures as $name => $bytes) {
    check(bin2hex($bytes) === $f[$name], 'official 26.3 fixture: ' . $name);
}
check(bin2hex(Binary::offlineUuid('Steve')) === bin2hex($uuid), 'offline UUID');
check($v->id('play', 'level_chunk_with_light') === 46, 'generated packet ID');
rejects(fn () => $v->name('play', 9999), 'unknown packet');
check(count(iterator_to_array($v->registryPackets())) === 32, 'all synchronized registries');
$path = sys_get_temp_dir() . '/php-minecraft-world-' . bin2hex(random_bytes(8)) . '.sqlite';
try {
    $world = new FlatWorld($v, $path);
    check($world->get(-1, 63, -1) === $v->blocks['states']['grass_block'], 'base terrain');
    $before = $world->chunk(-1, -1);
    $world->set(-1, 64, -1, $v->blocks['states']['stone']);
    check($world->chunk(-1, -1) !== $before, 'chunk cache invalidation');
    unset($world);
    $world = new FlatWorld($v, $path);
    check($world->get(-1, 64, -1) === $v->blocks['states']['stone'], 'persistent edits after reopen');
    $world->set(-1, 64, -1, 0);
    check($world->chunk(-1, -1) === $before, 'restoring procedural state');
    rejects(fn () => $world->set(0, 320, 0, 1), 'world height bound');
    unset($world);
} finally {
    foreach (glob($path . '*') as $file) {
        unlink($file);
    }
}
rejects(fn () => new Config(port:65536), 'invalid port');
rejects(fn () => new Config(viewDistance:1), 'invalid view distance');
check((new Config())->host === '127.0.0.1', 'loopback default');
echo 'PASS: ' . $checks . ' PHP checks, including ' . count($fixtures) . " Mojang codec fixtures\n";
require __DIR__ . '/world.php';
require __DIR__ . '/collision.php';
require __DIR__ . '/dimensions-unit.php';
require __DIR__ . '/logging.php';
require __DIR__ . '/restart-notice.php';
require __DIR__ . '/authentication.php';
require __DIR__ . '/worldgen-noise.php';
require __DIR__ . '/worldgen-density.php';
require __DIR__ . '/mob-combat-unit.php';
require __DIR__ . '/mob-sounds-unit.php';
require __DIR__ . '/commands-unit.php';
require __DIR__ . '/mob-physics-unit.php';
require __DIR__ . '/mob-motion-unit.php';
require __DIR__ . '/mob-tracking-unit.php';
require __DIR__ . '/spawn-initialization-unit.php';
if (in_array('--unit', $argv, true)) {
    exit(0);
}
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/authentication-network.php'), $code);
if ($code !== 0) {
    exit($code);
}
passthru('python3 ' . escapeshellarg(__DIR__ . '/mob-combat.py'), $code);
if ($code !== 0) {
    exit($code);
}
passthru('python3 ' . escapeshellarg(__DIR__ . '/mob-knockback.py'), $code);
if ($code !== 0) {
    exit($code);
}
passthru('python3 ' . escapeshellarg(__DIR__ . '/mob-sounds.py'), $code);
if ($code !== 0) {
    exit($code);
}
passthru('python3 ' . escapeshellarg(__DIR__ . '/integration.py'), $code);
if ($code !== 0) {
    exit($code);
}
passthru('python3 ' . escapeshellarg(__DIR__ . '/terrain.py'), $code);
if ($code !== 0) {
    exit($code);
}
passthru('python3 ' . escapeshellarg(__DIR__ . '/dimensions.py'), $code);
if ($code !== 0) {
    exit($code);
}
passthru('python3 ' . escapeshellarg(__DIR__ . '/placement.py'), $code);
if ($code !== 0) {
    exit($code);
}
passthru('python3 ' . escapeshellarg(__DIR__ . '/settings.py'), $code);
if ($code !== 0) {
    exit($code);
}
passthru('python3 ' . escapeshellarg(__DIR__ . '/multiplayer.py'), $code);
if ($code !== 0) {
    exit($code);
}
passthru('python3 ' . escapeshellarg(__DIR__ . '/equipment.py'), $code);
if ($code !== 0) {
    exit($code);
}
passthru('python3 ' . escapeshellarg(__DIR__ . '/tracking.py'), $code);
if ($code !== 0) {
    exit($code);
}
passthru('python3 ' . escapeshellarg(__DIR__ . '/spawn-eggs.py'), $code);
if ($code !== 0) {
    exit($code);
}
passthru('python3 ' . escapeshellarg(__DIR__ . '/mob-movement.py'), $code);
if ($code !== 0) {
    exit($code);
}
passthru('python3 ' . escapeshellarg(__DIR__ . '/signs-pick.py'), $code);
if ($code !== 0) {
    exit($code);
}
passthru('python3 ' . escapeshellarg(__DIR__ . '/simulation.py'), $code);
exit($code);
