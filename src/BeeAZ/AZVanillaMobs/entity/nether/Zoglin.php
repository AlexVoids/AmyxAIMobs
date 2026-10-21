<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\entity\nether;

use BeeAZ\AZVanillaMobs\entity\Monster;

class Zoglin extends Monster {
    public static function getNetworkTypeId(): string {
        return "minecraft:zoglin";
    }
}
