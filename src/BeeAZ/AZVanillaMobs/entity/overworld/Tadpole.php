<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\entity\overworld;

use BeeAZ\AZVanillaMobs\entity\Animal;

class Tadpole extends Animal {
    public static function getNetworkTypeId(): string {
        return "minecraft:tadpole";
    }

    public function isAquatic(): bool {
        return true;
    }
}
