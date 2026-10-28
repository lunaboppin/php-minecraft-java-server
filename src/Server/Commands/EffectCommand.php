<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait EffectCommand
{
    /** EffectCommands: give and clear. Only players can carry effects here. */
    private function executeEffect(Client $sender, array $arguments): void
    {
        if (($arguments[0] ?? '') === 'clear') {
            $targets = $this->selectPlayers($arguments[1] ?? '@s', $sender);
            $effect = isset($arguments[2]) ? $this->effectId($arguments[2]) : null;
            // CommandResponseTracker: only targets that lost an effect count, and one of them is named.
            $cleared = [];
            foreach ($targets as $target) {
                $removed = false;
                foreach ($effect === null ? array_keys($target->effects) : [$effect] as $id) {
                    $removed = $this->removeEffect($target, $id) || $removed;
                }
                if ($removed) {
                    $cleared[] = $target;
                }
            }
            if ($cleared === []) {
                throw new CommandFailure(['translate' => $effect === null ? 'commands.effect.clear.everything.failed' : 'commands.effect.clear.specific.failed']);
            }
            $single = count($cleared) === 1;
            $this->sendSuccess($sender, $effect === null
                ? ($single ? ['translate' => 'commands.effect.clear.everything.success.single', 'with' => [$this->displayName($cleared[0])]] : ['translate' => 'commands.effect.clear.everything.success.multiple', 'with' => [count($cleared)]])
                : ($single ? ['translate' => 'commands.effect.clear.specific.success.single', 'with' => [$this->effectName($effect), $this->displayName($cleared[0])]] : ['translate' => 'commands.effect.clear.specific.success.multiple', 'with' => [$this->effectName($effect), count($cleared)]]), true);
            return;
        }
        if (($arguments[0] ?? '') !== 'give') {
            throw new \InvalidArgumentException('Usage: /effect give|clear ...');
        }
        $targets = $this->selectPlayers($arguments[1], $sender);
        $effect = $this->effectId($arguments[2]);
        $instant = $this->effectDefinitions()[$effect]['instant'];
        // EffectCommands.giveEffect: seconds, or ticks for instant effects; "infinite" is -1.
        $seconds = $arguments[3] ?? null;
        $duration = match (true) {
            $seconds === null => $instant ? 1 : 600,
            $seconds === 'infinite' => -1,
            $instant => (int) $seconds,
            default => (int) $seconds * 20,
        };
        $amplifier = (int) ($arguments[4] ?? 0);
        $particles = ($arguments[5] ?? 'false') !== 'true';
        $given = [];
        foreach ($targets as $target) {
            // MobEffectInstance(effect, duration, amplifier, ambient, visible): the icon follows the particles.
            if ($this->giveEffect($target, $effect, $duration, $amplifier, false, $particles, $particles)) {
                $given[] = $target;
            }
        }
        if ($given === []) {
            throw new CommandFailure(['translate' => 'commands.effect.give.failed']);
        }
        $seconds = intdiv($duration, 20);
        $this->sendSuccess($sender, count($given) === 1
            ? ['translate' => 'commands.effect.give.success.single', 'with' => [$this->effectName($effect), $this->displayName($given[0]), $seconds]]
            : ['translate' => 'commands.effect.give.success.multiple', 'with' => [$this->effectName($effect), count($given), $seconds]], true);
    }

    private function effectId(string $text): string
    {
        return str_contains($text, ':') ? $text : 'minecraft:' . $text;
    }

    /** MobEffect.getDisplayName */
    private function effectName(string $effect): array
    {
        return ['translate' => $this->effectDefinitions()[$effect]['description']];
    }
}
