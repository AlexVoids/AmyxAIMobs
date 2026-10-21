<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\entity\overworld;

use BeeAZ\AZVanillaMobs\entity\Monster;
use pocketmine\player\Player;
use pocketmine\entity\Location;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\PlaySoundPacket;

class Witch extends Monster {

    public static function getNetworkTypeId(): string {
        return "minecraft:witch";
    }

    protected function calculateAI(): void {
        parent::calculateAI();

        $nearest = null;
        $minDist = 1024;
        foreach ($this->getWorld()->getPlayers() as $player) {
            if ($player->isCreative() || $player->isSpectator()) continue;
            $dist = $this->location->distanceSquared($player->getLocation());
            if ($dist < $minDist) {
                $minDist = $dist;
                $nearest = $player;
            }
        }

        if ($nearest !== null) {
            $this->lookAt($nearest->getLocation());

            if ($this->attackDelay <= 0) {
                $this->throwPotion($nearest);
                $this->attackDelay = 50;
            }
        }
    }

    private function throwPotion(Player $target): void {
        $diff = $target->getLocation()->subtractVector($this->getLocation());
        $pitch = -atan2($diff->y, sqrt($diff->x * $diff->x + $diff->z * $diff->z));
        $yaw = atan2($diff->z, $diff->x) - M_PI_2;

        $direction = $this->getDirectionVector();

        $spawnPos = $this->getLocation()->add(0, $this->getEyeHeight(), 0)->addVector($direction->multiply(1.0));

        $location = Location::fromObject($spawnPos, $this->getWorld(), rad2deg($yaw), rad2deg($pitch));

        $potion = new \BeeAZ\AZVanillaMobs\entity\projectile\WitchPotion($location, $this);

        $motion = $direction->multiply(1.1);
        $motion->y += 0.15;
        $potion->setMotion($motion);

        $ev = new \pocketmine\event\entity\ProjectileLaunchEvent($potion);
        $ev->call();

        if (!$ev->isCancelled()) {
            $potion->spawnToAll();

            $pk = new PlaySoundPacket();
            $pk->soundName = "mob.witch.throw";
            $pk->x = $spawnPos->x;
            $pk->y = $spawnPos->y;
            $pk->z = $spawnPos->z;
            $pk->volume = 1.0;
            $pk->pitch = 1.0;
            $this->getWorld()->broadcastPacketToViewers($spawnPos, $pk);
        } else {
            $potion->close();
        }
    }
}
