<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;
use PhpMinecraftJavaServer\World\Vanilla\F32;

/**
 * LocateCommand: the nearest structure of a type or tag, the nearest biome sampled in a spiral,
 * or a point of interest (none are tracked yet, so none are found). Vanilla searches on the
 * server thread; here bin/locate.php searches in its own process, as a search can take longer
 * than players would stay connected, and the result is sent when it finishes.
 */
trait LocateCommand
{
    /** @var list<array{process: resource, output: resource, buffer: string, sender: ?Client, kind: string, name: string, tag: bool, origin: array, started: int}> */
    private array $locating = [];

    private function executeLocate(Client $sender, array $arguments): void
    {
        if (count($arguments) !== 2 || !in_array($arguments[0], ['structure', 'biome', 'poi'], true)) {
            throw new \InvalidArgumentException('Usage: /locate (structure|biome|poi) <id|#tag>');
        }
        [$kind, $argument] = $arguments;
        $tag = str_starts_with($argument, '#');
        $id = $tag ? substr($argument, 1) : $argument;
        $id = str_contains($id, ':') ? $id : 'minecraft:' . $id;
        $printable = ($tag ? '#' : '') . $id;
        $origin = [(int) floor($sender->x), (int) floor($sender->y), (int) floor($sender->z)];
        $directory = $this->version->directory . '/worldgen';
        if ($kind === 'poi') {
            // PoiManager.findClosestWithType within 256 blocks: points of interest are not tracked.
            throw new CommandFailure(['translate' => 'commands.locate.poi.not_found', 'with' => [['text' => $printable]]]);
        }
        if ($kind === 'structure') {
            $names = $this->locateNames($directory . '/tags/structure', $directory . '/structure', $id, $tag);
            if ($names === null) {
                throw new CommandFailure(['translate' => 'commands.locate.structure.invalid', 'with' => [['text' => $printable]]]);
            }
        } else {
            $names = $tag ? array_values($this->version->registryTags('minecraft:worldgen/biome')[$id] ?? []) : [preg_replace('/^minecraft:/', '', $id)];
        }
        if (!$this->startLocate($sender, $sender->dimension, $kind, $names, $origin, $printable, $tag)) {
            throw new CommandFailure(['translate' => "commands.locate.$kind.not_found", 'with' => [['text' => $printable]]]);
        }
    }

    /** Start bin/locate.php for a search; false when the dimension has no vanilla generation. */
    private function startLocate(?Client $sender, string $dimension, string $kind, array $names, array $origin, string $name, bool $tag): bool
    {
        $world = $this->worlds[$dimension] ?? null;
        if ($world?->vanilla === null) {
            return false;
        }
        $path = $dimension === 'overworld' ? $this->config->worldPath : $this->config->worldPath . '.' . $dimension . '.sqlite';
        $command = [PHP_BINARY, '-d', 'memory_limit=768M', __DIR__ . '/../../../bin/locate.php', $path, (string) $world->seed, $dimension, (string) $world->height, $kind, json_encode($names, JSON_THROW_ON_ERROR), ...array_map('strval', $origin)];
        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => STDERR], $pipes);
        if (!is_resource($process)) {
            return false;
        }
        stream_set_blocking($pipes[1], false);
        $this->locating[] = ['process' => $process, 'output' => $pipes[1], 'buffer' => '', 'sender' => $sender, 'kind' => $kind, 'name' => $name, 'tag' => $tag, 'origin' => $origin, 'started' => hrtime(true)];
        return true;
    }

    /** Collect finished searches and report each to the player who asked, if still here. */
    private function pollLocates(): void
    {
        foreach ($this->locating as $index => &$search) {
            $search['buffer'] .= (string) stream_get_contents($search['output']);
            if (proc_get_status($search['process'])['running']) {
                continue;
            }
            $search['buffer'] .= (string) stream_get_contents($search['output']);
            fclose($search['output']);
            proc_close($search['process']);
            $found = json_decode(trim($search['buffer']), true);
            $sender = $search['sender'];
            unset($this->locating[$index]);
            if ($sender === null || $sender->closing) {
                continue;
            }
            if (!is_array($found)) {
                $this->sendFailure($sender, ['translate' => 'commands.locate.' . $search['kind'] . '.not_found', 'with' => [['text' => $search['name']]]]);
                continue;
            }
            $name = $search['tag'] ? $search['name'] . ' (minecraft:' . $found[3] . ')' : $search['name'];
            $this->showLocateResult($sender, $search['origin'], $found, 'commands.locate.' . $search['kind'] . '.success', $search['kind'] === 'biome', $name, $search['started']);
        }
        unset($search);
        $this->locating = array_values($this->locating);
    }

    /**
     * The structures a key or tag names, in the tag's order with nested tags expanded, or null
     * when the key or tag does not exist.
     *
     * @return list<string>|null
     */
    private function locateNames(string $tags, string $elements, string $id, bool $tag): ?array
    {
        [$namespace, $path] = explode(':', $id, 2);
        if ($namespace !== 'minecraft') {
            return null;
        }
        if (!$tag) {
            return is_file("$elements/$path.json") ? [$path] : null;
        }
        if (!is_file("$tags/$path.json")) {
            return null;
        }
        $names = [];
        foreach (json_decode((string) file_get_contents("$tags/$path.json"), true, 512, JSON_THROW_ON_ERROR)['values'] as $value) {
            $value = is_array($value) ? $value['id'] : $value;
            $nested = str_starts_with($value, '#') ? $this->locateNames($tags, $elements, substr($value, 1), true) : [preg_replace('/^minecraft:/', '', $value)];
            array_push($names, ...($nested ?? []));
        }
        return array_values(array_unique($names));
    }

    /** LocateCommand.showLocateResult: green bracketed coordinates that suggest a teleport, and the distance. */
    private function showLocateResult(Client $sender, array $origin, array $found, string $key, bool $includeY, string $name, int $started): void
    {
        [$x, $y, $z] = $found;
        $distance = $includeY
            ? (int) floor(F32::r(sqrt(F32::r((float) (($x - $origin[0]) ** 2 + ($y - $origin[1]) ** 2 + ($z - $origin[2]) ** 2)))))
            : (int) floor(F32::r(sqrt((float) (($x - $origin[0]) ** 2 + ($z - $origin[2]) ** 2))));
        $shownY = $includeY ? (string) $y : '~';
        $coordinates = [
            'translate' => 'chat.square_brackets',
            // The y is a string argument ("~" or the number), written like a plain text component.
            'with' => [['translate' => 'chat.coordinates', 'with' => [$x, ['text' => $shownY], $z]]],
            'color' => 'green',
            'click_event' => ['action' => 'suggest_command', 'command' => "/tp @s $x $shownY $z"],
            'hover_event' => ['action' => 'show_text', 'value' => ['translate' => 'chat.coordinates.tooltip']],
        ];
        $this->sendSuccess($sender, ['translate' => $key, 'with' => [['text' => $name], $coordinates, $distance]], false);
        $this->log(sprintf('Locating element %s took %d ms', $name, intdiv(hrtime(true) - $started, 1000000)));
    }
}
