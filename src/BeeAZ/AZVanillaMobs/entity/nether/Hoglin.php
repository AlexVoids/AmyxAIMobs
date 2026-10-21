<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\entity\nether;

use BeeAZ\AZVanillaMobs\entity\Monster;

class Hoglin extends Monster {
    public static function getNetworkTypeId(): string {
        return "minecraft:hoglin";
    }

    public function attack(\pocketmine\event\entity\EntityDamageEvent $source): void {
        parent::attack($source);
    }

    protected function calculateAI(): void {
        // Hoglins avoid warped fungi, portals and respawn anchors.
        $dangerBlock = null;
        $best = PHP_FLOAT_MAX;
        for ($x = -8; $x <= 8; $x += 2) {
            for ($y = -3; $y <= 3; $y++) {
                for ($z = -8; $z <= 8; $z += 2) {
                    $pos = $this->location->add($x, $y, $z);
                    $name = strtolower($this->getWorld()->getBlock($pos)->getName());
                    if (str_contains($name, 'warped fungus') || str_contains($name, 'portal') || str_contains($name, 'respawn anchor')) {
                        $distance = $this->location->distanceSquared($pos);
                        if ($distance < $best) {
                            $best = $distance;
                            $dangerBlock = $pos;
                        }
                    }
                }
            }
        }

        if ($dangerBlock !== null) {
            $away = $this->location->subtractVector($dangerBlock);
            $away->y = 0;
            if ($away->lengthSquared() > 0) {
                $away = $away->normalize();
                $candidate = $this->findWalkablePosition(
                    (int) floor($this->location->x + $away->x * 9),
                    (int) floor($this->location->z + $away->z * 9),
                    $this->location->getFloorY()
                );
                if ($candidate !== null) {
                    $this->targetPosition = $candidate;
                    $this->clearCombatTarget();
                    $this->setBehaviorSpeedMultiplier(1.2);
                    return;
                }
            }
        }

        // Retreat when nearby adult piglins noticeably outnumber hoglins.
        $piglins = 0;
        $hoglins = 1;
        $nearestPiglin = null;
        $nearestDistance = PHP_FLOAT_MAX;
        foreach ($this->getNearbyLiving(10.0, 8.0) as $entity) {
            if ($entity instanceof Piglin || $entity instanceof PiglinBrute) {
                $piglins++;
                $distance = $this->location->distanceSquared($entity->getLocation());
                if ($distance < $nearestDistance) {
                    $nearestDistance = $distance;
                    $nearestPiglin = $entity;
                }
            } elseif ($entity instanceof self) {
                $hoglins++;
            }
        }
        if ($nearestPiglin !== null && $piglins > $hoglins) {
            $this->targetPosition = $this->chooseEscapeDestination($nearestPiglin, 10.0);
            $this->clearCombatTarget();
            $this->setBehaviorSpeedMultiplier(1.2);
            return;
        }

        parent::calculateAI();
    }

}
