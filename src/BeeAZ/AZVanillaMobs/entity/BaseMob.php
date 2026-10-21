<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\entity;

use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\math\Vector3;
use pocketmine\item\Item;
use pocketmine\network\mcpe\protocol\MobEquipmentPacket;
use pocketmine\network\mcpe\protocol\types\inventory\ContainerIds;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStackWrapper;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\player\Player;
use pocketmine\world\World;

/**
 * Shared vanilla-like mob controller.
 *
 * This is intentionally lightweight: target selection runs at a reduced rate,
 * while movement and knockback are processed every tick. It gives PocketMine
 * mobs last-seen memory, line-of-sight, panic destinations and cached, bounded
 * ground paths. Physics still run each tick; path searches share a global budget.
 */
abstract class BaseMob extends Living
{
    private ?\BeeAZ\AZVanillaMobs\ai\GroundNavigator $navigator = null;
    private ?Vector3 $lastSeenTarget = null;
    private int $sightTick = -1;
    private int $sightEntity = -1;
    private bool $sightResult = false;
    protected int $tickOffset = 0;
    protected ?Vector3 $targetPosition = null;
    protected ?Living $targetEntity = null;

    /** The entity which most recently hurt this mob. */
    protected ?Living $lastAttacker = null;
    protected int $revengeTicks = 0;

    /** Prevents AI movement from immediately deleting vanilla knockback. */
    protected int $hurtControlTicks = 0;

    /** Target memory after temporary loss of sight. */
    protected int $targetMemoryTicks = 0;

    /** Temporary movement modifier used by panic/charge/chase behaviours. */
    protected float $behaviorSpeedMultiplier = 1.0;

    private bool $hasPlayerNearby = true;
    private int $stuckTicks = 0;
    private ?Vector3 $lastMovementSample = null;
    private int $steeringDirection = 1;
    private int $jumpCooldownTicks = 0;
    private int $movementSampleCounter = 0;
    private float $separationX = 0.0;
    private float $separationZ = 0.0;

    /** Only mobs created by SpawnerTask receive this flag. */
    protected bool $naturalSpawned = false;
    private int $naturalAgeTicks = 0;

    /** Only manually/automatically spawned structure mobs receive these fields. */
    protected bool $structureSpawned = false;
    protected ?Vector3 $structureHome = null;
    protected float $structureRadius = 32.0;
    protected string $structureId = "";
    protected string $structureType = "";

    public function __construct(Location $location, ?CompoundTag $nbt = null)
    {
        $this->tickOffset = mt_rand(0, 20);
        parent::__construct($location, $nbt);
        // Species initEntity hooks must not fully heal a saved injured mob.
        $health=$nbt?->getTag("Health");
        if($health instanceof \pocketmine\nbt\tag\FloatTag || $health instanceof \pocketmine\nbt\tag\ShortTag){
            $this->setHealth(max(0,min((float)$health->getValue(),$this->getMaxHealth())));
        }
        if ($this->isFlying()) {
            $this->gravity = 0.0;
        }
    }

    protected function initEntity(CompoundTag $nbt): void
    {
        $this->setMaxHealth($this->getDefaultMaxHealth());
        parent::initEntity($nbt);
        $armor=$nbt->getListTag("AmyxArmor",CompoundTag::class);
        if($armor!==null){
            foreach($armor as $entry){
                $slot=$entry->getByte("Slot",-1);
                if($slot>=0 && $slot<$this->getArmorInventory()->getSize()){
                    $this->getArmorInventory()->setItem($slot,Item::nbtDeserialize($entry));
                }
            }
        }
        $this->naturalSpawned = $nbt->getByte("NaturalSpawned", 0) === 1;
        $this->naturalAgeTicks = max(0, $nbt->getInt("NaturalAgeTicks", 0));
        $this->structureSpawned = $nbt->getByte("StructureSpawned", 0) === 1;
        if ($this->structureSpawned) {
            $this->structureHome = new Vector3(
                $nbt->getFloat("StructureHomeX", (float) $this->location->x),
                $nbt->getFloat("StructureHomeY", (float) $this->location->y),
                $nbt->getFloat("StructureHomeZ", (float) $this->location->z)
            );
            $this->structureRadius = max(8.0, min(128.0, $nbt->getFloat("StructureRadius", 32.0)));
            $this->structureId = $nbt->getString("StructureId", "");
            $this->structureType = $nbt->getString("StructureType", "");
        }

        if ($nbt->getTag("MaxHealth") !== null) {
            $this->setMaxHealth($nbt->getInt("MaxHealth"));
        }

        $healthTag = $nbt->getTag("Health");
        if ($healthTag !== null) {
            $this->setHealth(min((float) $healthTag->getValue(), $this->getMaxHealth()));
        } else {
            $this->setHealth($this->getMaxHealth());
        }

        $fenceLeashTag = $nbt->getCompoundTag("FenceLeash");
        if ($fenceLeashTag !== null) {
            $x = $fenceLeashTag->getInt("X");
            $y = $fenceLeashTag->getInt("Y");
            $z = $fenceLeashTag->getInt("Z");
            $worldName = $fenceLeashTag->getString("World");
            \BeeAZ\AZVanillaMobs\listener\LeashListener::registerFenceLeash($this, new Vector3($x, $y, $z), $worldName);
        }
    }

