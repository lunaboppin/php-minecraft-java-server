<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;
use PhpMinecraftJavaServer\World\Generation\Int64;
use PhpMinecraftJavaServer\World\Generation\XoroshiroRandom;

trait RandomCommand
{
    /** @var array{salt: int, worldSeed: bool, sequenceId: bool, sequences: array<string, XoroshiroRandom>}|null RandomSequences */
    private ?array $randomSequences = null;

    /** RandomCommand: value and roll draw from a range, from a named sequence for operators; reset restarts sequences. */
    private function executeRandom(Client $sender, array $arguments): void
    {
        $action = $arguments[0] ?? '';
        $sequences = &$this->randomSequenceState();
        if ($action === 'reset') {
            $target = $arguments[1] ?? '';
            $salt = isset($arguments[2]) ? (int) $arguments[2] : null;
            $worldSeed = ($arguments[3] ?? 'true') === 'true';
            $sequenceId = ($arguments[4] ?? 'true') === 'true';
            if ($target === '*') {
                if ($salt !== null) {
                    [$sequences['salt'], $sequences['worldSeed'], $sequences['sequenceId']] = [$salt, $worldSeed, $sequenceId];
                }
                $count = count($sequences['sequences']);
                $sequences['sequences'] = [];
                $this->saveRandomSequences();
                $this->sendSuccess($sender, ['translate' => 'commands.random.reset.all.success', 'with' => [$count]], false);
                return;
            }
            $id = str_contains($target, ':') ? $target : 'minecraft:' . $target;
            $sequences['sequences'][$id] = $salt === null ? $this->createRandomSequence($id) : $this->createRandomSequence($id, $salt, $worldSeed, $sequenceId);
            $this->saveRandomSequences();
            $this->sendSuccess($sender, ['translate' => 'commands.random.reset.success', 'with' => [['text' => $id]]], false);
            return;
        }
        $reader = new StringReader($arguments[1] ?? '');
        [$low, $high] = EntitySelector::readBounds($reader, true);
        $min = $low === null ? -2147483648 : (int) $low;
        $max = $high === null ? 2147483647 : (int) $high;
        $span = $max - $min;
        if ($span === 0) {
            throw new CommandFailure(['translate' => 'commands.random.error.range_too_small']);
        }
        if ($span >= 2147483647) {
            throw new CommandFailure(['translate' => 'commands.random.error.range_too_large']);
        }
        if (isset($arguments[2])) {
            $id = str_contains($arguments[2], ':') ? $arguments[2] : 'minecraft:' . $arguments[2];
            $random = $sequences['sequences'][$id] ??= $this->createRandomSequence($id);
            $value = $random->nextInt($max - $min + 1) + $min;
            $this->saveRandomSequences();
        } else {
            // The level's own random, which nothing else here shares.
            $value = random_int($min, $max);
        }
        if ($action === 'roll') {
            $message = ['translate' => 'commands.random.roll', 'with' => [$this->displayName($sender), $value, $min, $max]];
            foreach ($this->players() as $player) {
                $player->send($this->version, 'system_chat', \PhpMinecraftJavaServer\Protocol\Packets::systemChat($message));
            }
            $this->log(json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
            return;
        }
        $this->sendSuccess($sender, ['translate' => 'commands.random.sample.success', 'with' => [$value]], false);
    }

    /**
     * RandomSequences.createSequence: the world seed (when included) xor the salt, upgraded to 128
     * bits, xor the MD5 of the sequence ID (when included), then mixed.
     */
    private function createRandomSequence(string $id, ?int $salt = null, ?bool $worldSeed = null, ?bool $sequenceId = null): XoroshiroRandom
    {
        $state = $this->randomSequenceState();
        $seed = (($worldSeed ?? $state['worldSeed']) ? $this->worlds['overworld']->seed : 0) ^ ($salt ?? $state['salt']);
        // RandomSupport.upgradeSeedTo128bitUnmixed.
        $low = $seed ^ 7640891576956012809;
        $high = Int64::add($low, -7046029254386353131);
        if ($sequenceId ?? $state['sequenceId']) {
            // RandomSupport.seedFromHashOf.
            $hash = unpack('Jlow/Jhigh', md5($id, true));
            [$low, $high] = [$low ^ $hash['low'], $high ^ $hash['high']];
        }
        return new XoroshiroRandom(Int64::mixStafford13($low), Int64::mixStafford13($high));
    }

    /** The server's RandomSequences, loaded with the overworld. */
    private function &randomSequenceState(): array
    {
        if ($this->randomSequences === null) {
            $saved = $this->worlds['overworld']->setting('randomSequences') ?? [];
            $this->randomSequences = ['salt' => $saved['salt'] ?? 0, 'worldSeed' => $saved['worldSeed'] ?? true, 'sequenceId' => $saved['sequenceId'] ?? true, 'sequences' => []];
            foreach ($saved['sequences'] ?? [] as $id => [$low, $high]) {
                $this->randomSequences['sequences'][$id] = new XoroshiroRandom($low, $high);
            }
        }
        return $this->randomSequences;
    }

    private function saveRandomSequences(): void
    {
        $state = $this->randomSequences;
        $state['sequences'] = (object) array_map(static fn (XoroshiroRandom $random): array => $random->state(), $state['sequences']);
        $this->worlds['overworld']->setting('randomSequences', $state);
    }
}
