<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\entity\nether;

use BeeAZ\AZVanillaMobs\entity\Monster;

/** Neutral until attacked, then alerts nearby zombified piglins via Monster::broadcastRevenge(). */
class ZombifiedPiglin extends Monster {
    public static function getNetworkTypeId(): string {
        return "minecraft:zombie_pigman";
    }

    public function isUndead(): bool { return true; }
    public function burnsInDaylight(): bool { return false; }
}