    public function saveNBT(): CompoundTag
    {
        $nbt = parent::saveNBT();
        $armor=new \pocketmine\nbt\tag\ListTag([],\pocketmine\nbt\NBT::TAG_Compound);
        foreach($this->getArmorInventory()->getContents() as $slot=>$item){ $armor->push($item->nbtSerialize($slot)); }
        $nbt->setTag("AmyxArmor",$armor);
        $nbt->setInt("MaxHealth", $this->getMaxHealth());
        if ($this->naturalSpawned) {
            $nbt->setByte("NaturalSpawned", 1);
            $nbt->setInt("NaturalAgeTicks", $this->naturalAgeTicks);
        }
        if ($this->structureSpawned && $this->structureHome !== null) {
            $nbt->setByte("StructureSpawned", 1);
            $nbt->setFloat("StructureHomeX", (float) $this->structureHome->x);
            $nbt->setFloat("StructureHomeY", (float) $this->structureHome->y);
            $nbt->setFloat("StructureHomeZ", (float) $this->structureHome->z);
            $nbt->setFloat("StructureRadius", $this->structureRadius);
            if ($this->structureId !== "") {
                $nbt->setString("StructureId", $this->structureId);
            }
            if ($this->structureType !== "") {
                $nbt->setString("StructureType", $this->structureType);
            }
        }

        if (isset(\BeeAZ\AZVanillaMobs\listener\LeashListener::$fenceLeashedEntities[$this->getId()])) {
            $fenceData = \BeeAZ\AZVanillaMobs\listener\LeashListener::$fenceLeashedEntities[$this->getId()];
            $leashNBT = CompoundTag::create()
                ->setInt("X", (int) $fenceData['pos']->x)
                ->setInt("Y", (int) $fenceData['pos']->y)
                ->setInt("Z", (int) $fenceData['pos']->z)
                ->setString("World", $fenceData['world']);
            $nbt->setTag("FenceLeash", $leashNBT);
        }
        return $nbt;
    }

    public function isStructureSpawnedMob(): bool
    {
        return $this->structureSpawned;
    }

    public function getStructureId(): string
    {
        return $this->structureId;
    }

    public function getStructureType(): string
    {
        return $this->structureType;
    }

    public function getStructureHome(): ?Vector3
    {
        return $this->structureHome !== null ? clone $this->structureHome : null;
    }

    public function belongsToStructure(string $id, string $type, Vector3 $home): bool
    {
        if (!$this->structureSpawned) {
            return false;
        }
        if ($this->structureId !== "") {
            return $this->structureId === $id;
        }
        if ($this->structureType !== "" && $this->structureType !== $type) {
            return false;
        }
        return $this->structureHome !== null && $this->structureHome->distanceSquared($home) <= 64.0;
    }

    private function canLeaveStructureForTarget(): bool
    {
        if(!($this->targetEntity instanceof Player)){
            return false;
        }
        if(!$this->structureSpawned || $this->structureHome === null){
            return true;
        }
        // A structure mob may cross its patrol border only during a real player
        // chase, but not be dragged indefinitely across the world.
        $pursuitRadius = $this->structureRadius + 24.0;
        return $this->targetEntity->getLocation()->distanceSquared($this->structureHome) <= $pursuitRadius * $pursuitRadius;
    }

    private function isAllowedStructureDestination(Vector3 $candidate): bool
    {
        return !$this->structureSpawned || $this->structureHome === null || $this->canLeaveStructureForTarget() ||
            $candidate->distanceSquared($this->structureHome) <= $this->structureRadius * $this->structureRadius;
    }

    public function getDefaultMaxHealth(): int
    {
        $name = $this->getShortEntityName();
        $healthMap = [
            'cow' => 10, 'mooshroom' => 10, 'pig' => 10, 'sheep' => 8, 'chicken' => 4,
            'wolf' => 20, 'cat' => 10, 'ocelot' => 10,
            'horse' => 20, 'donkey' => 20, 'mule' => 20,
            'llama' => 20, 'traderllama' => 20, 'fox' => 10,
            'panda' => 20, 'parrot' => 6, 'turtle' => 30, 'dolphin' => 10,
            'squid' => 10, 'glowsquid' => 10, 'bat' => 6,
            'villager' => 20, 'wanderingtrader' => 20,
            'irongolem' => 100, 'snowgolem' => 4, 'warden' => 500,
            'axolotl' => 14, 'goat' => 20, 'frog' => 10,
            'tadpole' => 6, 'camel' => 32, 'sniffer' => 14,
            'allay' => 20, 'bee' => 10, 'cod' => 3, 'salmon' => 3,
            'pufferfish' => 3, 'tropicalfish' => 3,
            'skeleton' => 20, 'zombie' => 20, 'zombievillager' => 20,
            'husk' => 20, 'drowned' => 20, 'stray' => 20,
            'creeper' => 20, 'spider' => 16, 'cavespider' => 12,
            'slime' => 16, 'silverfish' => 8, 'witch' => 26,
            'phantom' => 20, 'vindicator' => 24, 'evoker' => 24,
            'pillager' => 24, 'ravager' => 100, 'vex' => 14,
            'guardian' => 30, 'elderguardian' => 80,
            'zombifiedpiglin' => 20, 'piglin' => 16, 'piglinbrute' => 50,
            'hoglin' => 40, 'zoglin' => 40, 'ghast' => 10,
            'blaze' => 20, 'magmacube' => 16, 'witherskeleton' => 20,
            'strider' => 20, 'enderman' => 40, 'endermite' => 8,
            'shulker' => 30, 'enderdragon' => 200,
        ];
        return $healthMap[$name] ?? 20;
    }

