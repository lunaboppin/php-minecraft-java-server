<?php
declare(strict_types=1);
require_once __DIR__.'/../src/bootstrap.php';
use PhpMinecraftJavaServer\World\Combat;
use PhpMinecraftJavaServer\Protocol\{Packets,Version};
$combatFixture=json_decode(file_get_contents(__DIR__.'/../reference/26.3/combat.json'),true,512,JSON_THROW_ON_ERROR);$combatChecks=0;
foreach($combatFixture['armor'] as $row){$actual=bin2hex(pack('G',Combat::afterArmor($row['incoming'],$row['armor'],$row['toughness'])));if($actual!==$row['bits']){throw new RuntimeException('Official armor mismatch '.json_encode($row));}++$combatChecks;}
foreach($combatFixture['charged_attacks'] as $row){
    $weapon=$combatFixture['items'][$row['item']]??$combatFixture['default_item'];
    if(bin2hex(pack('G',Combat::attack($weapon['damage'],$weapon['speed'],$row['ticks'])))!==$row['bits']){throw new RuntimeException('Official attack charge mismatch '.json_encode($row));}++$combatChecks;
    if((Combat::strength($weapon['speed'],$row['ticks'],1.0)>=$weapon['minimum_charge'])!==$row['allowed']){throw new RuntimeException('Official minimum attack charge mismatch '.json_encode($row));}++$combatChecks;
}
if(bin2hex(Packets::damageEvent(new Version(),1,2))!==$combatFixture['fixtures']['damage_event']){throw new RuntimeException('Official mob damage-event bytes mismatch');}++$combatChecks;
if(bin2hex(Packets::hurtAnimation(1,90))!==$combatFixture['fixtures']['hurt_animation']){throw new RuntimeException('Official hurt-animation bytes mismatch');}++$combatChecks;
echo 'PASS: '.$combatChecks." official unenchanted armor and damage-event codec comparisons\n";
