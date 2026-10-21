<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\entity\nether;

use BeeAZ\AZVanillaMobs\entity\Animal;

class Strider extends Animal {
    public static function getNetworkTypeId(): string {
        return "minecraft:strider";
    }
}
