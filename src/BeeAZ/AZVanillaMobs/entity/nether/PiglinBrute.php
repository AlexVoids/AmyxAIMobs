<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\entity\nether;

use BeeAZ\AZVanillaMobs\entity\Monster;

class PiglinBrute extends Monster {
    public static function getNetworkTypeId(): string {
        return "minecraft:piglin_brute";
    }
}