    public function getAttackDamage(): float
    {
        $name = $this->getShortEntityName();
        $damageMap = [
            'zombie' => 3.0, 'zombievillager' => 3.0, 'husk' => 3.0,
            'drowned' => 3.0, 'skeleton' => 2.0, 'stray' => 2.0,
            'creeper' => 3.0, 'spider' => 2.0, 'cavespider' => 2.0,
            'silverfish' => 1.0, 'witch' => 2.0, 'phantom' => 6.0,
            'vindicator' => 8.0, 'evoker' => 6.0, 'pillager' => 4.0,
            'ravager' => 12.0, 'vex' => 3.0, 'guardian' => 6.0,
            'elderguardian' => 8.0, 'zombifiedpiglin' => 5.0,
            'piglin' => 5.0, 'piglinbrute' => 12.0, 'hoglin' => 7.0,
            'zoglin' => 7.0, 'ghast' => 6.0, 'blaze' => 6.0,
            'magmacube' => 4.0, 'witherskeleton' => 8.0,
            'strider' => 2.0, 'enderman' => 7.0, 'endermite' => 2.0,
            'shulker' => 4.0, 'enderdragon' => 10.0,
            'irongolem' => 15.0, 'wolf' => 4.0, 'warden' => 30.0,
        ];
        return $damageMap[$name] ?? 3.0;
    }

    public function getShortEntityName(): string
    {
        $parts = explode('\\', static::class);
        return strtolower((string) array_pop($parts));
    }

    public function isFlying(): bool { return false; }
    public function isAquatic(): bool { return false; }
    public function isUndead(): bool { return false; }
    public function isFireProof(): bool {
        return in_array($this->getShortEntityName(),['blaze','ghast','magmacube','strider','zombifiedpiglin','witherskeleton'],true);
    }
    public function breathesWater(): bool { return $this->isAquatic(); }
    public function breathesAir(): bool { return !$this->isAquatic(); }

    /** Bedrock mobs which actually ignite in direct daylight. */
    public function burnsInDaylight(): bool
    {
        return in_array($this->getShortEntityName(), [
            'zombie', 'zombievillager', 'skeleton', 'stray', 'phantom',
        ], true);
    }

    public function isInsideOfWater(): bool
    {
        $block = $this->getWorld()->getBlock($this->location);
        if ($block instanceof \pocketmine\block\Water) {
            return true;
        }
        $eyeBlock = $this->getWorld()->getBlock($this->location->add(0, $this->getEyeHeight(), 0));
        return $eyeBlock instanceof \pocketmine\block\Water;
    }

    public function canBreathe(): bool
    {
        // Breathing follows eye immersion, not wet feet in shallow water.
        $inWater = $this->isUnderwater();
        if ($inWater) {
            return $this->breathesWater();
        }
        return $this->breathesAir();
    }

    public function isSwimming(): bool
    {
        return $this->isAquatic() && $this->isInsideOfWater();
    }

    public function getInitialGravity(): float
    {
        return $this->isFlying() ? 0.0 : parent::getInitialGravity();
    }

    protected function getInitialSizeInfo(): EntitySizeInfo
    {
        return new EntitySizeInfo(1.8, 0.6);
    }

    public function getName(): string
    {
        $parts = explode('\\', static::class);
        return (string) array_pop($parts);
    }

    public function attack(EntityDamageEvent $source): void
    {
        parent::attack($source);
        if ($source->isCancelled()) {
            return;
        }

        // Keep the knockback impulse visible for a few client ticks.
        $this->hurtControlTicks = max($this->hurtControlTicks, 6);

        if ($source instanceof EntityDamageByEntityEvent) {
            $damager = $source->getDamager();
            if ($damager instanceof Living && $damager !== $this && $damager->getWorld() === $this->getWorld()) {
                $this->rememberAttacker($damager, 300);
            }
        }
    }

    public function rememberAttacker(Living $attacker, int $ticks = 300): void
    {
        if (!$this->canUseAsTarget($attacker)) {
            return;
        }
        $this->lastAttacker = $attacker;
        $this->revengeTicks = max($this->revengeTicks, $ticks);
    }

    public function getRevengeTarget(): ?Living
    {
        if ($this->revengeTicks <= 0 || !$this->canUseAsTarget($this->lastAttacker)) {
            $this->lastAttacker = null;
            $this->revengeTicks = 0;
            return null;
        }
        return $this->lastAttacker;
    }

    public function setCombatTarget(?Living $target, int $memoryTicks = 340): void
    {
        if ($target !== null && !$this->canUseAsTarget($target)) {
            $target = null;
        }
        $changed = $this->targetEntity !== $target;
        $this->targetEntity = $target;
        if($target === null){ $this->clearCombatTarget(); return; }
        // Retaliation may start without sight, but repeatedly selecting an unseen
        // existing target must never reset the memory timer.
        if($changed || $this->canSeeEntity($target)){
            $this->targetMemoryTicks = $memoryTicks;
            $this->lastSeenTarget = clone $target->getLocation();
        }
        $this->targetPosition = $this->lastSeenTarget !== null ? clone $this->lastSeenTarget : null;
    }

    public function getCombatTarget(): ?Living
    {
        return $this->canUseAsTarget($this->targetEntity) ? $this->targetEntity : null;
    }

    public function clearCombatTarget(): void
    {
        if($this->targetEntity !== null){ $this->targetPosition = null; }
        $this->targetEntity = null;
        $this->lastSeenTarget = null;
        $this->targetMemoryTicks = 0;
    }

