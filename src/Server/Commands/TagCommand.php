<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait TagCommand
{
    /**
     * TagCommand: add, remove and list entity tags. Entity.addTag refuses a 1025th tag or one
     * already there; CommandResponseTracker names the one entity changed, or counts them.
     */
    private function executeTag(Client $sender, array $arguments): void
    {
        $targets = $this->selectEntities($arguments[0], $sender);
        if ($arguments[1] === 'list') {
            $tags = [];
            $tagged = [];
            foreach ($targets as $target) {
                $own = $this->entityTags($target);
                array_push($tags, ...$own);
                if ($own !== []) {
                    $tagged[] = $target;
                }
            }
            // ComponentUtils.formatList for strings sorts them and shows each in green.
            $tags = array_values(array_unique($tags));
            usort($tags, static fn (string $a, string $b): int => strcmp(mb_convert_encoding($a, 'UTF-16BE', 'UTF-8'), mb_convert_encoding($b, 'UTF-16BE', 'UTF-8')));
            $single = count($tagged) === 1 ? $this->entityName($tagged[0]) : null;
            $message = $tags === []
                ? ['translate' => 'commands.tag.list.multiple.empty', 'with' => [0]]
                : ($single !== null
                    ? ['translate' => 'commands.tag.list.single.success', 'with' => [$single, count($tags), $this->formatList(array_map(static fn (string $tag): array => ['text' => $tag, 'color' => 'green'], $tags))]]
                    : ['translate' => 'commands.tag.list.multiple.success', 'with' => [count($tagged), count($tags), $this->formatList(array_map(static fn (string $tag): array => ['text' => $tag, 'color' => 'green'], $tags))]]);
            $this->sendSuccess($sender, $message, false);
            return;
        }
        $name = $arguments[2];
        $changed = [];
        foreach ($targets as $target) {
            $tags = $this->entityTags($target);
            $has = in_array($name, $tags, true);
            if ($arguments[1] === 'add' ? ($has || count($tags) >= 1024) : !$has) {
                continue;
            }
            $this->setEntityTags($target, $arguments[1] === 'add' ? [...$tags, $name] : array_values(array_diff($tags, [$name])));
            $changed[] = $target;
        }
        $verb = $arguments[1];
        if ($changed === []) {
            throw new CommandFailure(['translate' => 'commands.tag.' . $verb . '.failed']);
        }
        $this->sendSuccess($sender, count($changed) === 1
            ? ['translate' => 'commands.tag.' . $verb . '.success.single', 'with' => [['text' => $name], $this->entityName($changed[0])]]
            : ['translate' => 'commands.tag.' . $verb . '.success.multiple', 'with' => [['text' => $name], count($changed)]], true);
    }
}
