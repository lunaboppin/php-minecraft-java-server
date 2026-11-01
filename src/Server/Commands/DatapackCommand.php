<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait DatapackCommand
{
    /** Built-in packs that need experimental features, which worlds here never enable. */
    private const FEATURE_PACKS = ['minecart_improvements', 'redstone_experiments', 'trade_rebalance'];

    /**
     * DataPackCommand: list, enable and disable packs, and create empty ones in the world's
     * datapacks folder. Selections are kept with the world; the packs' contents are not loaded,
     * so only the built-in vanilla data applies.
     */
    private function executeDatapack(Client $sender, array $arguments, string $text): void
    {
        $reader = new StringReader((string) preg_replace('/\A\/?\s*datapack\s+/', '', $text));
        $action = $reader->readUnquotedString();
        $reader->skipWhitespace();
        switch ($action) {
            case 'list':
                $which = $reader->canRead() ? $reader->readUnquotedString() : '';
                if ($which !== 'available') {
                    $this->listEnabledPacks($sender);
                }
                if ($which !== 'enabled') {
                    $this->listAvailablePacks($sender);
                }
                return;
            case 'create':
                $id = $reader->readString();
                $reader->skipWhitespace();
                $this->createPack($sender, $id, TextComponentArgument::read($reader));
                return;
            case 'disable':
                $id = $this->packArgument($reader->readString(), false);
                $selected = array_values(array_diff($this->selectedPacks(), [$id]));
                $this->sendSuccess($sender, ['translate' => 'commands.datapack.modify.disable', 'with' => [$this->packLink($id, true)]], true);
                $this->worlds['overworld']->setting('dataPacks', $selected);
                return;
            default:
                $id = $this->packArgument($reader->readString(), true);
                $reader->skipWhitespace();
                $selected = $this->selectedPacks();
                $where = $reader->canRead() ? $reader->readUnquotedString() : '';
                if ($where === 'before' || $where === 'after') {
                    $reader->skipWhitespace();
                    $existing = $this->packArgument($reader->readString(), false);
                    array_splice($selected, (int) array_search($existing, $selected, true) + ($where === 'after' ? 1 : 0), 0, [$id]);
                } elseif ($where === 'first' || ($where === '' && $id === 'vanilla')) {
                    // Pack.Position.BOTTOM: the vanilla pack goes under the others.
                    array_unshift($selected, $id);
                } else {
                    $selected[] = $id;
                }
                $this->sendSuccess($sender, ['translate' => 'commands.datapack.modify.enable', 'with' => [$this->packLink($id, true)]], true);
                $this->worlds['overworld']->setting('dataPacks', $selected);
        }
    }

    /** @return list<string> the enabled packs, lowest priority first */
    private function selectedPacks(): array
    {
        $available = $this->availablePacks();
        return array_values(array_filter($this->worlds['overworld']->setting('dataPacks') ?? ['vanilla'], static fn (string $id): bool => isset($available[$id])));
    }

    /**
     * PackRepository.reload: the vanilla pack and the world's pack folders, sorted by ID, each
     * with its title and description.
     *
     * @return array<string, array{array|string, array|string}>
     */
    private function availablePacks(): array
    {
        $packs = ['vanilla' => [['translate' => 'dataPack.vanilla.name'], ['translate' => 'dataPack.vanilla.description']]];
        foreach (glob($this->packDirectory() . '/*/pack.mcmeta') ?: [] as $file) {
            $metadata = json_decode((string) file_get_contents($file), true);
            if (is_array($metadata['pack'] ?? null)) {
                $name = basename(dirname($file));
                $packs['file/' . $name] = [['text' => $name], self::jsonComponent($metadata['pack']['description'] ?? '')];
            }
        }
        ksort($packs, SORT_STRING);
        return $packs;
    }

    /** A text component as JSON holds it: strings are plain text and a list is its first element with the rest appended. */
    private static function jsonComponent(mixed $json): array
    {
        if (is_array($json) && array_is_list($json) && $json !== []) {
            $first = self::jsonComponent($json[0]);
            $first['extra'] = [...$first['extra'] ?? [], ...array_map(self::jsonComponent(...), array_slice($json, 1))];
            return $first;
        }
        if (!is_array($json)) {
            return ['text' => is_scalar($json) ? (string) $json : ''];
        }
        if (isset($json['extra']) && is_array($json['extra'])) {
            $json['extra'] = array_map(self::jsonComponent(...), $json['extra']);
        }
        return $json;
    }

    private function packDirectory(): string
    {
        return dirname($this->config->worldPath) . '/datapacks';
    }

    /** DataPackCommand.getPack: a known pack that is (or, enabling, is not) selected and needs no missing features. */
    private function packArgument(string $id, bool $enabling): string
    {
        if (in_array($id, self::FEATURE_PACKS, true)) {
            throw new CommandFailure($enabling
                ? ['translate' => 'commands.datapack.enable.failed.no_flags', 'with' => [['text' => $id], ['text' => 'minecraft:' . $id]]]
                : ['translate' => 'commands.datapack.disable.failed', 'with' => [['text' => $id]]]);
        }
        if (!isset($this->availablePacks()[$id])) {
            throw new CommandFailure(['translate' => 'commands.datapack.unknown', 'with' => [['text' => $id]]]);
        }
        if (in_array($id, $this->selectedPacks(), true) === $enabling) {
            throw new CommandFailure(['translate' => $enabling ? 'commands.datapack.enable.failed' : 'commands.datapack.disable.failed', 'with' => [['text' => $id]]]);
        }
        return $id;
    }

    /** Pack.getChatLink: the ID and source in brackets, green when enabled, titled and described on hover. */
    private function packLink(string $id, bool $enabled): array
    {
        [$title, $description] = $this->availablePacks()[$id];
        // StringArgumentType.escapeIfRequired quotes IDs with characters an unquoted string can't hold.
        $insertion = preg_match('/\A[0-9A-Za-z_\-.+]*\z/', $id) === 1 ? $id : '"' . addcslashes($id, '"\\') . '"';
        return ['translate' => 'chat.square_brackets', 'with' => [['translate' => 'pack.nameAndSource', 'with' => [['text' => $id], ['translate' => $id === 'vanilla' ? 'pack.source.builtin' : 'pack.source.world']], 'color' => 'gray']],
            'color' => $enabled ? 'green' : 'red', 'insertion' => $insertion, 'hover_event' => ['action' => 'show_text', 'value' => ['text' => '', 'extra' => [$title, ['text' => "\n"], $description]]]];
    }

    private function listEnabledPacks(Client $sender): void
    {
        $selected = $this->selectedPacks();
        $this->sendSuccess($sender, $selected === [] ? ['translate' => 'commands.datapack.list.enabled.none']
            : ['translate' => 'commands.datapack.list.enabled.success', 'with' => [count($selected), $this->formatList(array_map(fn (string $id): array => $this->packLink($id, true), $selected))]], false);
    }

    private function listAvailablePacks(Client $sender): void
    {
        $unselected = array_values(array_diff(array_keys($this->availablePacks()), $this->selectedPacks()));
        $this->sendSuccess($sender, $unselected === [] ? ['translate' => 'commands.datapack.list.available.none']
            : ['translate' => 'commands.datapack.list.available.success', 'with' => [count($unselected), $this->formatList(array_map(fn (string $id): array => $this->packLink($id, false), $unselected))]], false);
    }

    /** DataPackCommand.createPack: a folder with an empty data directory and pack.mcmeta for this version. */
    private function createPack(Client $sender, string $id, array $description): void
    {
        if ($id === '.' || $id === '..' || preg_match('/\A[-._a-z0-9]+\z/', $id) !== 1) {
            throw new CommandFailure(['translate' => 'commands.datapack.create.invalid_name', 'with' => [['text' => $id]]]);
        }
        if (preg_match('/\A(?:.*\.|(?:COM|CLOCK\$|CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\..*)?)\z/i', $id) === 1) {
            throw new CommandFailure(['translate' => 'commands.datapack.create.invalid_full_name', 'with' => [['text' => $id]]]);
        }
        $directory = $this->packDirectory() . '/' . $id;
        if (file_exists($directory)) {
            throw new CommandFailure(['translate' => 'commands.datapack.create.already_exists', 'with' => [['text' => $id]]]);
        }
        $format = (int) $this->version->info['data_pack'];
        // A plain text description is written as a string, as the component codec writes it.
        $description = array_keys($description) === ['text'] ? $description['text'] : $description;
        if (!@mkdir($directory . '/data', 0755, true)
            || file_put_contents($directory . '/pack.mcmeta', json_encode(['pack' => ['description' => $description, 'min_format' => $format, 'max_format' => $format]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === false) {
            throw new CommandFailure(['translate' => 'commands.datapack.create.io_failure', 'with' => [['text' => $id]]]);
        }
        $this->sendSuccess($sender, ['translate' => 'commands.datapack.create.success', 'with' => [['text' => $id]]], true);
    }
}
