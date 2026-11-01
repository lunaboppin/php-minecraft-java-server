<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;
use PhpMinecraftJavaServer\World\Generation\LegacyRandom;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Decorator;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\ProtoChunk;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Region;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\WorldgenRandom;
use PhpMinecraftJavaServer\World\Vanilla\F32;
use PhpMinecraftJavaServer\World\Vanilla\Structure\BlockTransform;
use PhpMinecraftJavaServer\World\Vanilla\Structure\ShapeUpdater;
use PhpMinecraftJavaServer\World\Vanilla\Structure\StructureContext;
use PhpMinecraftJavaServer\World\Vanilla\Structure\Template;

trait PlaceCommand
{
    /**
     * PlaceCommand: a configured feature, as worldgen places it, or a structure template, rotated,
     * mirrored and with blocks left out at random below full integrity. Structures and jigsaw
     * pools are not placed yet.
     */
    private function executePlace(Client $sender, array $arguments, string $text): void
    {
        match ($arguments[0] ?? '') {
            'feature' => $this->placeFeatureCommand($sender, $arguments, $text),
            'template' => $this->placeTemplateCommand($sender, $arguments),
            default => throw new \InvalidArgumentException('Placing structures and jigsaw pools is not supported yet'),
        };
    }

    private function placeTemplateCommand(Client $sender, array $arguments): void
    {
        $id = str_contains($arguments[1], ':') ? $arguments[1] : 'minecraft:' . $arguments[1];
        $position = isset($arguments[2]) ? $this->loadedBlockPosition(array_slice($arguments, 2, 3), $sender) : array_map(static fn (float $value): int => (int) floor($value), $this->sourcePosition($sender));
        $rotation = array_search($arguments[5] ?? 'none', ['none', 'clockwise_90', '180', 'counterclockwise_90'], true);
        $mirror = ['none' => BlockTransform::NONE, 'left_right' => BlockTransform::LEFT_RIGHT, 'front_back' => BlockTransform::FRONT_BACK][$arguments[6] ?? 'none'];
        $integrity = F32::r((float) ($arguments[7] ?? 1.0));
        $seed = (int) ($arguments[8] ?? 0);
        $strict = ($arguments[9] ?? '') === 'strict';
        $directory = $this->version->directory . '/worldgen';
        $path = substr($id, 10);
        if (!str_starts_with($id, 'minecraft:') || !is_file($directory . '/template/' . $path . '.json.gz')) {
            throw new CommandFailure(['translate' => 'commands.place.template.invalid', 'with' => [['text' => $id]]]);
        }
        $decorator = $this->treeDecorator();
        $template = Template::load($decorator->blocks, $directory, $path);
        $dimension = $this->sourceDimension($sender);
        // PlaceCommand.checkLoaded: the chunks from the position to the far corner of the unturned size.
        for ($chunkX = $position[0] >> 4; $chunkX <= ($position[0] + $template->size[0]) >> 4; ++$chunkX) {
            for ($chunkZ = $position[2] >> 4; $chunkZ <= ($position[2] + $template->size[2]) >> 4; ++$chunkZ) {
                if (!$this->commandChunkLoaded($dimension, $chunkX, $chunkZ)) {
                    throw new CommandFailure(['translate' => 'argument.pos.unloaded']);
                }
            }
        }
        // StructureBlockEntity.createRandom: the seed, or the time when it is 0.
        $random = static fn (): LegacyRandom => new LegacyRandom($seed !== 0 ? $seed : (int) (microtime(true) * 1000));
        $settingsRandom = $random();
        $process = null;
        if ($integrity < 1.0) {
            // StructurePlaceSettings.getRandomPalette draws from the settings' random first.
            $settingsRandom->nextInt(count($template->palettes));
            $process = static fn (array $info): ?array => $settingsRandom->nextFloat() <= $integrity ? $info : null;
        }
        $context = new StructureContext($decorator->blocks, 0, $directory);
        $context->shapes = $strict ? null : new ShapeUpdater($decorator->blocks, $decorator->survival);
        $box = $template->boundingBox($position, $mirror, $rotation, [0, 0, 0]);
        $placed = $this->withDimension($dimension, fn (): bool => $this->onCommandRegion($decorator, $box, static function (Region $region) use ($template, $context, $position, $mirror, $rotation, $random, $process, $strict): bool {
            $world = [-30000000, $region->minY, -30000000, 30000000, $region->maxY(), 30000000];
            return $template->place($context, $region, $position, $mirror, $rotation, [0, 0, 0], $world, $random(), $process, true, false, !$strict, null, false);
        }));
        if (!$placed) {
            throw new CommandFailure(['translate' => 'commands.place.template.failed']);
        }
        // Jigsaw blocks keep their connection data, which their update packets carry.
        $this->withDimension($dimension, function () use ($context): void {
            foreach ($context->blockEntities as $key => $entity) {
                if (preg_replace('/^minecraft:/', '', $entity['id'] ?? '') === 'jigsaw') {
                    unset($entity['id']);
                    $this->world->container(array_map('intval', explode(',', $key)), $entity);
                }
            }
        });
        $this->sendSuccess($sender, ['translate' => 'commands.place.template.success', 'with' => [['text' => $id], ...$position]], true);
    }

