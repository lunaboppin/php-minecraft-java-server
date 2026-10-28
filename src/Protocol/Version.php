<?php
declare(strict_types=1);
namespace PhpMinecraftJavaServer\Protocol;

final class Version
{
    public const NAME = '26.3';
    public const PROTOCOL = 777;
    public const DATA_VERSION = 5023;
    private array $packets;
    private array $dynamic;
    private array $reverse = [];
    public readonly array $blocks;
    public readonly Catalog $catalog;
    /** The pinned server's version metadata (name, protocol, data version, build time, pack formats). */
    public readonly array $info;
    public function __construct(public readonly string $directory = __DIR__ . '/../../reference/26.3') {
        $this->packets = $this->json('packets.json');
        $this->dynamic = $this->json('dynamic-registries.json');
        $this->blocks = $this->json('blocks.json');
        $this->info = $this->json('version.json');
        $this->catalog = new Catalog($directory);
        foreach ($this->packets as $state => $directions) {
            foreach ($directions['serverbound'] ?? [] as $name => $entry) {
                $this->reverse[$state][$entry['protocol_id']] = substr($name, 10);
            }
        }
    }
    private function json(string $file): array {
        $bytes = @file_get_contents($this->directory . '/' . $file);
        if ($bytes === false) { throw new \RuntimeException('Missing reference data: ' . $file); }
        return json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
    }
    public function id(string $state, string $name, string $direction = 'clientbound'): int {
        return $this->packets[$state][$direction]['minecraft:' . $name]['protocol_id'] ?? throw new \LogicException('Unknown packet ' . $state . ':' . $name);
    }
    public function name(string $state, int $id): string {
        return $this->reverse[$state][$id] ?? throw new ProtocolException('Unknown packet ID in ' . $state);
    }
    public function registryId(string $registry, string $name): int {
        $id = array_search('minecraft:' . $name, $this->dynamic['minecraft:' . $registry] ?? [], true);
        if ($id === false) { throw new \LogicException('Unknown registry entry'); }
        return $id;
    }
    public function tags(): string {
        $bytes = @file_get_contents($this->directory . '/tags.bin');
        if ($bytes === false) { throw new \RuntimeException('Missing registry tags'); }
        return $bytes;
    }
    public function registryCount(string $name): int { return count($this->dynamic['minecraft:'.$name]??[]); }
    public function registryPackets(): iterable {
        foreach ($this->dynamic as $name => $_) {
            $path = str_replace('/', '_', substr($name, 10));
            $bytes = file_get_contents($this->directory . '/registries/' . $path . '.bin');
            if ($bytes === false) { throw new \RuntimeException('Missing registry ' . $name); }
            yield $bytes;
        }
    }
}
