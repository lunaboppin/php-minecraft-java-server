<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World;

/** Native section-ordered AABB lookup with insertion order inside each section. */
final class EntitySectionIndex
{
    private array $sections = [];

    private array $entities = [];

    private array $visibility = [];

    public function upsert(int $id, array $position, array $bounds): void
    {
        $coordinates = array_map(static fn (float $coordinate): int => ((int) floor($coordinate)) >> 4, $position);
        $key = self::key(...$coordinates);
        if (isset($this->entities[$id]) && $this->entities[$id]['section'] !== $key) {
            $this->remove($id);
        }
        if (!isset($this->sections[$key])) {
            $this->sections[$key] = ['coordinates' => $coordinates, 'members' => []];
            uksort($this->sections, static fn (int $first, int $second): int => $first <=> $second);
        }
        $this->sections[$key]['members'][$id] = true;
        $this->entities[$id] = ['section' => $key, 'bounds' => $bounds];
    }

    public function remove(int $id): void
    {
        if (!isset($this->entities[$id])) {
            return;
        }
        $key = $this->entities[$id]['section'];
        unset($this->sections[$key]['members'][$id], $this->entities[$id]);
        if ($this->sections[$key]['members'] === []) {
            unset($this->sections[$key]);
        }
    }

    public function setAccessible(array $coordinates, bool $accessible): void
    {
        $this->visibility[self::key(...$coordinates)] = $accessible;
    }

    /** @return list<int> Ordered entity IDs; caller and push predicates apply later. */
    public function query(array $bounds): array
    {
        $minimum = [
            ((int) floor($bounds[0] - 2.0)) >> 4,
            ((int) floor($bounds[1] - 4.0)) >> 4,
            ((int) floor($bounds[2] - 2.0)) >> 4,
        ];
        $maximum = [
            ((int) floor($bounds[3] + 2.0)) >> 4,
            ((int) floor($bounds[4] + 0.0)) >> 4,
            ((int) floor($bounds[5] + 2.0)) >> 4,
        ];
        $result = [];
        foreach ($this->sections as $key => $section) {
            if (!($this->visibility[$key] ?? true)) {
                continue;
            }
            foreach ($section['coordinates'] as $axis => $coordinate) {
                if ($coordinate < $minimum[$axis] || $coordinate > $maximum[$axis]) {
                    continue 2;
                }
            }
            foreach ($section['members'] as $id => $_) {
                if (EntityPushing::intersects($bounds, $this->entities[$id]['bounds'])) {
                    $result[] = $id;
                }
            }
        }
        return $result;
    }

    private static function key(int $x, int $y, int $z): int
    {
        return (($x & 0x3fffff) << 42) | (($z & 0x3fffff) << 20) | ($y & 0xfffff);
    }
}
