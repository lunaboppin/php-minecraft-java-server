<?php
declare(strict_types=1);
namespace PhpMinecraftJavaServer\Server;
use PhpMinecraftJavaServer\Protocol\{Framing, Version};

final class Client
{
    public ?array $editingSign=null;
    public ?array $information=null;
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
    public int $fireTicks = 0;
    public bool $dead = false;
    public bool $flying = false;
    public bool $sneaking = false;
    public int $lastAttackTick = -20;
    public ?int $lastAttackItem = null;
    public bool $sprinting = false;
    /** Total experience points collected, with the level and float progress toward the next. */
    public int $experience = 0;
    public int $experienceLevel = 0;
    public float $experienceProgress = 0.0;
    public int $lastLevelUpTick = -1000;
    /** @var array<string, array{amplifier: int, duration: int, ambient: bool, particles: bool, icon: bool}> active status effects */
    public array $effects = [];
    /** @var array<string, float> attribute base values changed from the type's defaults */
    public array $attributeBases = [];
    /** @var array<string, array<string, array{0: float, 1: int, 2: bool}>> attribute modifiers by id, in insertion order: amount, operation, permanent */
    public array $attributeModifiers = [];
    /** @var array<string, true> AttributeMap instances created so far, in creation order: the ones pairing sends */
    public array $attributeInstances = self::PLAYER_ATTRIBUTE_INSTANCES;
    /** Created for every player: abilities set the speed and game modes modify the ranges. */
    public const PLAYER_ATTRIBUTE_INSTANCES = ['movement_speed' => true, 'block_interaction_range' => true, 'entity_interaction_range' => true];
    /** Absorption hearts' health. */
    public float $absorption = 0.0;
    public int $lastDamageTick = -20;
    /** The amount of the last hurt, which a hit during the invulnerability window must exceed. */
    public float $lastDamageAmount = 0;
    /** Kill credit: the player who last hurt this one, remembered for 100 ticks as in vanilla. */
    public ?Client $lastHurtByPlayer = null;
    public int $lastHurtByPlayerTick = -1000;
    public ?array $mining = null;
    /** ServerPlayerGameMode: the last block started, its start tick, the crack stage sent, an early stop's delayed destroy and the game mode's tick count. */
    public ?array $miningPosition = null;
    public int $miningStarted = 0;
    public int $miningSent = -1;
    public ?array $delayedDestroy = null;
    public int $miningTicks = 0;
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
    public bool $trackingDirty = true;
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
    public string $authenticationPhase='hello';
    public string $challengeNonce='';
    public bool $authenticated=false;
    public array $profileProperties=[];
    public ?\PhpMinecraftJavaServer\Authentication\Cfb8 $decryptor=null;
    public ?\PhpMinecraftJavaServer\Authentication\Cfb8 $encryptor=null;
    public bool $registriesSent = false;
    public bool $playerLoaded = false;
    public bool $knownPacksRequested = false;
    public int $entityId = 0;
    public float $x = 0.5;
    public float $y = 64;
    public float $z = 0.5;
    public float $yaw = 0;
    public float $pitch = 0;
    public bool $ground = true;
    /** The highest block change sequence to acknowledge in the next tick, or -1. */
    public int $ackUpTo = -1;
    public int $teleportCounter = 0;
    public ?int $teleport = null;
    public float $teleportAt = 0;
    public int $chunkX = 0;
    public int $chunkZ = 0;
    public array $loadedChunks = [];
    public array $queuedChunks = [];
    /** PlayerChunkSender: the client's reported rate, the batch window and the send quota. */
    public float $desiredChunksPerTick = 9.0;
    public int $maxUnacknowledgedBatches = 1;
    public int $unacknowledgedBatches = 0;
    public float $batchQuota = 0.0;
    public float $batchAt = 0;
    /** Whether the attributes changed during creation have been sent, on the first tick after joining. */
    public bool $attributesSent = false;
    /** A teleport whose trackers are updated in the player's next tick. */
    public bool $teleportBroadcastPending = false;
    public int $heldSlot = 0;
    public array $inventory = [];
    public array $lastEquipment = [];
    /** @var array<int, ?array> the equipment stacks whose attribute modifiers are applied */
    public array $equipmentModifierStacks = [];
    public ?string $lastPlayerMetadata = null;
    public float $chatAt = 0;
    public int $packetsThisSecond = 0;
    public float $packetWindow;
    public function __construct(public readonly mixed $socket, public readonly string $peer) {
        $this->connectedAt = $this->lastRead = $this->lastKeepAlive = $this->packetWindow = hrtime(true)/1e9;
    }
    public function encrypt(string $secret):void {
        if($this->decryptor!==null){throw new \LogicException('Encryption already enabled');}
        $this->decryptor=new \PhpMinecraftJavaServer\Authentication\Cfb8($secret,true);
        $this->encryptor=new \PhpMinecraftJavaServer\Authentication\Cfb8($secret);
        $this->input=$this->decryptor->transform($this->input);
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
        $this->output .= $this->encryptor?->transform($frame)??$frame;
    }
}
