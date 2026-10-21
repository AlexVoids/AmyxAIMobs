<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\entity\overworld;

use BeeAZ\AZVanillaMobs\entity\Monster;

class ZombieVillager extends Monster {
    public static function getNetworkTypeId(): string {
        return "minecraft:zombie_villager";
    }

    public function isUndead(): bool { return true; }
}
