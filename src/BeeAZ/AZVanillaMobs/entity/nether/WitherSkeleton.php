<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\entity\nether;

use BeeAZ\AZVanillaMobs\entity\Monster;

class WitherSkeleton extends Monster {
    protected function sendSpawnPacket(\pocketmine\player\Player $player): void {
        parent::sendSpawnPacket($player);
        $this->sendHeldItem($player, \pocketmine\item\VanillaItems::STONE_SWORD());
    }

    public static function getNetworkTypeId(): string {
        return "minecraft:wither_skeleton";
    }

    public function isUndead(): bool { return true; }
    public function burnsInDaylight(): bool { return false; }
}
