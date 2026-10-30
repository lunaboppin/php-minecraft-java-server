<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

/**
 * CommandSourceStack for /execute: the executing entity, position, rotation, anchor and
 * dimension a command runs with, while feedback still goes to the player who ran it.
 */
trait CommandSources
{
    /** @var array{output: Client, entity: ?array, x: float, y: float, z: float, yaw: float, pitch: float, dimension: string, anchor: string, forked: bool}|null */
    private ?array $commandContext = null;

    /** @return array{float, float, float} */
    private function sourcePosition(Client $sender): array
    {
        return $this->commandContext === null ? [$sender->x, $sender->y, $sender->z] : [$this->commandContext['x'], $this->commandContext['y'], $this->commandContext['z']];
    }

    /** @return array{float, float} yaw and pitch */
    private function sourceRotation(Client $sender): array
    {
        return $this->commandContext === null ? [$sender->yaw, $sender->pitch] : [$this->commandContext['yaw'], $this->commandContext['pitch']];
    }

    private function sourceDimension(Client $sender): string
    {
        return $this->commandContext['dimension'] ?? $sender->dimension;
    }

    /** EntityAnchorArgument.Anchor.apply: the position, raised to the executing entity's eyes when anchored there. */
    private function sourceAnchorPosition(Client $sender): array
    {
        $position = $this->sourcePosition($sender);
        if (($this->commandContext['anchor'] ?? 'feet') === 'eyes' && ($entity = $this->commandContext['entity']) !== null) {
            $position[1] += $this->entityEyeHeight($entity);
        }
        return $position;
    }

    /** Entity.getEyeHeight for the entities a command can select. */
    private function entityEyeHeight(array $entity): float
    {
        if (isset($entity['client'])) {
            return $entity['client']->sneaking ? 1.27 : 1.62;
        }
        // EntityDimensions.eyeHeight defaults to 85% of the height.
        return \PhpMinecraftJavaServer\World\Generation\Float32::round($entity['height'] * 0.85);
    }

    /** CommandSourceStack.getDisplayName: the executing entity's name. */
    private function sourceDisplayName(Client $sender): array
    {
        $entity = $this->commandContext['entity'] ?? null;
        if ($entity === null) {
            return $this->displayName($sender);
        }
        return isset($entity['client']) ? $this->displayName($entity['client']) : $this->entityName($entity);
    }

    /** Runs the action with the context, restoring the previous one afterwards. */
    private function withCommandContext(array $context, callable $action): mixed
    {
        $previous = $this->commandContext;
        $this->commandContext = $context;
        try {
            return $this->withDimension($context['dimension'], $action);
        } finally {
            $this->commandContext = $previous;
        }
    }
}
