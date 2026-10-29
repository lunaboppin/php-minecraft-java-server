<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Protocol\Packets;
use PhpMinecraftJavaServer\Server\Client;

trait DamageCommand
{
    private function executeDamage(Client $sender, array $arguments): void
    {
        // /damage <target> <amount> [<damageType> [by <entity>]]; the "at" and "from" forms are not supported yet.
        if (count($arguments) < 2 || count($arguments) === 4 || count($arguments) > 5 || (count($arguments) === 5 && $arguments[3] !== 'by')) {
            throw new \InvalidArgumentException('Usage: /damage <target> <amount> [<damageType> [by <entity>]]');
        }
        $targets = $this->selectEntities($arguments[0], $sender);
        if (count($targets) !== 1) {
            throw new CommandFailure(['translate' => 'argument.entity.toomany']);
        }
        if (!is_numeric($arguments[1]) || (float) $arguments[1] < 0) {
            throw new \InvalidArgumentException('Amount must be a number of at least 0');
        }
        // FloatArgumentType: the amount is a Java float.
        $amount = unpack('G', pack('G', (float) $arguments[1]))[1];
        $type = 'minecraft:' . preg_replace('/^minecraft:/', '', $arguments[2] ?? 'generic');
        if (!isset($this->version->catalog->damageTypes['types'][$type])) {
            throw new CommandFailure(['translate' => 'argument.resource.not_found', 'with' => [['text' => $type], ['text' => 'minecraft:damage_type']]]);
        }
        $attacker = null;
        if (count($arguments) === 5) {
            $attackers = $this->selectPlayers($arguments[4], $sender);
            if (count($attackers) !== 1) {
                throw new CommandFailure(['translate' => 'argument.entity.toomany']);
            }
            $attacker = $attackers[0];
        }
        $target = $targets[0];
        // The reply names the entity even when the damage removes it.
        $name = $this->entityName($target);
        // Mobs take LivingEntity.hurtServer as players do; dropped items take ItemEntity.hurtServer.
        $hurt = match (true) {
            isset($target['client']) => $this->damage($target['client'], $amount, $type, $attacker),
            isset($target['mob']) => $this->withDimension($sender->dimension, fn () => $this->hurtMob($target['mob'], $amount, $type, $attacker)),
            isset($target['item']) => $this->withDimension($sender->dimension, fn () => $this->hurtItem($target['item'], $amount, $type)),
            default => false,
        };
        if (!$hurt) {
            throw new CommandFailure(['translate' => 'commands.damage.invulnerable']);
        }
        $this->sendSuccess($sender, ['translate' => 'commands.damage.success', 'with' => [$amount, $name]], true);
    }
}
