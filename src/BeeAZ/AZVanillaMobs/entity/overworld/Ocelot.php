<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\entity\overworld;

use BeeAZ\AZVanillaMobs\entity\Animal;

class Ocelot extends Animal {
    public static function getNetworkTypeId(): string {
        return "minecraft:ocelot";
    }

    public function isBreedingItem(\pocketmine\item\Item $item): bool {
        $typeId = $item->getTypeId();
        return $typeId === \pocketmine\item\VanillaItems::RAW_FISH()->getTypeId() ||
               $typeId === \pocketmine\item\VanillaItems::RAW_SALMON()->getTypeId();
    }
}
