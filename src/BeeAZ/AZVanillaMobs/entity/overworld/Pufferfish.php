<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\entity\overworld;

use BeeAZ\AZVanillaMobs\entity\Animal;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\item\Item;

class Pufferfish extends Animal {

    public static function getNetworkTypeId(): string {
        return "minecraft:pufferfish";
    }

    public function isAquatic(): bool {
        return true;
    }

    public function isBreedingItem(Item $item): bool {
        return false;
    }

    protected function getInitialSizeInfo(): EntitySizeInfo {
        return new EntitySizeInfo(0.35, 0.35);
    }
}
