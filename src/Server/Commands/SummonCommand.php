<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait SummonCommand
{
    private function executeSummon(Client $sender, array $arguments, string $text = ''): void
    {
        $kind = preg_replace('/^minecraft:/', '', $arguments[0] ?? '');
        $info = $this->version->catalog->mobs['entities'][$kind] ?? null;
        // SummonCommand.ensureSummonable: the type must be summonable at all.
        if ($info !== null && (!$info['summonable'] || $kind === 'player')) {
            throw new CommandFailure(['translate' => 'entity.not_summonable', 'with' => [['text' => 'minecraft:' . $kind]]]);
        }
        if (!isset($info['attributes']['max_health'])) {
            throw new \InvalidArgumentException('Unknown living entity type');
        }
        // Without a position the entity appears at the sender's position, as in vanilla.
        $position = count($arguments) < 4 ? [$this->sourcePosition($sender)[0], $this->sourcePosition($sender)[1], $this->sourcePosition($sender)[2]] : $this->positionArguments(array_slice($arguments, 1, 3), $sender);
        $nbt = count($arguments) > 4 ? $this->summonNbt($text) : [];
        $id = $this->spawnAnimal($kind, ...$position);
        if ($id === null) {
            throw new CommandFailure(['translate' => 'commands.summon.failed']);
        }
        $this->applyMobNbt($id, $nbt);
        $animal = $this->animals[$id];
        $this->sendSuccess($sender, ['translate' => 'commands.summon.success', 'with' => [$this->entityComponent($kind, hex2bin($animal['uuid']), $animal['customName'] ?? null)]], true);
    }

    /** The NBT compound after the entity and position, read from the raw input as the parser reads it. */
    private function summonNbt(string $text): array
    {
        $reader = new StringReader($text);
        for ($word = 0; $word < 5; ++$word) {
            while ($reader->canRead() && $reader->peek() !== ' ') {
                $reader->skip();
            }
            $reader->skip();
        }
        return Snbt::read($reader)['value'];
    }

    /**
     * Entity.load for the tags this server keeps: CustomName, CustomNameVisible, NoAI, Silent,
     * Invulnerable, PersistenceRequired, Tags, Health, Rotation and Age (negative for babies).
     *
     * @param array<string, array{type: string, value: mixed}> $nbt
     */
    private function applyMobNbt(int $id, array $nbt): void
    {
        $animal = &$this->animals[$id];
        if (isset($nbt['CustomName'])) {
            $name = TextComponentArgument::decode($nbt['CustomName']);
            if (is_array($name)) {
                $animal['customName'] = $name;
            }
        }
        foreach (['CustomNameVisible' => 'customNameVisible', 'NoAI' => 'noAi', 'Silent' => 'silent', 'Invulnerable' => 'invulnerable', 'PersistenceRequired' => 'persistent'] as $tag => $field) {
            if (isset($nbt[$tag])) {
                $animal[$field] = (int) $nbt[$tag]['value'] !== 0;
            }
        }
        if (isset($nbt['Tags']) && $nbt['Tags']['type'] === 'list') {
            $animal['tags'] = array_map(static fn (array $tag): string => (string) $tag['value'], $nbt['Tags']['value']);
        }
        if (isset($nbt['Health'])) {
            $animal['health'] = max(0.0, min((float) $nbt['Health']['value'], (float) $this->version->catalog->mobs['entities'][$animal['kind']]['attributes']['max_health']));
        }
        if (isset($nbt['Rotation']['value'][0])) {
            $animal['yaw'] = (float) $nbt['Rotation']['value'][0]['value'];
        }
        if (isset($nbt['Age'])) {
            $animal['baby'] = (int) $nbt['Age']['value'] < 0;
        }
        foreach ($this->localPlayers() as $player) {
            if (isset($player->entities[$id])) {
                $player->send($this->version, 'set_entity_data', $this->mobState($animal));
            }
        }
    }
}
