<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\entity\the_end;

use BeeAZ\AZVanillaMobs\entity\Monster;
use pocketmine\entity\Living;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;

class Shulker extends Monster
{
    private int $shotCooldown = 0;

    public static function getNetworkTypeId(): string
    {
        return "minecraft:shulker";
    }

    public function getMovementSpeed(): float
    {
        return 0.0;
    }

    protected function getMeleeReachSquared(): float
    {
        return 0.0;
    }

    public function onUpdate(int $currentTick): bool
    {
        if ($this->shotCooldown > 0) {
            $this->shotCooldown--;
        }
        return parent::onUpdate($currentTick);
    }

    protected function calculateAI(): void
    {
        $this->targetPosition = null;
        $target = $this->getRevengeTarget() ?? $this->getCombatTarget();
        if ($target === null || !$this->isValidNaturalTarget($target)) {
            $target = $this->selectNaturalTarget();
        }

        if ($target !== null) {
            $this->setCombatTarget($target, 340);
            $this->lookAt($target->getLocation());
            if ($this->shotCooldown <= 0
                && $this->location->distanceSquared($target->getLocation()) <= 256.0
                && $this->canSeeEntity($target)) {
                $this->fireBullet($target);
                $this->shotCooldown = 40;
            }
        } else {
            $this->clearCombatTarget();
        }
    }

    private function fireBullet(Living $target): void
    {
        // PocketMine has no vanilla shulker-bullet entity in the core API. The
        // gameplay result is reproduced server-side: projectile damage followed
        // by levitation, with an end-rod trail between shooter and target.
        $start = $this->location->add(0, $this->getEyeHeight(), 0);
        $delta = $target->getLocation()->add(0, $target->getEyeHeight() * 0.5, 0)->subtractVector($start);
        $length = $delta->length();
        if ($length > 0.01) {
            $direction = $delta->multiply(1.0 / $length);
            for ($distance = 0.5; $distance < $length; $distance += 1.0) {
                $this->getWorld()->addParticle(
                    $start->addVector($direction->multiply($distance)),
                    new \pocketmine\world\particle\SmokeParticle()
                );
            }
        }

        $event = new EntityDamageByEntityEvent($this, $target, EntityDamageEvent::CAUSE_PROJECTILE, 4.0);
        $target->attack($event);
        if (!$event->isCancelled()) {
            $target->getEffects()->add(new \pocketmine\entity\effect\EffectInstance(
                \pocketmine\entity\effect\VanillaEffects::LEVITATION(), 200, 0
            ));
        }
    }
}
