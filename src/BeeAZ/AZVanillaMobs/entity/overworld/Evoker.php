<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\entity\overworld;

use BeeAZ\AZVanillaMobs\entity\Monster;
use pocketmine\entity\Living;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\math\Vector3;

class Evoker extends Monster
{
    private int $spellCooldown = 0;
    private int $castTicks = 0;
    private ?Living $spellTarget = null;
    private bool $summonSpell = false;

    public static function getNetworkTypeId(): string
    {
        return "minecraft:evocation_illager";
    }

    protected function getMeleeReachSquared(): float
    {
        return 0.0;
    }

    public function onUpdate(int $currentTick): bool
    {
        if ($this->spellCooldown > 0) {
            $this->spellCooldown--;
        }

        $hasUpdate = parent::onUpdate($currentTick);
        if ($this->castTicks > 0) {
            $this->castTicks--;
            $this->targetPosition = null;
            $this->motion->x *= 0.2;
            $this->motion->z *= 0.2;
            if ($this->spellTarget !== null) {
                $this->lookAt($this->spellTarget->getLocation());
            }
            if ($this->castTicks === 0) {
                if ($this->summonSpell) {
                    $this->summonVexes();
                } else {
                    $this->castFangs();
                }
                $this->spellCooldown = $this->summonSpell ? 340 : 100;
                $this->spellTarget = null;
            }
            $hasUpdate = true;
        }
        return $hasUpdate;
    }

    protected function calculateAI(): void
    {
        if ($this->castTicks > 0) {
            return;
        }

        $target = $this->getRevengeTarget() ?? $this->getCombatTarget();
        if ($target === null || !$this->isValidNaturalTarget($target)) {
            $target = $this->selectNaturalTarget();
        }

        if ($target !== null) {
            $this->setCombatTarget($target, 340);
            $distance = $this->location->distanceSquared($target->getLocation());
            if ($distance < 36.0) {
                $this->targetPosition = $this->chooseEscapeDestination($target, 8.0);
            } elseif ($distance > 144.0) {
                $this->targetPosition = clone $target->getLocation();
            } else {
                $this->targetPosition = null;
                $this->lookAt($target->getLocation());
            }

            if ($this->spellCooldown <= 0 && $distance <= 144.0 && $this->canSeeEntity($target)) {
                $this->spellTarget = $target;
                $this->summonSpell = $this->countNearbyVexes() < 3 && mt_rand(1, 3) === 1;
                $this->castTicks = $this->summonSpell ? 30 : 20;
            }
            return;
        }

        $this->clearCombatTarget();
        if ($this->targetPosition === null || mt_rand(1, 100) <= 6) {
            $this->targetPosition = $this->chooseRandomDestination(10.0, 2.0);
        }
    }

    private function countNearbyVexes(): int
    {
        $count = 0;
        foreach ($this->getNearbyLiving(16.0, 12.0) as $entity) {
            if ($entity instanceof Vex) {
                $count++;
            }
        }
        return $count;
    }

    private function summonVexes(): void
    {
        $target = $this->spellTarget;
        for ($i = 0; $i < 3; $i++) {
            $location = \pocketmine\entity\Location::fromObject(
                $this->location->add(mt_rand(-2, 2), mt_rand(1, 3), mt_rand(-2, 2)),
                $this->getWorld()
            );
            $vex = new Vex($location);
            if ($target !== null && $target->isAlive()) {
                $vex->setCombatTarget($target, 600);
            }
            $vex->spawnToAll();
        }
        $this->getWorld()->addSound($this->location, new \pocketmine\world\sound\PopSound());
    }

    private function castFangs(): void
    {
        $target = $this->spellTarget;
        if ($target === null || !$target->isAlive() || $target->isClosed()) {
            return;
        }

        $start = clone $this->location;
        $delta = $target->getLocation()->subtractVector($start);
        $length = max(0.001, sqrt($delta->x * $delta->x + $delta->z * $delta->z));
        $dirX = $delta->x / $length;
        $dirZ = $delta->z / $length;
        for ($distance = 1.0; $distance <= min(12.0, $length + 1.0); $distance += 1.0) {
            $position = new Vector3(
                $start->x + $dirX * $distance,
                $target->getLocation()->y + 0.1,
                $start->z + $dirZ * $distance
            );
            $this->getWorld()->addParticle($position, new \pocketmine\world\particle\SmokeParticle());
        }

        if ($this->location->distanceSquared($target->getLocation()) <= 196.0) {
            $event = new EntityDamageByEntityEvent($this, $target, EntityDamageEvent::CAUSE_MAGIC, 6.0);
            $target->attack($event);
        }
    }
}
