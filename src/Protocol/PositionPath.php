<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Protocol;

/** Absolute linear or stepped positions used by protocol-777 movement packets. */
final readonly class PositionPath
{
    private function __construct(public array $end, public ?array $steps)
    {
    }

    public static function linear(array $position): self
    {
        return new self($position, null);
    }

    /** @param list<array{position: array, ticks: int}> $steps */
    public static function stepped(array $steps): self
    {
        if ($steps === []) {
            throw new \InvalidArgumentException('A stepped position path needs an end position');
        }
        return new self($steps[array_key_last($steps)]['position'], $steps);
    }

    public function encode(): string
    {
        if ($this->steps === null) {
            return "\0" . pack('EEE', ...$this->end);
        }
        $bytes = "\1" . Binary::writeVarInt(count($this->steps));
        foreach ($this->steps as $step) {
            $bytes .= pack('EEE', ...$step['position']) . Binary::writeVarInt($step['ticks']);
        }
        return $bytes;
    }
}