    protected function canUseAsTarget(?Living $entity): bool
    {
        if ($entity === null || $entity === $this || !$entity->isAlive() || $entity->isClosed() || $entity->getWorld() !== $this->getWorld()) {
            return false;
        }
        if ($entity instanceof Player && ($entity->isCreative() || $entity->isSpectator())) {
            return false;
        }
        return true;
    }

    /**
     * Cheap block ray test. It is only called during AI selection, not every tick.
     */
    public function canSeeEntity(Living $entity): bool
    {
        if (!$this->canUseAsTarget($entity)) {
            return false;
        }

        $tick = \pocketmine\Server::getInstance()->getTick();
        if($this->sightEntity === $entity->getId() && $tick-$this->sightTick < 5){ return $this->sightResult; }
        $this->sightEntity=$entity->getId();$this->sightTick=$tick;$this->sightResult=false;
        $start = $this->location->add(0, $this->getEyeHeight(), 0);
        $end = $entity->getLocation()->add(0, $entity->getEyeHeight(), 0);
        $delta = $end->subtractVector($start);
        $distance = $delta->length();
        if ($distance <= 0.001) {
            return true;
        }

        $steps = min(80, max(1, (int) ceil($distance * 2.0)));
        $step = $delta->multiply(1.0 / $steps);
        $cursor = clone $start;
        for ($i = 1; $i < $steps; $i++) {
            $cursor = $cursor->addVector($step);
            if(!$this->getWorld()->isChunkLoaded($cursor->getFloorX()>>4,$cursor->getFloorZ()>>4)){ return false; }
            $block=$this->getWorld()->getBlock($cursor);
            foreach($block->getCollisionBoxes() as $bb){ if($bb->calculateIntercept($start,$end)!==null){ return false; } }
        }
        return $this->sightResult=true;
    }

    /** @return Living[] */
    protected function getNearbyLiving(float $horizontalRadius, float $verticalRadius = 8.0): array
    {
        $result = [];
        foreach ($this->getWorld()->getNearbyEntities($this->getBoundingBox()->expandedCopy($horizontalRadius, $verticalRadius, $horizontalRadius), $this) as $entity) {
            if ($entity instanceof Living && $this->canUseAsTarget($entity)) {
                $result[] = $entity;
            }
        }
        return $result;
    }

    protected function findNearestLiving(callable $filter, float $radius, bool $mustSee = true): ?Living
    {
        $nearest = null;
        $best = $radius * $radius;

        foreach ($this->getNearbyLiving($radius, min(16.0, $radius)) as $entity) {
            if (!$filter($entity)) {
                continue;
            }
            $distance = $this->location->distanceSquared($entity->getLocation());
            if ($distance >= $best) {
                continue;
            }
            if ($mustSee && !$this->canSeeEntity($entity)) {
                continue;
            }
            $nearest = $entity;
            $best = $distance;
        }
        return $nearest;
    }

    public function setBehaviorSpeedMultiplier(float $multiplier): void
    {
        $this->behaviorSpeedMultiplier = max(0.1, min(3.0, $multiplier));
    }

    public function getEffectiveMovementSpeed(): float
    {
        return $this->getMovementSpeed() * $this->behaviorSpeedMultiplier;
    }

    protected function isBehaviorActive(): bool
    {
        return $this->targetEntity !== null || $this->revengeTicks > 0;
    }

    public function isSafePosition(Vector3 $pos): bool
    {
        if ($this->isFlying() || $this->isSwimming()) {
            return !$this->getWorld()->getBlock($pos)->isSolid();
        }
        return $this->findWalkablePosition((int) floor($pos->x), (int) floor($pos->z), (int) floor($pos->y)) !== null;
    }

    protected function findWalkablePosition(int $x, int $z, int $preferredY): ?Vector3
    {
        $walker = new \BeeAZ\AZVanillaMobs\ai\WalkEvaluator($this->getWorld(),$this->size->getWidth(),$this->size->getHeight());
        $y = $walker->stand($x,$z,$preferredY);
        return $y !== null ? new Vector3($x+0.5,$y,$z+0.5) : null;
    }

