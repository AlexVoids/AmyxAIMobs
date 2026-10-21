<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\entity\overworld;

use BeeAZ\AZVanillaMobs\entity\Monster;

class Vindicator extends Monster {
    public static function getNetworkTypeId(): string {
        return "minecraft:vindicator";
    }

    protected function sendSpawnPacket(\pocketmine\player\Player $player): void {
        parent::sendSpawnPacket($player);
        $this->sendHeldItem($player, \pocketmine\item\VanillaItems::IRON_AXE());
    }
}
