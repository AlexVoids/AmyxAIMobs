<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\entity\overworld;

use BeeAZ\AZVanillaMobs\entity\Monster;

class Spider extends Monster {
    public static function getNetworkTypeId(): string {
        return "minecraft:spider";
    }

    protected function initEntity(\pocketmine\nbt\tag\CompoundTag $nbt): void {
        parent::initEntity($nbt);
        $this->getNetworkProperties()->setGenericFlag(\pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags::WALLCLIMBING, false);
    }

    protected function syncNetworkData(\pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection $properties): void {
        parent::syncNetworkData($properties);
        $properties->setGenericFlag(\pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags::WALLCLIMBING, $this->isCollidedHorizontally);
    }


    protected function calculateAI(): void {
        parent::calculateAI();

        $target = $this->getCombatTarget();
        if ($target === null || !$this->onGround) {
            return;
        }

        $distanceSquared = $this->location->distanceSquared($target->getLocation());
        if ($distanceSquared >= 4.0 && $distanceSquared <= 36.0 && mt_rand(1, 4) === 1) {
            $direction = $target->getLocation()->subtractVector($this->location);
            $direction->y = 0;
            if ($direction->lengthSquared() > 0.001) {
                $direction = $direction->normalize();
                $this->setMotion(new \pocketmine\math\Vector3(
                    $direction->x * 0.42,
                    0.4,
                    $direction->z * 0.42
                ));
            }
        }
    }

    protected function moveTowardsTarget(): void {
        parent::moveTowardsTarget();
        if ($this->isCollidedHorizontally) {
            $this->motion->y = 0.2;
        }
    }
}
