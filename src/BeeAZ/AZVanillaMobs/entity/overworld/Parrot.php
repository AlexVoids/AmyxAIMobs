<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\entity\overworld;

use BeeAZ\AZVanillaMobs\entity\Animal;
use pocketmine\item\Item;

class Parrot extends Animal
{
    public static function getNetworkTypeId(): string
    {
        return "minecraft:parrot";
    }

    public function isFlying(): bool
    {
        return true;
    }

    // Vanilla parrots cannot be bred. Seeds are intentionally not passed to
    // Animal's love-mode implementation.
    public function isBreedingItem(Item $item): bool
    {
        return false;
    }

    public function getMovementSpeed(): float
    {
        return 0.20;
    }
}
