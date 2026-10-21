<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\entity\overworld;

use BeeAZ\AZVanillaMobs\entity\Animal;

class Llama extends Animal {
    private int $spitCooldown = 0;
    public static function getNetworkTypeId(): string {
        return "minecraft:llama";
    }

    public function isBreedingItem(\pocketmine\item\Item $item): bool {
        $hay = \pocketmine\item\StringToItemParser::getInstance()->parse("hay_block");
        return $hay !== null && $item->equals($hay, false, false);
    }

    protected function shouldPanicWhenHurt(): bool { return false; }

    public function attack(\pocketmine\event\entity\EntityDamageEvent $source): void {
        parent::attack($source);
        if (!$source->isCancelled() && $source instanceof \pocketmine\event\entity\EntityDamageByEntityEvent) {
            $damager = $source->getDamager();
            if ($damager instanceof \pocketmine\entity\Living && $this->canUseAsTarget($damager)) {
                $this->setCombatTarget($damager, 200);
            }
        }
    }

    public function onUpdate(int $currentTick): bool {
        if ($this->spitCooldown > 0) $this->spitCooldown--;
        return parent::onUpdate($currentTick);
    }

    protected function calculateAI(): void {
        $target = $this->getRevengeTarget() ?? $this->getCombatTarget();
        if ($target !== null) {
            $distance = $this->location->distanceSquared($target->getLocation());
            $this->lookAt($target->getLocation());
            if ($distance < 20.0) {
                $this->targetPosition = $this->chooseEscapeDestination($target, 7.0);
            } elseif ($distance > 196.0) {
                $this->clearCombatTarget();
            } else {
                $this->targetPosition = null;
            }

            if ($distance <= 225.0 && $this->canSeeEntity($target) && $this->spitCooldown <= 0) {
                $event = new \pocketmine\event\entity\EntityDamageByEntityEvent(
                    $this, $target, \pocketmine\event\entity\EntityDamageEvent::CAUSE_PROJECTILE, 1.0
                );
                $target->attack($event);
                if (!$event->isCancelled()) {
                    $this->getWorld()->addSound($this->location, new \pocketmine\world\sound\PopSound());
                }
                $this->spitCooldown = 40;
            }
            return;
        }
        parent::calculateAI();
    }
}
