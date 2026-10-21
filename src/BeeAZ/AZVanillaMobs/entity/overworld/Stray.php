<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\entity\overworld;

class Stray extends Skeleton {
    public static function getNetworkTypeId(): string {
        return "minecraft:stray";
    }

    public function isUndead(): bool { return true; }
}
