<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\entity\overworld;

use BeeAZ\AZVanillaMobs\entity\Monster;

class Vex extends Monster {
    public static function getNetworkTypeId(): string {
        return "minecraft:vex";
    }

    public function isFlying(): bool {
        return true;
    }

    public function move(float $dx, float $dy, float $dz): void {
        $this->location->x += $dx;
        $this->location->y += $dy;
        $this->location->z += $dz;
        $this->recalculateBoundingBox();
    }
}
