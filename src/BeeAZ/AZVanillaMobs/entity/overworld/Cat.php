<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\entity\overworld;

use BeeAZ\AZVanillaMobs\entity\Animal;
use pocketmine\item\VanillaItems;

class Cat extends Animal {
    public static function getNetworkTypeId(): string {
        return "minecraft:cat";
    }

    public function isBreedingItem(\pocketmine\item\Item $item): bool {
        return $item->equals(VanillaItems::RAW_FISH(), true, false) || $item->equals(VanillaItems::RAW_SALMON(), true, false);
    }
}
