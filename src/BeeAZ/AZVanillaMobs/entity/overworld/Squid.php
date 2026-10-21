<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\entity\overworld;

use BeeAZ\AZVanillaMobs\entity\Animal;

class Squid extends Animal
{
    use SquidMovementTrait;

    public static function getNetworkTypeId(): string
    {
        return "minecraft:squid";
    }

    public function isAquatic(): bool
    {
        return true;
    }
}