    protected function chooseRandomDestination(float $radius = 8.0, float $verticalRadius = 3.0): ?Vector3
    {
        if ($this->isFlying() || $this->isSwimming()) {
            for ($i = 0; $i < 10; $i++) {
                $candidate = $this->location->add(
                    mt_rand((int) -$radius * 10, (int) $radius * 10) / 10,
                    mt_rand((int) -$verticalRadius * 10, (int) $verticalRadius * 10) / 10,
                    mt_rand((int) -$radius * 10, (int) $radius * 10) / 10
                );
                $block = $this->getWorld()->getBlock($candidate);
                if (!$this->isAllowedStructureDestination($candidate)) {
                    continue;
                }
                if ($this->isSwimming()) {
                    if ($block instanceof \pocketmine\block\Water) {
                        return $candidate;
                    }
                } elseif (!$block->isSolid()) {
                    return $candidate;
                }
            }
            return null;
        }

        for ($i = 0; $i < 12; $i++) {
            $x = (int) floor($this->location->x + mt_rand((int) -$radius * 10, (int) $radius * 10) / 10);
            $z = (int) floor($this->location->z + mt_rand((int) -$radius * 10, (int) $radius * 10) / 10);
            $candidate = $this->findWalkablePosition($x, $z, $this->location->getFloorY());
            if ($candidate !== null && $this->isAllowedStructureDestination($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    /** Selects a reachable point generally opposite the threat. */
    protected function chooseEscapeDestination(Living $threat, float $distance = 10.0): ?Vector3
    {
        $awayX = $this->location->x - $threat->getLocation()->x;
        $awayZ = $this->location->z - $threat->getLocation()->z;
        $length = sqrt($awayX * $awayX + $awayZ * $awayZ);
        if ($length < 0.001) {
            $angle = mt_rand(0, 628) / 100;
            $awayX = cos($angle);
            $awayZ = sin($angle);
            $length = 1.0;
        }
        $awayX /= $length;
        $awayZ /= $length;

        $angles = [0.0, 0.42, -0.42, 0.78, -0.78, 1.15, -1.15];
        shuffle($angles);
        array_unshift($angles, 0.0);
        foreach ($angles as $angle) {
            $cos = cos($angle);
            $sin = sin($angle);
            $dx = $awayX * $cos - $awayZ * $sin;
            $dz = $awayX * $sin + $awayZ * $cos;
            $candidateX = (int) floor($this->location->x + $dx * $distance);
            $candidateZ = (int) floor($this->location->z + $dz * $distance);

            if ($this->isFlying()) {
                $candidate = new Vector3($candidateX + 0.5, $this->location->y + mt_rand(-2, 3), $candidateZ + 0.5);
                if (!$this->getWorld()->getBlock($candidate)->isSolid()) {
                    return $candidate;
                }
            } else {
                $candidate = $this->findWalkablePosition($candidateX, $candidateZ, $this->location->getFloorY());
                if ($candidate !== null && $candidate->distanceSquared($threat->getLocation()) > $this->location->distanceSquared($threat->getLocation())) {
                    return $candidate;
                }
            }
        }
        return $this->chooseRandomDestination($distance, 3.0);
    }

    /** Sends a held item using the viewer's own protocol converter. */
    protected function sendHeldItem(Player $player, Item $item): void
    {
        try {
            $session = $player->getNetworkSession();
            $session->sendDataPacket(MobEquipmentPacket::create(
                $this->getId(),
                ItemStackWrapper::legacy($session->getTypeConverter()->coreItemStackToNet($item)),
                0,
                0,
                ContainerIds::INVENTORY
            ));
        } catch (\Throwable $e) {
            // Equipment is visual only; a protocol mismatch must never crash AI.
        }
    }

    /** Re-sends a changed held item to every current viewer. */
    protected function broadcastHeldItem(Item $item): void
    {
        foreach ($this->getViewers() as $viewer) {
            $this->sendHeldItem($viewer, $item);
        }
    }

    public function onUpdate(int $currentTick): bool
    {
        if ($this->revengeTicks > 0) {
            $this->revengeTicks--;
        }
        if ($this->hurtControlTicks > 0) {
            $this->hurtControlTicks--;
        }
        if ($this->targetMemoryTicks > 0) {
            $this->targetMemoryTicks--;
        }
        if ($this->jumpCooldownTicks > 0) {
            $this->jumpCooldownTicks--;
        }

        if ($this->isSwimming()) {
            $this->gravity = 0.0;
        } else {
            $this->gravity = $this->isFlying() ? 0.0 : $this->getInitialGravity();
        }

        $hasUpdate = parent::onUpdate($currentTick);
        if (!$this->isAlive() || $this->isClosed()) {
            return $hasUpdate;
        }

        if ($this->naturalSpawned) {
            $this->naturalAgeTicks++;
            if ($this->naturalAgeTicks > 600 && (($currentTick + $this->tickOffset) % 100) === 0 && !$this->mustPersistNaturally()) {
                $plugin = \pocketmine\Server::getInstance()->getPluginManager()->getPlugin("AmyxAIMobs");
                $distance = $plugin instanceof \BeeAZ\AZVanillaMobs\Main
                    ? max(64, (int) $plugin->getConfig()->get("natural-despawn-distance", 96))
                    : 96;
                $maxDistanceSquared = $distance * $distance;
                $playerNear = false;
                foreach ($this->getWorld()->getPlayers() as $player) {
                    if ($this->location->distanceSquared($player->getLocation()) <= $maxDistanceSquared) {
                        $playerNear = true;
                        break;
                    }
                }
                if (!$playerNear) {
                    $this->flagForDespawn();
                    return true;
                }
            }
        }

        $baseInterval = max(1, $this->getAIUpdateInterval());
        $checkInterval = $this->hasPlayerNearby || $this->isBehaviorActive() ? $baseInterval : max(20, $baseInterval * 2);

        if (($currentTick + $this->tickOffset) % $checkInterval === 0) {
            $this->hasPlayerNearby = false;
            foreach ($this->getWorld()->getPlayers() as $player) {
                if ($this->location->distanceSquared($player->getLocation()) <= 2304) {
                    $this->hasPlayerNearby = true;
                    break;
                }
            }

            $isLeashed = \BeeAZ\AZVanillaMobs\listener\LeashListener::getLeashHolder($this) !== null;
            if (!$isLeashed) {
                $this->behaviorSpeedMultiplier = 1.0;
                $this->calculateAI();
            }
            $hasUpdate = true;

            $timeOfDay = $this->getWorld()->getTimeOfDay();
            $isDaytime = $timeOfDay < World::TIME_NIGHT || $timeOfDay >= World::TIME_SUNRISE;
            if ($this->burnsInDaylight() && !$this->isInsideOfWater() && $isDaytime) {
                if (!\BeeAZ\AZVanillaMobs\listener\EventListener::isWorldRaining($this->getWorld())) {
                    $x = $this->location->getFloorX();
                    $z = $this->location->getFloorZ();
                    if ($this->getWorld()->isChunkGenerated($x >> 4, $z >> 4) && $this->getWorld()->getHighestBlockAt($x, $z) <= $this->location->getFloorY()) {
                        $helmet = $this->getArmorInventory() !== null ? $this->getArmorInventory()->getHelmet() : null;
                        if ($helmet === null || $helmet->isNull()) {
                            $this->setOnFire(8);
                        }
                    }
                }
            }
        }

        if ($this->targetEntity !== null) {
            if (!$this->canUseAsTarget($this->targetEntity)) {
                $this->clearCombatTarget();
            } elseif ($this->structureSpawned && $this->targetEntity instanceof Player && !$this->canLeaveStructureForTarget()) {
                // The chase has gone too far from the registered structure.
                $this->clearCombatTarget();
            } elseif ($this->targetMemoryTicks <= 0 && !$this->canSeeEntity($this->targetEntity)) {
                $this->clearCombatTarget();
                $this->lastAttacker = null;
                $this->revengeTicks = 0;
            } elseif(!$this->canSeeEntity($this->targetEntity)) {
                $this->targetPosition = $this->lastSeenTarget !== null ? clone $this->lastSeenTarget : null;
            }
            // Species AI owns its movement goal. Do not overwrite a skeleton's
            // retreat/stand-and-shoot goal with a forced melee chase here.
        }

        // Structure-only tether. Normal and naturally spawned mobs never enter
        // this branch. After combat ends, the mob returns to its saved home and
        // cannot choose a random destination outside the structure radius.
        if ($this->structureSpawned && $this->structureHome !== null && !$this->canLeaveStructureForTarget()) {
            $radiusSq = $this->structureRadius * $this->structureRadius;
            $distanceSq = $this->location->distanceSquared($this->structureHome);
            if ($distanceSq > $radiusSq) {
                $this->targetPosition = clone $this->structureHome;
                $this->behaviorSpeedMultiplier = max($this->behaviorSpeedMultiplier, 1.25);
                $towardsHome = $this->structureHome->subtractVector($this->location);
                if ($towardsHome->lengthSquared() > 0.001) {
                    $towardsHome = $towardsHome->normalize();
                    $speed = max(0.22, $this->getEffectiveMovementSpeed());
                    $this->setMotion(new Vector3($towardsHome->x * $speed, $this->motion->y, $towardsHome->z * $speed));
                }
            } elseif ($this->targetPosition !== null && $this->targetPosition->distanceSquared($this->structureHome) > $radiusSq) {
                $this->targetPosition = clone $this->structureHome;
            } else {
                $nextPosition = $this->location->add($this->motion->x, 0.0, $this->motion->z);
                if ($nextPosition->distanceSquared($this->structureHome) > $radiusSq) {
                    $this->targetPosition = clone $this->structureHome;
                    $this->setMotion(new Vector3(0.0, $this->motion->y, 0.0));
                }
            }
        }

        // Air-breathing swimmers surface before drowning. Land mobs also paddle
        // upward when knocked into water; ground paths cannot run underwater.
        if($this->hurtControlTicks<=0 && $this->isInsideOfWater() && $this->breathesAir() && !$this->breathesWater()){
            if(!$this->isAquatic() || $this->getAirSupplyTicks()<100){
                $this->motion->y=max($this->motion->y,0.12);
                if($this->isAquatic()){ $this->targetPosition=$this->location->add(0,2,0); }
            }
        }

        // AI must not overwrite the physical impulse immediately after damage.
        if ($this->targetPosition !== null && $this->hurtControlTicks <= 0) {
            $this->moveTowardsTarget();
            $hasUpdate = true;
        }

        $holderPos = \BeeAZ\AZVanillaMobs\listener\LeashListener::getLeashHolder($this);
        if ($holderPos !== null) {
            $this->targetPosition = null;
            $dist = $this->location->distance($holderPos);
            if ($dist > 2.0) {
                $dir = $holderPos->subtractVector($this->location);
                $motionY = $this->motion->y;
                if ($dir->y > 0) {
                    $motionY = $dir->y / max($dist, 1.0) * 0.4;
                }
                $dir->y = 0;
                if ($dir->lengthSquared() > 0) {
                    $dir = $dir->normalize();
                }
                $speed = $dist > 5.0 ? min(0.45, $dist * 0.06) : $this->getEffectiveMovementSpeed();
                $this->setMotion(new Vector3($dir->x * $speed, $motionY, $dir->z * $speed));
                if ($this->isCollidedHorizontally && $this->onGround && $this->jumpCooldownTicks <= 0) {
                    $this->motion->y = max($this->motion->y, $this->getJumpVelocity());
                    $this->jumpCooldownTicks = 14;
                }
                $this->setRotation(rad2deg(atan2($dir->z, $dir->x)) - 90, 0);
                $hasUpdate = true;
            }
        }

        // Keep every mob in an already-loaded chunk scheduled for updates.
        // Returning false removes the entity from World::updateEntities, which
        // previously froze distant mobs until a nearby interaction woke them.
        $plugin = \pocketmine\Server::getInstance()->getPluginManager()->getPlugin("AmyxAIMobs");
        $alwaysTick = !($plugin instanceof \BeeAZ\AZVanillaMobs\Main)
            || (bool) $plugin->getConfig()->get("always-tick-loaded-mobs", true);
        return $alwaysTick ? true : $hasUpdate;
    }

    private function mustPersistNaturally(): bool
    {
        if ($this->structureSpawned) {
            return true;
        }
        $plugin=\pocketmine\Server::getInstance()->getPluginManager()->getPlugin("AmyxAIMobs");
        if($this instanceof Animal && !$this->isAquatic() && !($plugin?->getConfig()->get("despawn-passive",false) ?? false)){ return true; }
        if ($this->getNameTag() !== "") {
            return true;
        }
        if (\BeeAZ\AZVanillaMobs\listener\LeashListener::getLeashHolder($this) !== null) {
            return true;
        }
        if (method_exists($this, "isTamed")) {
            try {
                if ((bool) $this->isTamed()) {
                    return true;
                }
            } catch (\Throwable $e) {}
        }
        return false;
    }

    public function wasNaturallySpawned(): bool
    {
        return $this->naturalSpawned;
    }

    protected function calculateAI(): void {}

    protected function getAIUpdateInterval(): int
    {
        $tps = \pocketmine\Server::getInstance()->getTicksPerSecond();
        return $tps < 12 ? 30 : ($tps < 15 ? 20 : 10);
    }

    protected function moveTowardsTarget(): void
    {
        if ($this->targetPosition === null) {
            return;
        }

        $x = $this->targetPosition->x - $this->location->x;
        $y = $this->targetPosition->y - $this->location->y;
        $z = $this->targetPosition->z - $this->location->z;

        if ($this->isFlying() || $this->isSwimming()) {
            $distanceSq = $x * $x + $y * $y + $z * $z;
            if ($distanceSq < 1.2 || $distanceSq > 4096) {
                if ($this->targetEntity === null) {
                    $this->targetPosition = null;
                }
                $this->motion->x *= 0.35;
                $this->motion->y *= 0.35;
                $this->motion->z *= 0.35;
                return;
            }
            $distance = sqrt($distanceSq);
            $speed = $this->getEffectiveMovementSpeed();
            $desiredX = ($x / $distance) * $speed;
            $desiredY = ($y / $distance) * $speed;
            $desiredZ = ($z / $distance) * $speed;
            $this->motion->x = $this->motion->x * 0.30 + $desiredX * 0.70;
            $this->motion->y = $this->motion->y * 0.30 + $desiredY * 0.70;
            $this->motion->z = $this->motion->z * 0.30 + $desiredZ * 0.70;
            $this->setRotation(rad2deg(atan2($z, $x)) - 90, -rad2deg(atan2($y, sqrt($x * $x + $z * $z))));
            return;
        }

        if($this->isInsideOfWater()){
            $length=sqrt($x*$x+$z*$z);
            if($length>0.01){
                $speed=min(0.12,$this->getEffectiveMovementSpeed()*0.6);
                $this->motion->x=$x/$length*$speed;$this->motion->z=$z/$length*$speed;
            }
            return;
        }
        $plugin=\pocketmine\Server::getInstance()->getPluginManager()->getPlugin("AmyxAIMobs");
        $options=(array)($plugin?->getConfig()->get("ai",[]) ?? []);
        if(($options['pathfinding']??true) && ($x*$x+$z*$z)>0.75){
            $this->navigator ??= new \BeeAZ\AZVanillaMobs\ai\GroundNavigator();
            $waypoint=$this->navigator->next($this,$this->targetPosition,$options);
            if($waypoint===null){ $this->motion->x*=0.35;$this->motion->z*=0.35;return; }
            $x=$waypoint->x-$this->location->x;$y=$waypoint->y-$this->location->y;$z=$waypoint->z-$this->location->z;
        }
        $distanceSq = $x * $x + $z * $z;
        if ($distanceSq < 0.04 || $distanceSq > 4096) {
            if ($this->targetEntity === null) {
                $this->targetPosition = null;
            }
            $this->motion->x *= 0.55;
            $this->motion->z *= 0.55;
            return;
        }

        $distance = sqrt($distanceSq);
        $dirX = $x / $distance;
        $dirZ = $z / $distance;
        $speed = $this->getEffectiveMovementSpeed();

        // Gentle personal-space steering prevents herds and hostile packs from
        // occupying the same point without making them scatter unnaturally.
        if (($this->movementSampleCounter++ % 4) === 0) {
            $this->separationX = 0.0;
            $this->separationZ = 0.0;
            $seen = 0;
            foreach ($this->getWorld()->getNearbyEntities($this->getBoundingBox()->expandedCopy(1.25, 0.8, 1.25), $this) as $nearby) {
                if (!$nearby instanceof BaseMob || $nearby->isClosed()) {
                    continue;
                }
                $dx = $this->location->x - $nearby->getLocation()->x;
                $dz = $this->location->z - $nearby->getLocation()->z;
                $d2 = $dx * $dx + $dz * $dz;
                if ($d2 > 0.0025 && $d2 < 1.5625) {
                    $d = sqrt($d2);
                    $strength = (1.25 - $d) / 1.25;
                    $this->separationX += ($dx / $d) * $strength;
                    $this->separationZ += ($dz / $d) * $strength;
                    if (++$seen >= 6) {
                        break;
                    }
                }
            }
        }
        $dirX += $this->separationX * 0.35;
        $dirZ += $this->separationZ * 0.35;

        // Probe feet and body independently. A jump is allowed only for a real
        // one-block step; head-height walls are steered around instead.
        $frontDistance = max(0.72, ($this->size !== null ? $this->size->getWidth() : 0.6) * 0.65 + 0.45);
        $frontFeet = new Vector3($this->location->x + $dirX * $frontDistance, $this->location->y + 0.05, $this->location->z + $dirZ * $frontDistance);
        $frontBody = $frontFeet->add(0, 1.05, 0);
        $frontTop = $frontFeet->add(0, 2.05, 0);
        $feetBlocked = $this->getWorld()->getBlock($frontFeet)->isSolid();
        $bodyBlocked = $this->getWorld()->getBlock($frontBody)->isSolid();
        $topBlocked = $this->getWorld()->getBlock($frontTop)->isSolid();

        $groundAhead = false;
        for ($drop = 1; $drop <= 3; $drop++) {
            if ($this->getWorld()->getBlock($frontFeet->subtract(0, $drop, 0))->isSolid()) {
                $groundAhead = true;
                break;
            }
        }

        if ($feetBlocked && !$bodyBlocked && !$topBlocked && $this->onGround && $this->jumpCooldownTicks <= 0) {
            $this->motion->y = max($this->motion->y, $this->getJumpVelocity());
            $this->jumpCooldownTicks = 14;
        } elseif ($feetBlocked || $bodyBlocked) {
            $sideX = -$dirZ * $this->steeringDirection;
            $sideZ = $dirX * $this->steeringDirection;
            $dirX = $dirX * 0.25 + $sideX * 0.95;
            $dirZ = $dirZ * 0.25 + $sideZ * 0.95;
        } elseif (!$groundAhead && abs($y) < 2.5) {
            $sideX = -$dirZ * $this->steeringDirection;
            $sideZ = $dirX * $this->steeringDirection;
            $dirX = $dirX * 0.15 + $sideX;
            $dirZ = $dirZ * 0.15 + $sideZ;
        }

        $dirLength = sqrt($dirX * $dirX + $dirZ * $dirZ);
        if ($dirLength > 0.001) {
            $dirX /= $dirLength;
            $dirZ /= $dirLength;
        }

        $this->motion->x = $this->motion->x * 0.30 + $dirX * $speed * 0.70;
        $this->motion->z = $this->motion->z * 0.30 + $dirZ * $speed * 0.70;
        $this->setRotation(rad2deg(atan2($dirZ, $dirX)) - 90, 0);

        // Sample once every few ticks. The old code sampled every tick with a
        // very large threshold, so ordinary walking was mistaken for being stuck
        // and caused endless hopping.
        if (($this->movementSampleCounter % 4) === 0) {
            if ($this->lastMovementSample !== null && $this->location->distanceSquared($this->lastMovementSample) < 0.0256) {
                $this->stuckTicks += 4;
            } else {
                $this->stuckTicks = 0;
            }
            $this->lastMovementSample = clone $this->location;
        }

        if ($this->stuckTicks >= 28) {
            $this->steeringDirection *= -1;
            $this->stuckTicks = 0;
            // Turn away and choose another idle point instead of jumping forever.
            if ($this->targetEntity === null) {
                $this->targetPosition = $this->chooseRandomDestination(10.0, 2.0);
            }
        }
    }

    protected function performMeleeAttack(Living $target, float $damage, float $reachSquared = 3.0): bool
    {
        if (!$this->canUseAsTarget($target) || $this->location->distanceSquared($target->getLocation()) > $reachSquared) {
            return false;
        }
        $event = new EntityDamageByEntityEvent($this, $target, EntityDamageEvent::CAUSE_ENTITY_ATTACK, $damage);
        $target->attack($event);
        if ($event->isCancelled()) {
            return false;
        }

        $packet = new \pocketmine\network\mcpe\protocol\AnimatePacket();
        $packet->action = \pocketmine\network\mcpe\protocol\AnimatePacket::ACTION_SWING_ARM;
        $packet->actorRuntimeId = $this->getId();
        $this->getWorld()->broadcastPacketToViewers($this->getLocation(), $packet);
        return true;
    }

    public function getJumpVelocity(): float { return 0.52; }
    public function getMovementSpeed(): float { return 0.23; }
    public function getXpDropAmount(): int { return 0; }

    protected function syncNetworkData(\pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection $properties): void
    {
        parent::syncNetworkData($properties);

        // Bedrock animation controllers use TARGET_EID for ranged aiming and
        // head tracking. Without it skeletons can shoot but appear motionless.
        $target = $this->getCombatTarget();
        $properties->setLong(
            \pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties::TARGET_EID,
            $target !== null ? $target->getId() : 0
        );

        $holderId = -1;
        $entityId = $this->getId();
        if (isset(\BeeAZ\AZVanillaMobs\listener\LeashListener::$leashedEntities[$entityId])) {
            $holderId = \BeeAZ\AZVanillaMobs\listener\LeashListener::$leashedEntities[$entityId];
        } elseif (isset(\BeeAZ\AZVanillaMobs\listener\LeashListener::$fenceLeashedEntities[$entityId])) {
            $holderId = \BeeAZ\AZVanillaMobs\listener\LeashListener::$fenceLeashedEntities[$entityId]['knotId'];
        } elseif (isset(\BeeAZ\AZVanillaMobs\listener\LeashListener::$entityLeashedEntities[$entityId])) {
            $holderId = \BeeAZ\AZVanillaMobs\listener\LeashListener::$entityLeashedEntities[$entityId];
        }

        if ($holderId !== -1) {
            $properties->setGenericFlag(\pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags::LEASHED, true);
            $properties->setLong(\pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties::LEAD_HOLDER_EID, $holderId);
        } else {
            $properties->setGenericFlag(\pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags::LEASHED, false);
            $properties->setLong(\pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties::LEAD_HOLDER_EID, -1);
        }
    }
}
