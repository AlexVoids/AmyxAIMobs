<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\entity\overworld;

use BeeAZ\AZVanillaMobs\entity\Animal;

class Bat extends Animal {
    public static function getNetworkTypeId(): string {
        return "minecraft:bat";
    }

    public function isFlying(): bool {
        return true;
    }
}
