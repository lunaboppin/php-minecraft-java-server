<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\Protocol\Binary;
use PhpMinecraftJavaServer\Protocol\Nbt;
use PhpMinecraftJavaServer\World\Generation\Float32;

/**
 * CustomBossEvents: command boss bars, saved with the world, shown to the players they list
 * while visible, as ServerBossEvent sends ClientboundBossEventPacket.
 */
trait BossBars
{
    /** @var array<string, array>|null by identifier, in insertion order */
    private ?array $bossBars = null;

    // BossEvent.BossBarColor: each colour's chat formatting for the bar's name.
    private const BOSS_BAR_COLORS = ['pink' => 'red', 'blue' => 'blue', 'red' => 'dark_red', 'green' => 'green', 'yellow' => 'yellow', 'purple' => 'dark_blue', 'white' => 'white'];
    private const BOSS_BAR_STYLES = ['progress', 'notched_6', 'notched_10', 'notched_12', 'notched_20'];

    private function &bossBarList(): array
    {
        if ($this->bossBars === null) {
            $this->bossBars = [];
            foreach ($this->worlds['overworld']->setting('bossBars') ?? [] as $bar) {
                // CustomBossEvents.load gives each bar a new random UUID.
                $this->bossBars[$bar['id']] = ['uuid' => random_bytes(16)] + $bar;
            }
        }
        return $this->bossBars;
    }

    private function saveBossBars(): void
    {
        $this->worlds['overworld']->setting('bossBars', array_values(array_map(static fn (array $bar): array => array_diff_key($bar, ['uuid' => true]), $this->bossBarList())));
    }

    /** HashMap<Identifier, …>.values(): by the identifiers' hash buckets. */
    private function orderedBossBars(): array
    {
        $bars = $this->bossBarList();
        $hash = static function (string $text): int {
            $value = 0;
            foreach (unpack('n*', mb_convert_encoding($text, 'UTF-16BE', 'UTF-8')) ?: [] as $unit) {
                $value = (31 * $value + $unit) & 0xffffffff;
            }
            return $value;
        };
        $capacity = 16;
        while (count($bars) > $capacity * 3 / 4) {
            $capacity *= 2;
        }
        $bucket = static function (string $id) use ($hash, $capacity): int {
            [$namespace, $path] = explode(':', $id, 2);
            $value = (31 * $hash($namespace) + $hash($path)) & 0xffffffff;
            return ($value ^ ($value >> 16)) & ($capacity - 1);
        };
        $ids = array_keys($bars);
        usort($ids, static fn (string $a, string $b): int => $bucket($a) <=> $bucket($b));
        return array_map(static fn (string $id): array => $bars[$id], $ids);
    }

    /** CustomBossEvent.getDisplayName: the bracketed name in the bar's colour, hovering and inserting its ID. */
    private function bossBarName(array $bar): array
    {
        return ['translate' => 'chat.square_brackets', 'with' => [$bar['name']], 'color' => self::BOSS_BAR_COLORS[$bar['color']],
            'hover_event' => ['action' => 'show_text', 'value' => ['text' => $bar['id']]], 'insertion' => $bar['id']];
    }

    /** Online players the bar lists, which ServerBossEvent shows it to. */
    private function bossBarViewers(array $bar): array
    {
        return array_values(array_filter($this->players(), static fn (Client $player): bool => isset($bar['players'][bin2hex($player->uuid)])));
    }

    private function sendBossEvent(array $bar, int $operation, string $payload, ?array $to = null): void
    {
        foreach ($to ?? $this->bossBarViewers($bar) as $player) {
            $player->send($this->version, 'boss_event', $bar['uuid'] . Binary::writeVarInt($operation) . $payload);
        }
    }

    private function bossBarAdd(array $bar): string
    {
        return Nbt::component($bar['name']) . pack('G', $bar['progress']) . Binary::writeVarInt(array_search($bar['color'], array_keys(self::BOSS_BAR_COLORS), true))
            . Binary::writeVarInt(array_search($bar['style'], self::BOSS_BAR_STYLES, true)) . "\0";
    }

    /** CustomBossEvents.onPlayerConnect: a returning player listed by a visible bar sees it again. */
    private function bossBarsConnect(Client $c): void
    {
        foreach ($this->bossBarList() as $bar) {
            if ($bar['visible'] && isset($bar['players'][bin2hex($c->uuid)])) {
                $this->sendBossEvent($bar, 0, $this->bossBarAdd($bar), [$c]);
            }
        }
    }

    /** CustomBossEvent.setValue and setMax: the progress is the clamped float quotient. */
    private function updateBossBarProgress(array &$bar): void
    {
        $progress = max(0.0, min(1.0, Float32::round(Float32::round((float) $bar['value']) / Float32::round((float) $bar['max']))));
        if ($progress !== $bar['progress']) {
            $bar['progress'] = $progress;
            if ($bar['visible']) {
                $this->sendBossEvent($bar, 2, pack('G', $progress));
            }
        }
    }
}
