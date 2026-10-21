<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\entity\overworld;

use BeeAZ\AZVanillaMobs\entity\Monster;
use pocketmine\entity\Living;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;

class Silverfish extends Monster
{
    public static function getNetworkTypeId(): string
    {
        return "minecraft:silverfish";
    }

    public function attack(EntityDamageEvent $source): void
    {
        parent::attack($source);
        if (!$source->isCancelled() && $source instanceof EntityDamageByEntityEvent) {
            $damager = $source->getDamager();
            if ($damager instanceof Living) {
                foreach ($this->getNearbyLiving(20.0, 10.0) as $nearby) {
                    if ($nearby instanceof self) {
                        $nearby->rememberAttacker($damager, 300);
                        $nearby->setCombatTarget($damager, 300);
                    }
                }
            }
        }
    }
}
