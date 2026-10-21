<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\entity\overworld;

use BeeAZ\AZVanillaMobs\entity\Monster;
use pocketmine\entity\Living;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\math\Vector3;

class Guardian extends Monster
{
    protected int $laserTicks = 0;
    protected int $laserCooldown = 0;
    protected ?Living $laserTarget = null;

    public static function getNetworkTypeId(): string
    {
        return "minecraft:guardian";
    }

    public function isAquatic(): bool
    {
        return true;
    }

    protected function getLaserDamage(): float
    {
        return 6.0;
    }

    protected function getLaserChargeTicks(): int
    {
        return 80;
    }

    protected function getMeleeReachSquared(): float
    {
        return 0.0;
    }

    public function onUpdate(int $currentTick): bool
    {
        if ($this->laserCooldown > 0) {
            $this->laserCooldown--;
        }

        $hasUpdate = parent::onUpdate($currentTick);
        if (!$this->isAlive() || $this->isClosed()) {
            return $hasUpdate;
        }

        if ($this->laserTarget !== null) {
            if (!$this->canUseAsTarget($this->laserTarget)
                || !$this->canSeeEntity($this->laserTarget)
                || $this->location->distanceSquared($this->laserTarget->getLocation()) > 256.0) {
                $this->laserTarget = null;
                $this->laserTicks = 0;
            } elseif ($this->laserCooldown <= 0) {
                $this->laserTicks++;
                $this->lookAt($this->laserTarget->getLocation());

                if ($currentTick % 3 === 0) {
                    $this->spawnBeamParticles($this->laserTarget);
                }

                if ($this->laserTicks >= $this->getLaserChargeTicks()) {
                    $event = new EntityDamageByEntityEvent(
                        $this,
                        $this->laserTarget,
                        EntityDamageEvent::CAUSE_MAGIC,
                        $this->getLaserDamage()
                    );
                    $this->laserTarget->attack($event);
                    $this->laserTicks = 0;
                    $this->laserCooldown = 20;
                }
                $hasUpdate = true;
            }
        }

        return $hasUpdate;
    }

    protected function calculateAI(): void
    {
        $target = $this->getRevengeTarget() ?? $this->getCombatTarget();
        if ($target === null || !$this->isValidNaturalTarget($target)) {
            $target = $this->selectNaturalTarget();
        }

        if ($target !== null) {
            $this->setCombatTarget($target, 340);
            $distance = $this->location->distanceSquared($target->getLocation());

            if ($distance < 9.0) {
                $this->targetPosition = $this->chooseEscapeDestination($target, 6.0);
                $this->setBehaviorSpeedMultiplier(1.15);
                $this->laserTarget = null;
                $this->laserTicks = 0;
            } else {
                $this->targetPosition = null;
                if ($this->laserTarget !== $target) {
                    $this->laserTarget = $target;
                    $this->laserTicks = 0;
                }
            }
            return;
        }

        $this->laserTarget = null;
        $this->laserTicks = 0;
        $this->clearCombatTarget();
        parent::calculateAI();
    }

    protected function spawnBeamParticles(Living $target): void
    {
        $start = $this->location->add(0, $this->getEyeHeight(), 0);
        $end = $target->getLocation()->add(0, $target->getEyeHeight() * 0.5, 0);
        $delta = $end->subtractVector($start);
        $length = $delta->length();
        if ($length <= 0.01) {
            return;
        }
        $direction = $delta->multiply(1.0 / $length);
        for ($distance = 0.5; $distance < $length; $distance += 1.0) {
            $position = $start->addVector($direction->multiply($distance));
            $this->getWorld()->addParticle($position, new \pocketmine\world\particle\SmokeParticle());
        }
    }

    public function getXpDropAmount(): int
    {
        return 10;
    }
}
