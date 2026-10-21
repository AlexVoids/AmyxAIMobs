<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\entity\the_end;

use BeeAZ\AZVanillaMobs\entity\Monster;

class Endermite extends Monster {
    public static function getNetworkTypeId(): string {
        return "minecraft:endermite";
    }
}
