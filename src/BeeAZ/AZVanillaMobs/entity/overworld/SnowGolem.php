<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\entity\overworld;

use BeeAZ\AZVanillaMobs\entity\Animal;
use BeeAZ\AZVanillaMobs\entity\Monster;
use pocketmine\entity\Location;
use pocketmine\entity\projectile\Snowball;
use pocketmine\world\sound\ThrowSound;

class SnowGolem extends Animal {

    private int $shootDelay = 0;

    public static function getNetworkTypeId(): string {
        return "minecraft:snow_golem";
    }

    public function onUpdate(int $currentTick): bool {
        if ($this->shootDelay > 0) {
            $this->shootDelay--;
        }
        return parent::onUpdate($currentTick);
    }

    protected function calculateAI(): void {
        
        $nearest = null;
        $minDist = 1024.0; 

        foreach ($this->getWorld()->getEntities() as $entity) {
            if ($entity instanceof Monster && $entity->isAlive()) {
                $dist = $this->location->distanceSquared($entity->getLocation());
                if ($dist < $minDist) {
                    $minDist = $dist;
                    $nearest = $entity;
                }
            }
        }

        if ($nearest !== null) {
            
            $targetPos = $nearest->getLocation();
            $x = $targetPos->x - $this->location->x;
            $z = $targetPos->z - $this->location->z;
            $yaw = rad2deg(atan2($z, $x)) - 90;
            $this->setRotation($yaw, 0.0);

            $this->targetPosition = null; 

            if ($this->shootDelay <= 0) {
                
                $sourcePos = $this->location->add(0, 1.5, 0);
                $eyePos = $nearest->getLocation()->add(0, $nearest->getEyeHeight(), 0);
                
                $direction = $eyePos->subtract($sourcePos->x, $sourcePos->y, $sourcePos->z)->normalize();
                $motion = $direction->multiply(1.3);

                $loc = Location::fromObject($sourcePos, $this->getWorld(), $this->location->yaw, $this->location->pitch);
                
                try {
                    $snowball = new Snowball($loc, $this);
                    $snowball->setMotion($motion);
                    $snowball->spawnToAll();

                    $this->getWorld()->addSound($this->location, new ThrowSound());
                } catch (\Exception $e) {}

                $this->shootDelay = 20; 
            }
        } else {
            parent::calculateAI();
        }
    }
}
