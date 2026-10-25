<?php
declare(strict_types=1);
namespace Jva\Server;
use Jva\Protocol\{Framing, Version};

final class Client
{
    public string $dimension='overworld';
    public int $portalTicks=0;
    public int $portalCooldown=0;
    public int $gameMode = 1;
    public float $health = 20;
    public int $food = 20;
    public float $saturation = 5;
    public float $exhaustion = 0;
    public float $fallDistance = 0;
    public int $air = 300;
    public ?int $lastEntityFlags = null;
    public int $fireTicks = 0;
    public bool $dead = false;
    public bool $flying = false;
    public bool $sneaking = false;
    public int $lastAttackTick = -20;
    public bool $sprinting = false;
    public int $experience = 0;
    public int $lastDamageTick = -20;
    public ?array $mining = null;
    public ?array $eating = null;
    public ?array $cursor = null;
    public array $dragSlots = [];
    public int $dragType = -1;
    public int $inventoryRevision = 0;
    public int $windowId = 0;
    public string $windowType = 'player';
    public ?array $windowPosition = null;
    public array $craftGrid = [];
    public array $entities = [];
    public ?array $personalSpawn = null;
    public string $state = 'handshake';
    public bool $bufferedWork = false;
    public string $input = '';
    public string $output = '';
    public int $compression = -1;
    public bool $closing = false;
    public float $closingAt = 0;
    public float $connectedAt;
    public float $lastRead;
    public float $lastKeepAlive;
    public ?int $keepAlive = null;
    public string $name = '';
    public string $uuid = '';
    public bool $loginStarted = false;
    public bool $registriesSent = false;
    public bool $knownPacksRequested = false;
    public int $entityId = 0;
    public float $x = 0.5;
    public float $y = 64;
    public float $z = 0.5;
    public float $yaw = 0;
    public float $pitch = 0;
    public bool $ground = true;
    public int $teleportCounter = 0;
    public ?int $teleport = null;
    public float $teleportAt = 0;
    public int $chunkX = 0;
    public int $chunkZ = 0;
    public array $loadedChunks = [];
    public array $queuedChunks = [];
    public bool $awaitingBatch = false;
    public float $batchAt = 0;
    public int $heldSlot = 0;
    public array $inventory = [];
    public float $chatAt = 0;
    public int $packetsThisSecond = 0;
    public float $packetWindow;
    public function __construct(public readonly mixed $socket, public readonly string $peer) {
        $this->connectedAt = $this->lastRead = $this->lastKeepAlive = $this->packetWindow = hrtime(true)/1e9;
    }
    public function send(Version $version, string $name, string $payload = ''): void {
        if ($this->closing) { return; }
        $frame = Framing::encode($version->id($this->state,$name),$payload,$this->compression);
        if (strlen($this->output) + strlen($frame) > 8*1024*1024) {
            $this->output = '';
            $this->closing = true;
            $this->closingAt = hrtime(true)/1e9;
            return;
        }
        $this->output .= $frame;
    }
}
