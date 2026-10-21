<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\entity\overworld;

class ElderGuardian extends Guardian
{
    public static function getNetworkTypeId(): string
    {
        return "minecraft:elder_guardian";
    }

    protected function getLaserDamage(): float
    {
        return 8.0;
    }

    protected function getLaserChargeTicks(): int
    {
        return 60;
    }

    public function getXpDropAmount(): int
    {
        return 10;
    }
}
