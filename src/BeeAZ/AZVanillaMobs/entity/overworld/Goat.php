<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\entity\overworld;

use BeeAZ\AZVanillaMobs\entity\Animal;

class Goat extends Animal {
    public static function getNetworkTypeId(): string {
        return "minecraft:goat";
    }

    protected function getBreedingItem(): \pocketmine\item\Item {
        return \pocketmine\item\VanillaItems::WHEAT();
    }
}
