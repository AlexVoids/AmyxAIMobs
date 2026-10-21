<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\entity\overworld;

use BeeAZ\AZVanillaMobs\entity\Animal;

class GlowSquid extends Animal
{
    use SquidMovementTrait;

    public static function getNetworkTypeId(): string
    {
        return "minecraft:glow_squid";
    }

    public function isAquatic(): bool
    {
        return true;
    }
}
