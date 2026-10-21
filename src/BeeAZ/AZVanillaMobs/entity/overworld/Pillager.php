<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\entity\overworld;

use BeeAZ\AZVanillaMobs\entity\Monster;
use BeeAZ\AZVanillaMobs\utils\CompatItemManager;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\player\Player;

class Pillager extends Monster
{
    private int $crossbowChargeTicks = 0;
    private int $equipmentRefreshTicks = 0;

    public static function getNetworkTypeId(): string
    {
        return "minecraft:pillager";
    }

    protected function sendSpawnPacket(Player $player): void
    {
        parent::sendSpawnPacket($player);
        $this->sendHeldItem($player, CompatItemManager::get("crossbow") ?? \pocketmine\item\VanillaItems::BOW());
    }

    protected function getMeleeReachSquared(): float
    {
        return 0.0;
    }

    protected function calculateAI(): void
    {
        $this->attackDelay = max(0, $this->attackDelay - $this->getAIUpdateInterval());

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
            if ($distance > 81.0 || !$this->canSeeEntity($target)) {
                $this->targetPosition = clone $target->getLocation();
            } elseif ($distance < 25.0) {
                $this->targetPosition = $this->chooseEscapeDestination($target, 8.0);
            } else {
                $this->targetPosition = null;
                $this->lookAt($target->getLocation());
            }

            if ($distance <= 225.0 && $this->canSeeEntity($target) && $this->attackDelay <= 0 && $this->crossbowChargeTicks === 0) {
                $this->crossbowChargeTicks = 25;
                $this->updateCrossbowPose(true);
            }
            return;
        }

        $this->clearCombatTarget();
        $this->crossbowChargeTicks = 0;
        $this->updateCrossbowPose(false);
        if ($this->targetPosition === null || mt_rand(1, 100) <= 6) {
            $this->targetPosition = $this->chooseRandomDestination(10.0, 2.0);
        }
    }

    public function onUpdate(int $currentTick): bool
    {
        $hasUpdate = parent::onUpdate($currentTick);

        // Some multiprotocol clients overwrite mob equipment shortly after
        // AddActor. Refresh the real crossbow occasionally.
        if(++$this->equipmentRefreshTicks >= 100){
            $this->equipmentRefreshTicks = 0;
            $this->broadcastHeldItem(CompatItemManager::get("crossbow") ?? \pocketmine\item\VanillaItems::BOW());
        }

        if ($this->crossbowChargeTicks > 0) {
            $this->crossbowChargeTicks--;
            $target = $this->getCombatTarget();
            if ($target === null || !$this->canSeeEntity($target)) {
                $this->crossbowChargeTicks = 0;
                $this->updateCrossbowPose(false);
            } elseif ($this->crossbowChargeTicks === 0) {
                $this->updateCrossbowPose(false);
                $this->shootArrow($target);
                $this->attackDelay = 60;
            }
            $hasUpdate = true;
        }
        return $hasUpdate;
    }

    private function updateCrossbowPose(bool $charging): void
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
        $charging = $this->crossbowChargeTicks > 0;
        $properties->setGenericFlag(EntityMetadataFlags::ACTION, $charging);
        $properties->setGenericFlag(EntityMetadataFlags::CHARGE_ATTACK, $charging);
        $properties->setGenericFlag(EntityMetadataFlags::DELAYED_ATTACKING, $charging);
        $properties->setGenericFlag(EntityMetadataFlags::FACING_TARGET_TO_RANGE_ATTACK, $charging);
        if(defined(EntityMetadataFlags::class . "::BODY_ROTATION_ALWAYS_FOLLOWS_HEAD")){ $properties->setGenericFlag(EntityMetadataFlags::BODY_ROTATION_ALWAYS_FOLLOWS_HEAD, $charging); }
    }

    private function shootArrow(Living $target): void
    {
        $origin = $this->getLocation()->add(0, $this->getEyeHeight(), 0);
        $aim = $target->getLocation()->add(0, $target->getEyeHeight() * 0.55, 0);
        $diff = $aim->subtractVector($origin);
        if ($diff->lengthSquared() <= 0.001) {
            return;
        }

        $pitch = -atan2($diff->y, sqrt($diff->x * $diff->x + $diff->z * $diff->z));
        $yaw = atan2($diff->z, $diff->x) - M_PI_2;
        $location = Location::fromObject($origin, $this->getWorld(), rad2deg($yaw), rad2deg($pitch));
        $arrow = new \pocketmine\entity\projectile\Arrow($location, $this, false);
        $arrow->setMotion($diff->normalize()->multiply(1.6));
        $event = new \pocketmine\event\entity\ProjectileLaunchEvent($arrow);
        $event->call();
        if ($event->isCancelled()) {
            $arrow->close();
            return;
        }
        $arrow->spawnToAll();
        $this->getWorld()->addSound($this->location, new \pocketmine\world\sound\BowShootSound());
    }

    public function getXpDropAmount(): int
    {
        return 5;
    }
}
