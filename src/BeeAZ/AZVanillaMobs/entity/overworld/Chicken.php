<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\entity\overworld;

use BeeAZ\AZVanillaMobs\entity\Animal;

class Chicken extends Animal {
    public static function getNetworkTypeId(): string {
        return "minecraft:chicken";
    }

    protected function getBreedingItem(): \pocketmine\item\Item {
        return \pocketmine\item\VanillaItems::WHEAT_SEEDS();
    }

    public function isBreedingItem(\pocketmine\item\Item $item): bool {
        $typeId = $item->getTypeId();
        return $typeId === \pocketmine\item\VanillaItems::WHEAT_SEEDS()->getTypeId() ||
               $typeId === \pocketmine\item\VanillaItems::PUMPKIN_SEEDS()->getTypeId() ||
               $typeId === \pocketmine\item\VanillaItems::MELON_SEEDS()->getTypeId() ||
               $typeId === \pocketmine\item\VanillaItems::BEETROOT_SEEDS()->getTypeId();
    }
}
