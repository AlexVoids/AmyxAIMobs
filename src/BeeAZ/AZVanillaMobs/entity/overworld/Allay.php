<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\entity\overworld;

use BeeAZ\AZVanillaMobs\entity\Animal;

class Allay extends Animal {
    public static function getNetworkTypeId(): string {
        return "minecraft:allay";
    }

    public function isFlying(): bool {
        return true;
    }
}
