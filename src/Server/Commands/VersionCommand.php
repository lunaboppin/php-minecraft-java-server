<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Protocol\Packets;
use PhpMinecraftJavaServer\Server\Client;

trait VersionCommand
{
    private function executeVersion(Client $sender, array $arguments): void
    {
        $info = $this->version->info;
        // Component.translationArg(Date) renders Java's Date.toString, here in UTC.
        $built = gmdate('D M d H:i:s \U\T\C Y', strtotime($info['build_time']));
        $lines = [
            ['translate' => 'commands.version.header'],
            ['translate' => 'commands.version.id', 'with' => [['text' => $info['name']]]],
            ['translate' => 'commands.version.name', 'with' => [['text' => $info['name']]]],
            ['translate' => 'commands.version.data', 'with' => [$info['data_version']]],
            ['translate' => 'commands.version.series', 'with' => [['text' => $info['series']]]],
            ['translate' => 'commands.version.protocol', 'with' => [$info['protocol'], ['text' => '0x' . dechex($info['protocol'])]]],
            ['translate' => 'commands.version.build_time', 'with' => [['text' => $built]]],
            ['translate' => 'commands.version.pack.resource', 'with' => [['text' => $info['resource_pack']]]],
            ['translate' => 'commands.version.pack.data', 'with' => [['text' => $info['data_pack']]]],
            ['translate' => $info['stable'] ? 'commands.version.stable.yes' : 'commands.version.stable.no'],
        ];
        // sendSystemMessage: no operator echo and no console log.
        foreach ($lines as $line) {
            $sender->send($this->version, 'system_chat', Packets::systemChat($line));
        }
    }
}
