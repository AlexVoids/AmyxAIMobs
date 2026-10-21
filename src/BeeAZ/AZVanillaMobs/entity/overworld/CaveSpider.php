<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\entity\overworld;

use BeeAZ\AZVanillaMobs\entity\Monster;

class CaveSpider extends Spider {
    public static function getNetworkTypeId(): string {
        return "minecraft:cave_spider";
    }
}
