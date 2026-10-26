<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Tracking;

use PhpMinecraftJavaServer\Protocol\PositionPath;

/** Collects forced substeps between ordinary living-entity tracking updates. */
final class SteppedPositionTracker
{
    private array $steps = [];

    private int $ticksSinceStep = -1;

    public function update(array $position, array $predictedMovement, bool $forceStep): void
    {
        $length = $predictedMovement[0] * $predictedMovement[0] + $predictedMovement[1] * $predictedMovement[1] + $predictedMovement[2] * $predictedMovement[2];
        if ($length > 9.999999747378752E-6) {
            foreach ($this->steps as &$step) {
                for ($axis = 0; $axis < 3; ++$axis) {
                    $step['position'][$axis] += $predictedMovement[$axis];
                }
            }
            unset($step);
        }
        ++$this->ticksSinceStep;
        if ($forceStep) {
            $this->add($position);
        }
    }

    private function add(array $position): void
    {
        if ($this->ticksSinceStep > 0) {
            $this->steps[] = ['position' => $position, 'ticks' => $this->ticksSinceStep];
            $this->ticksSinceStep = 0;
        }
    }

    public function path(array $position): PositionPath
    {
        $this->add($position);
        return $this->steps === [] ? PositionPath::linear($position) : PositionPath::stepped($this->steps);
    }

    public function clear(): void
    {
        $this->steps = [];
        $this->ticksSinceStep = 0;
    }
}
