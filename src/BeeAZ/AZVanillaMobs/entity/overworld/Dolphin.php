<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\entity\overworld;

use BeeAZ\AZVanillaMobs\entity\Animal;

class Dolphin extends Animal {
    private int $attackDelay = 0;
    public static function getNetworkTypeId(): string {
        return "minecraft:dolphin";
    }

    public function isAquatic(): bool {
        return true;
    }
    public function breathesAir(): bool { return true; }
    public function breathesWater(): bool { return false; }

    protected function shouldPanicWhenHurt(): bool { return false; }

    public function attack(\pocketmine\event\entity\EntityDamageEvent $source): void {
        parent::attack($source);
        if (!$source->isCancelled() && $source instanceof \pocketmine\event\entity\EntityDamageByEntityEvent) {
            $damager = $source->getDamager();
            if ($damager instanceof \pocketmine\entity\Living && $this->canUseAsTarget($damager)) {
                $this->setCombatTarget($damager, 300);
                foreach ($this->getNearbyLiving(16.0, 8.0) as $nearby) {
                    if ($nearby instanceof self) {
                        $nearby->rememberAttacker($damager, 300);
                        $nearby->setCombatTarget($damager, 300);
                    }
                }
            }
        }
    }

    public function onUpdate(int $currentTick): bool {
        if ($this->attackDelay > 0) $this->attackDelay--;
        return parent::onUpdate($currentTick);
    }

    protected function calculateAI(): void {
        $target = $this->getRevengeTarget() ?? $this->getCombatTarget();
        if ($target !== null) {
            $this->setCombatTarget($target, 300);
            $this->targetPosition = clone $target->getLocation();
            $this->setBehaviorSpeedMultiplier(1.3);
            if ($this->location->distanceSquared($target->getLocation()) < 2.5 && $this->attackDelay <= 0) {
                if ($this->performMeleeAttack($target, 3.0, 2.5)) {
                    $this->attackDelay = 20;
                }
            }
            return;
        }
        $this->clearCombatTarget();
        parent::calculateAI();
    }
}
