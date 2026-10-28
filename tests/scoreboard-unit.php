<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use PhpMinecraftJavaServer\World\Scoreboard;

$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    ++$checks;
};
// Orders reported by the official server's /scoreboard objectives list and players list.
$scoreboard = new Scoreboard();
foreach (['kills', 'deaths', 'Ab_c-.+', 'averyveryveryveryveryveryverylongname'] as $name) {
    $scoreboard->addObjective($name, ['criteria' => 'dummy']);
}
$check($scoreboard->objectiveNames() === ['averyveryveryveryveryveryverylongname', 'kills', 'deaths', 'Ab_c-.+'], 'fastutil iteration order');
$scoreboard->removeObjective('kills');
$scoreboard->addObjective('t', ['criteria' => 'trigger']);
$check($scoreboard->objectiveNames() === ['t', 'averyveryveryveryveryveryverylongname', 'deaths', 'Ab_c-.+'], 'removal shifts keys back');
foreach (range(1, 40) as $index) {
    $scoreboard->addObjective('o' . $index, ['criteria' => 'dummy']);
}
$check(count($scoreboard->objectiveNames()) === 44 && count(array_unique($scoreboard->objectiveNames())) === 44, 'rehashing keeps every objective');
$scoreboard->scores = ['Witness' => ['t' => ['value' => 1, 'locked' => true]], 'ParityProbe' => ['t' => ['value' => 1, 'locked' => true]], 'fakeplayer' => ['t' => ['value' => 1, 'locked' => true]]];
$check($scoreboard->holders() === ['fakeplayer', 'Witness', 'ParityProbe'], 'holders in HashMap order');
// scoreboard.dat saves objectives in iteration order and adds them again on load, so a reload
// gives the order of a fresh scoreboard filled in the saved order.
$saved = $scoreboard->toArray();
$fresh = new Scoreboard();
foreach (array_keys($saved['objectives']) as $name) {
    $fresh->addObjective((string) $name, []);
}
$check(Scoreboard::fromArray(json_decode(json_encode($saved), true))->objectiveNames() === $fresh->objectiveNames(), 'a reload adds objectives in saved order');
echo "PASS: {$checks} scoreboard checks\n";