    private function placeFeatureCommand(Client $sender, array $arguments, string $text): void
    {
        $reader = new StringReader(ltrim($text));
        $reader->readUnquotedString();
        $reader->skipWhitespace();
        $reader->readUnquotedString();
        $reader->skipWhitespace();
        $inline = $reader->canRead() && in_array($reader->peek(), ['{', '['], true);
        $directory = $this->version->directory . '/worldgen';
        if ($inline) {
            $feature = self::plainTag(Snbt::read($reader));
            $id = null;
        } else {
            $id = $reader->readIdentifier();
            $feature = substr($id, 10);
        }
        $reader->skipWhitespace();
        $rest = preg_split('/\s+/', trim(substr($reader->string, $reader->cursor)));
        $position = $rest !== [''] ? $this->loadedBlockPosition(array_slice($rest, 0, 3), $sender) : array_map(static fn (float $value): int => (int) floor($value), $this->sourcePosition($sender));
        $dimension = $this->sourceDimension($sender);
        for ($chunkX = ($position[0] >> 4) - 1; $chunkX <= ($position[0] >> 4) + 1; ++$chunkX) {
            for ($chunkZ = ($position[2] >> 4) - 1; $chunkZ <= ($position[2] >> 4) + 1; ++$chunkZ) {
                if (!$this->commandChunkLoaded($dimension, $chunkX, $chunkZ)) {
                    throw new CommandFailure(['translate' => 'argument.pos.unloaded']);
                }
            }
        }
        $decorator = $this->treeDecorator();
        $random = new WorldgenRandom();
        $random->setSeed(random_int(PHP_INT_MIN, PHP_INT_MAX));
        $box = [$position[0], $position[1], $position[2], $position[0], $position[1], $position[2]];
        try {
            $placed = $this->withDimension($dimension, fn (): bool => $this->onCommandRegion($decorator, $box, static fn (Region $region, Decorator $decorator): bool => $decorator->place($feature, $region, $random, ...$position)));
        } catch (\TypeError|\ValueError|\UnexpectedValueException|\JsonException $error) {
            // A malformed inline feature, which the codec would have refused, places nothing.
            $this->log('Feature not placed: ' . $error->getMessage());
            $placed = false;
        }
        if (!$placed) {
            throw new CommandFailure(['translate' => 'commands.place.feature.failed']);
        }
        $this->sendSuccess($sender, $id === null
            ? ['translate' => 'commands.place.feature.success.inline', 'with' => $position]
            : ['translate' => 'commands.place.feature.success', 'with' => [['text' => $id], ...$position]], true);
    }

    /** An SNBT value as the decoders read JSON: plain values. */
    private static function plainTag(array $tag): mixed
    {
        return match ($tag['type']) {
            'compound', 'list' => array_map(self::plainTag(...), $tag['value']),
            'byte' => $tag['value'] === 0 || $tag['value'] === 1 ? (bool) $tag['value'] : $tag['value'],
            default => $tag['value'],
        };
    }

    /**
     * Run worldgen code on a copy of the chunks around a box, as it sees the level, then set the
     * blocks it changed in the world in the order they were first written, sent as the tick ends.
     *
     * @param array{0: int, 1: int, 2: int, 3: int, 4: int, 5: int} $box
     * @param \Closure(Region, Decorator): bool $work
     */
    private function onCommandRegion(Decorator $decorator, array $box, \Closure $work): bool
    {
        [$minX, $minZ, $maxX, $maxZ] = [($box[0] >> 4) - 1, ($box[2] >> 4) - 1, ($box[3] >> 4) + 1, ($box[5] >> 4) + 1];
        [$centerX, $centerZ] = [$box[0] >> 4, $box[2] >> 4];
        $height = $this->world->height;
        $chunks = [];
        for ($chunkX = $minX; $chunkX <= $maxX; ++$chunkX) {
            for ($chunkZ = $minZ; $chunkZ <= $maxZ; ++$chunkZ) {
                $sections = $this->world->sections($chunkX, $chunkZ);
                $states = [];
                for ($section = 0; $section < intdiv($height, 16); ++$section) {
                    array_push($states, ...($sections[$section] ?? array_fill(0, 4096, 0)));
                }
                $chunks[$chunkX . ',' . $chunkZ] = new ProtoChunk($chunkX, $chunkZ, $states, [], []);
            }
        }
        $radius = max($centerX - $minX, $maxX - $centerX, $centerZ - $minZ, $maxZ - $centerZ);
        $region = new Region($decorator->blocks, $centerX, $centerZ, $chunks, $this->world->minY, $height, 63, 0, $this->world->seed, null, $radius);
        $region->writes = [];
        if (!$work($region, $decorator)) {
            return false;
        }
        $this->world->transaction(function () use ($region): void {
            foreach ($region->writes as [$x, $y, $z]) {
                $state = $region->get($x, $y, $z);
                if ($state !== $this->world->get($x, $y, $z)) {
                    $this->world->set($x, $y, $z, $state);
                    $this->blockChanged($x, $y, $z);
                }
            }
        });
        return true;
    }
}
