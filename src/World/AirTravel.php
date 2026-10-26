<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World;

use PhpMinecraftJavaServer\Protocol\Catalog;
use PhpMinecraftJavaServer\World\Generation\Float32;

/** Ordinary air/ground integration; navigation and network delivery are separate. */
final class AirTravel
{
    private readonly Collision $collision;

    public function __construct(
        private readonly Catalog $catalog,
        private readonly EntityPhysics $physics,
        private readonly array $constants
    ) {
        $this->collision = new Collision($catalog);
    }

    public function step(World $world, array &$mob, array $definition, array $input, float $speed): void
    {
        $attributes = $definition['attributes'];
        $velocity = $mob['velocity'];
        foreach ($velocity as &$component) {
            if (abs($component) < 0.003) {
                $component = 0.0;
            }
        }
        unset($component);

        $floor = $world->get((int) floor($mob['x']), (int) floor($mob['y'] - Float32::round(0.500001)), (int) floor($mob['z']));
        $floorName = $this->catalog->blockName($floor);
        $blockFriction = $this->constants['block_friction'][$floorName] ?? $this->constants['default_block_friction'];
        $friction = ($mob['ground'] ?? false)
            ? EntityPhysics::modifiedFriction($blockFriction, $attributes['friction_modifier'] ?? 1.0)
            : 1.0;
        $acceleration = EntityPhysics::movementAcceleration($speed, $friction, $mob['ground'] ?? false);
        $relative = $this->physics->inputVector($input, $acceleration, $mob['yaw']);
        for ($axis = 0; $axis < 3; ++$axis) {
            $velocity[$axis] += $relative[$axis];
        }

        $bounds = Collision::bounds($mob['x'], $mob['y'], $mob['z'], $definition['width'], $definition['height']);
        $motion = $this->collision->moveWithStep($world, $bounds, $velocity, $attributes['step_height'] ?? 0.6, $mob['ground'] ?? false);
        foreach (['x', 'y', 'z'] as $axis => $field) {
            $mob[$field] += $motion[$axis];
        }
        $mob['ground'] = $velocity[1] < 0.0 && $motion[1] !== $velocity[1];

        if ($motion[0] !== $velocity[0]) {
            $velocity[0] = 0.0;
        }
        if ($motion[2] !== $velocity[2]) {
            $velocity[2] = 0.0;
        }
        $gravity = ($mob['noGravity'] ?? false) ? 0.0 : ($attributes['gravity'] ?? 0.08);
        $verticalDrag = EntityPhysics::modifiedFriction(0.98, $attributes['air_drag_modifier'] ?? 1.0);
        $landedState = $world->get((int) floor($mob['x']), (int) floor($mob['y'] - Float32::round(0.2)), (int) floor($mob['z']));
        $landedName = $this->catalog->blockName($landedState);
        if ($motion[1] !== $velocity[1]) {
            $restitution = $mob['ground'] && -$velocity[1] > $gravity && !($mob['suppressBounce'] ?? false)
                ? ($this->constants['block_bounce_restitution'][$landedName] ?? 0.0)
                : 0.0;
            if ($restitution > 0.0) {
                $fraction = $motion[1] / $velocity[1];
                $partialGravity = $fraction * $gravity;
                $partialDrag = 1.0 + $fraction * ($verticalDrag - 1.0);
                $velocity[1] = ($partialGravity - $velocity[1]) * $partialDrag * $restitution;
                $mob['syncPosition'] = true;
            } else {
                $velocity[1] = 0.0;
            }
        }

        $airDrag = EntityPhysics::modifiedFriction(0.91, $attributes['air_drag_modifier'] ?? 1.0);
        $horizontalDrag = Float32::round($friction * $airDrag);
        $mob['velocity'] = [
            $velocity[0] * $horizontalDrag,
            ($velocity[1] - $gravity) * $verticalDrag,
            $velocity[2] * $horizontalDrag,
        ];
        // stepOn runs after travel has applied gravity and drag.
        if ($mob['ground'] && $landedName === 'slime_block'
            && abs($mob['velocity'][1]) < 0.1 && !($mob['steppingCarefully'] ?? false)) {
            $factor = 0.4 + abs($mob['velocity'][1]) * 0.2;
            $mob['velocity'][0] *= $factor;
            $mob['velocity'][2] *= $factor;
        }
    }
}
