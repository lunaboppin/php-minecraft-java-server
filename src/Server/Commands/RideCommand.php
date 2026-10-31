<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait RideCommand
{
    /** RideCommand: mount a target on a vehicle, or dismount it. */
    private function executeRide(Client $sender, array $arguments): void
    {
        $target = $this->selectEntities($arguments[0], $sender, single: true)[0];
        $vehicle = $this->vehicleOf($target);
        if ($arguments[1] === 'dismount') {
            if ($vehicle === null) {
                throw new CommandFailure(['translate' => 'commands.ride.not_riding', 'with' => [$this->entityName($target)]]);
            }
            $this->stopRiding($target);
            $this->sendSuccess($sender, ['translate' => 'commands.ride.dismount.success', 'with' => [$this->entityName($target), $this->entityName($this->mobReference($vehicle))]], true);
            return;
        }
        $mount = $this->selectEntities($arguments[2], $sender, single: true)[0];
        if ($vehicle !== null) {
            throw new CommandFailure(['translate' => 'commands.ride.already_riding', 'with' => [$this->entityName($target), $this->entityName($this->mobReference($vehicle))]]);
        }
        if (isset($mount['client'])) {
            throw new CommandFailure(['translate' => 'commands.ride.mount.failure.cant_ride_players']);
        }
        if (isset($target['mob'], $mount['mob']) && in_array($mount['mob'], $this->selfAndPassengers($target['mob']), true)) {
            throw new CommandFailure(['translate' => 'commands.ride.mount.failure.loop']);
        }
        if (isset($target['client']) && $target['client']->dimension !== $this->world->dimension) {
            throw new CommandFailure(['translate' => 'commands.ride.mount.failure.wrong_dimension']);
        }
        // Only players and mobs ride here, and only mobs carry riders.
        if (!isset($mount['mob']) || !(isset($target['client']) || isset($target['mob'])) || !$this->startRiding($target, $mount['mob'])) {
            throw new CommandFailure(['translate' => 'commands.ride.mount.failure.generic', 'with' => [$this->entityName($target), $this->entityName($mount)]]);
        }
        $this->sendSuccess($sender, ['translate' => 'commands.ride.mount.success', 'with' => [$this->entityName($target), $this->entityName($mount)]], true);
    }
}
