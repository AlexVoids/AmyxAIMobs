<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\entity\overworld;

use BeeAZ\AZVanillaMobs\entity\Monster;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\player\Player;

class Skeleton extends Monster
{
    private int $bowChargeTicks = 0;

    public function __construct(Location $location, ?CompoundTag $nbt = null)
    {
        parent::__construct($location, $nbt);
    }

    public static function getNetworkTypeId(): string
    {
        return "minecraft:skeleton";
    }

    protected function sendSpawnPacket(Player $player): void
    {
        parent::sendSpawnPacket($player);
        $this->sendHeldItem($player, \pocketmine\item\VanillaItems::BOW());
    }

    public function isUndead(): bool
    {
        return true;
    }

    protected function calculateAI(): void
    {
        $this->attackDelay = max(0, $this->attackDelay - $this->getAIUpdateInterval());

        $threat = $this->findAvoidanceThreat();
        if ($threat !== null) {
            $this->clearCombatTarget();
            $this->targetPosition = $this->chooseEscapeDestination($threat, 9.0);
            $this->setBehaviorSpeedMultiplier(1.2);
            $this->cancelBowCharge();
            return;
        }

        $target = $this->getRevengeTarget();
        if ($target === null) {
            $current = $this->getCombatTarget();
            if ($current !== null && !$this->isValidNaturalTarget($current)) {
                $current = null;
            }
            $target = $this->selectPreferredTarget($current);
        }

        if ($target !== null) {
            $this->setCombatTarget($target, 340);
            $distance = $this->location->distanceSquared($target->getLocation());

            if ($distance > 64.0 || !$this->canSeeEntity($target)) {
                $this->targetPosition = clone $target->getLocation();
            } elseif ($distance < 20.0) {
                $this->targetPosition = $this->chooseEscapeDestination($target, 7.0);
            } else {
                $this->targetPosition = null;
                $this->lookAt($target->getLocation());
            }

            if ($distance < 225.0 && $this->canSeeEntity($target) && $this->attackDelay <= 0 && $this->bowChargeTicks === 0) {
                $this->bowChargeTicks = 20;
                $this->updateBowPose(true);
                $this->broadcastArmAnimation();
            }
            return;
        }

        $this->clearCombatTarget();
        $this->cancelBowCharge();
        if ($this->targetPosition === null || mt_rand(1, 100) <= 6) {
            $this->targetPosition = $this->chooseRandomDestination(10.0, 2.0);
        }
    }

    public function onUpdate(int $currentTick): bool
    {
        $hasUpdate = parent::onUpdate($currentTick);

        if ($this->bowChargeTicks > 0) {
            $this->bowChargeTicks--;
            $target = $this->getCombatTarget();
            if ($target === null || !$this->canSeeEntity($target)) {
                $this->cancelBowCharge();
            } elseif ($this->bowChargeTicks === 0) {
                $this->updateBowPose(false);
                $this->shootArrow($target);
                $this->attackDelay = 40;
            }
            $hasUpdate = true;
        }

        return $hasUpdate;
    }

    private function cancelBowCharge(): void
    {
        $this->bowChargeTicks = 0;
        $this->updateBowPose(false);
    }

    private function updateBowPose(bool $charging): void
    {
        $properties = $this->getNetworkProperties();
        $properties->setGenericFlag(EntityMetadataFlags::ACTION, $charging);
        $properties->setGenericFlag(EntityMetadataFlags::CHARGE_ATTACK, $charging);
        $properties->setGenericFlag(EntityMetadataFlags::DELAYED_ATTACKING, $charging);
        $properties->setGenericFlag(EntityMetadataFlags::FACING_TARGET_TO_RANGE_ATTACK, $charging);
        if(defined(EntityMetadataFlags::class . "::BODY_ROTATION_ALWAYS_FOLLOWS_HEAD")){ $properties->setGenericFlag(EntityMetadataFlags::BODY_ROTATION_ALWAYS_FOLLOWS_HEAD, $charging); }
        $this->sendData(null);
    }

    protected function syncNetworkData(EntityMetadataCollection $properties): void
    {
        parent::syncNetworkData($properties);
        $charging = $this->bowChargeTicks > 0;
        $properties->setGenericFlag(EntityMetadataFlags::ACTION, $charging);
        $properties->setGenericFlag(EntityMetadataFlags::CHARGE_ATTACK, $charging);
        $properties->setGenericFlag(EntityMetadataFlags::DELAYED_ATTACKING, $charging);
        $properties->setGenericFlag(EntityMetadataFlags::FACING_TARGET_TO_RANGE_ATTACK, $charging);
        if(defined(EntityMetadataFlags::class . "::BODY_ROTATION_ALWAYS_FOLLOWS_HEAD")){ $properties->setGenericFlag(EntityMetadataFlags::BODY_ROTATION_ALWAYS_FOLLOWS_HEAD, $charging); }
    }

    private function broadcastArmAnimation(): void
    {
        $packet = new \pocketmine\network\mcpe\protocol\AnimatePacket();
        $packet->action = \pocketmine\network\mcpe\protocol\AnimatePacket::ACTION_SWING_ARM;
        $packet->actorRuntimeId = $this->getId();
        $this->getWorld()->broadcastPacketToViewers($this->getLocation(), $packet);
    }

    private function shootArrow(Living $target): void
    {
        $origin = $this->getLocation()->add(0, $this->getEyeHeight(), 0);
        $aim = $target->getLocation()->add(0, $target->getEyeHeight() * 0.65, 0);
        $diff = $aim->subtractVector($origin);
        if ($diff->lengthSquared() <= 0.001) {
            return;
        }

        $pitch = -atan2($diff->y, sqrt($diff->x * $diff->x + $diff->z * $diff->z));
        $yaw = atan2($diff->z, $diff->x) - M_PI_2;
        $location = Location::fromObject($origin, $this->getWorld(), rad2deg($yaw), rad2deg($pitch));
        $arrow = new \pocketmine\entity\projectile\Arrow($location, $this, false);
        $arrow->setMotion($diff->normalize()->multiply(1.5));

        $event = new \pocketmine\event\entity\ProjectileLaunchEvent($arrow);
        $event->call();
        if ($event->isCancelled()) {
            $arrow->close();
            return;
        }

        $arrow->spawnToAll();
        $this->getWorld()->addSound($this->getLocation(), new \pocketmine\world\sound\BowShootSound());
        $this->broadcastArmAnimation();
    }
}
