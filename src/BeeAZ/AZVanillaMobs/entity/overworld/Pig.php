<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\entity\overworld;

use BeeAZ\AZVanillaMobs\entity\Animal;

class Pig extends Animal {
    public static function getNetworkTypeId(): string {
        return "minecraft:pig";
    }

    protected function getBreedingItem(): \pocketmine\item\Item {
        return \pocketmine\item\VanillaItems::CARROT();
    }

    public function isBreedingItem(\pocketmine\item\Item $item): bool {
        $typeId = $item->getTypeId();
        return $typeId === \pocketmine\item\VanillaItems::CARROT()->getTypeId() ||
               $typeId === \pocketmine\item\VanillaItems::POTATO()->getTypeId() ||
               $typeId === \pocketmine\item\VanillaItems::BEETROOT()->getTypeId();
    }
}
