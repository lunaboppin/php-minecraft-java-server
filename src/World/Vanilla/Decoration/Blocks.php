<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Decoration;

use PhpMinecraftJavaServer\Protocol\Version;

/**
 * Block states for decoration: ids from the catalog, the official per-state behaviour bits
 * (reference/26.3/worldgen/block-behaviour.json) and block tags.
 */
final class Blocks
{
    public const AIR = 1;
    public const SOLID = 2;
    public const MOTION_HEIGHTMAP = 4;
    public const REPLACEABLE = 8;
    public const WATER = 65536;
    public const LAVA = 131072;
    public const SOURCE = 262144;
    public const FULL_CUBE = 524288;
    public const MOTION_NO_LEAVES = 2097152;
    /** Direction indexes of Direction.get3DDataValue. */
    public const DOWN = 0;
    public const UP = 1;
    public const NORTH = 2;
    public const SOUTH = 3;
    public const WEST = 4;
    public const EAST = 5;
    public const OFFSETS = [[0, -1, 0], [0, 1, 0], [0, 0, -1], [0, 0, 1], [-1, 0, 0], [1, 0, 0]];

    /** @var list<int> */
    private readonly array $flags;

    /** @var array<string, array<string, true>> tag => block names */
    private array $tags = [];

    /** @var array<string, int> */
    private array $stateCache = [];

    public function __construct(public readonly Version $version, string $behaviourPath)
    {
        $json = json_decode((string) file_get_contents($behaviourPath), true, 512, JSON_THROW_ON_ERROR);
        $this->flags = array_values(unpack('V*', base64_decode($json['flags'])));
        foreach ($version->blockTags() as $tag => $names) {
            $this->tags[preg_replace('/^minecraft:/', '', $tag)] = array_flip($names);
        }
    }

    public function name(int $state): string
    {
        return $this->version->catalog->blockName($state);
    }

    /** @param array<string, string> $properties */
    public function state(string $name, array $properties = []): int
    {
        $name = preg_replace('/^minecraft:/', '', $name);
        if ($properties === []) {
            return $this->stateCache[$name] ??= $this->version->catalog->state($name);
        }
        return $this->version->catalog->state($name, $properties);
    }

    /** A state from JSON: an id string or {Name|id, Properties|properties}. */
    public function parse(mixed $json): int
    {
        if (is_string($json)) {
            // BlockStateParser: name[key=value,...]
            if (preg_match('/^([^\[]+)\[(.*)\]$/', $json, $match) === 1) {
                $properties = [];
                foreach ($match[2] === '' ? [] : explode(',', $match[2]) as $pair) {
                    [$key, $value] = explode('=', $pair, 2);
                    $properties[trim($key)] = trim($value);
                }
                return $this->state(preg_replace('/^minecraft:/', '', $match[1]), $properties);
            }
            return $this->state($json);
        }
        return $this->state($json['Name'] ?? $json['id'], array_map('strval', $json['Properties'] ?? $json['properties'] ?? []));
    }

    /** @return array<string, string> */
    public function properties(int $state): array
    {
        return $this->version->catalog->properties($state);
    }

    public function with(int $state, string $property, string $value): int
    {
        $properties = $this->properties($state);
        if (!array_key_exists($property, $properties)) {
            return $state;
        }
        $properties[$property] = $value;
        try {
            return $this->state($this->name($state), $properties);
        } catch (\InvalidArgumentException) {
            return $state;
        }
    }

    public function has(int $state, string $property): bool
    {
        return array_key_exists($property, $this->properties($state));
    }

    public function flag(int $state, int $flag): bool
    {
        return ($this->flags[$state] & $flag) !== 0;
    }

    public function isAir(int $state): bool
    {
        return ($this->flags[$state] & self::AIR) !== 0;
    }

    public function isSolid(int $state): bool
    {
        return ($this->flags[$state] & self::SOLID) !== 0;
    }

    public function replaceable(int $state): bool
    {
        return ($this->flags[$state] & self::REPLACEABLE) !== 0;
    }

    public function isWater(int $state): bool
    {
        return ($this->flags[$state] & self::WATER) !== 0;
    }

    public function isLava(int $state): bool
    {
        return ($this->flags[$state] & self::LAVA) !== 0;
    }

    public function hasFluid(int $state): bool
    {
        return ($this->flags[$state] & (self::WATER | self::LAVA)) !== 0;
    }

    public function isSource(int $state): bool
    {
        return ($this->flags[$state] & self::SOURCE) !== 0;
    }

    /** BlockState.isFaceSturdy with full support. */
    public function sturdy(int $state, int $direction): bool
    {
        return ($this->flags[$state] & (16 << $direction)) !== 0;
    }

    /**
     * WallBlock.isCovered: whether the bottom face of the state's collision shape covers a wall's
     * post (side null) or its side towards north, east, south or west.
     */
    public function coversWall(int $state, ?string $side): bool
    {
        $bit = $side === null ? 1 << 22 : 1 << (23 + ['north' => 0, 'east' => 1, 'south' => 2, 'west' => 3][$side]);
        return ($this->flags[$state] & $bit) !== 0;
    }

    /** BlockState.isFaceSturdy with center support. */
    public function sturdyCenter(int $state, int $direction): bool
    {
        return ($this->flags[$state] & (1024 << $direction)) !== 0;
    }

    public function is(int $state, string $tagOrName): bool
    {
        if (str_starts_with($tagOrName, '#')) {
            return isset($this->tags[preg_replace('/^#(minecraft:)?/', '', $tagOrName)][$this->name($state)]);
        }
        return $this->name($state) === preg_replace('/^minecraft:/', '', $tagOrName);
    }

    public function inTag(int $state, string $tag): bool
    {
        return isset($this->tags[$tag][$this->name($state)]);
    }

    /** @return list<string> */
    public function tagMembers(string $tag): array
    {
        return array_keys($this->tags[preg_replace('/^#?(minecraft:)?/', '', $tag)] ?? []);
    }
}
