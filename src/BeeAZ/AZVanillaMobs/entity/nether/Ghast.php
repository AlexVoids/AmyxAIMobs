<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\entity\nether;

use BeeAZ\AZVanillaMobs\entity\Monster;
use pocketmine\player\Player;
use pocketmine\entity\Location;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\PlaySoundPacket;

class Ghast extends Monster {

    private int $chargeTicks = 0;

    public static function getNetworkTypeId(): string {
        return "minecraft:ghast";
    }

    public function isFlying(): bool {
        return true;
    }

    public function getXpDropAmount(): int {
        return 10;
    }

    protected function calculateAI(): void {
        if ($this->attackDelay > 0) {
            $this->attackDelay -= 10;
        }

        $nearest = null;
        $minDist = 4096;

        foreach ($this->getWorld()->getPlayers() as $player) {
            if ($player->isCreative() || $player->isSpectator()) continue;

            $dist = $this->location->distanceSquared($player->getLocation());
            if ($dist < $minDist) {
                $minDist = $dist;
                $nearest = $player;
            }
        }

        if ($nearest !== null) {

            if ($this->targetPosition === null || $this->location->distanceSquared($this->targetPosition) < 16 || mt_rand(1, 100) <= 5) {
                $playerPos = $nearest->getLocation();
                $randX = $playerPos->x + mt_rand(-20, 20);
                $randZ = $playerPos->z + mt_rand(-20, 20);
                $targetY = $playerPos->y + 20.0;

                $newPos = new \pocketmine\math\Vector3($randX, $targetY, $randZ);
                if (!$this->getWorld()->getBlock($newPos)->isSolid()) {
                    $this->targetPosition = $newPos;
                } else {
                    $this->targetPosition = new \pocketmine\math\Vector3($playerPos->x, $playerPos->y + 20.0, $playerPos->z);
                }
            }
            $this->lookAt($nearest->getLocation());

            if ($minDist < 4096 && $this->attackDelay <= 0) {
                if ($this->chargeTicks === 0) {
                    $this->chargeTicks = 30;
                    $this->getNetworkProperties()->setGenericFlag(EntityMetadataFlags::ACTION, true);

                    $pk = new PlaySoundPacket();
                    $pk->soundName = "mob.ghast.scream";
                    $pk->x = $this->location->x;
                    $pk->y = $this->location->y;
                    $pk->z = $this->location->z;
                    $pk->volume = 1.0;
                    $pk->pitch = 1.0;
                    $this->getWorld()->broadcastPacketToViewers($this->location, $pk);
                }
            }
        } else {

            if ($this->chargeTicks > 0) {
                $this->chargeTicks = 0;
                $this->getNetworkProperties()->setGenericFlag(EntityMetadataFlags::ACTION, false);
            }

            if ($this->targetPosition === null || mt_rand(1, 100) <= 5) {
                $randX = mt_rand(-12, 12);
                $randZ = mt_rand(-12, 12);
                $randY = mt_rand(-2, 4);
                $newPos = $this->location->add($randX, $randY, $randZ);

                $chunkX = (int)floor($newPos->x) >> 4;
                $chunkZ = (int)floor($newPos->z) >> 4;
                if ($this->getWorld()->isChunkGenerated($chunkX, $chunkZ)) {
                    $highestBlockY = $this->getWorld()->getHighestBlockAt((int)floor($newPos->x), (int)floor($newPos->z));
                    if ($highestBlockY !== null) {
                        $targetY = max($highestBlockY + 3.0, min($highestBlockY + 16, $newPos->y));
                        $newPos->y = $targetY;
                    }
                    if (!$this->getWorld()->getBlock($newPos)->isSolid()) {
                        $this->targetPosition = $newPos;
                    }
                }
            }
        }
    }

    public function onUpdate(int $currentTick): bool {
        $hasUpdate = parent::onUpdate($currentTick);

        if ($this->chargeTicks > 0) {
            $this->chargeTicks--;
            if ($this->chargeTicks <= 0) {
                $this->getNetworkProperties()->setGenericFlag(EntityMetadataFlags::ACTION, false);

                $nearest = null;
                $minDist = 4096;
                foreach ($this->getWorld()->getPlayers() as $player) {
                    if ($player->isCreative() || $player->isSpectator()) continue;
                    $dist = $this->location->distanceSquared($player->getLocation());
                    if ($dist < $minDist) {
                        $minDist = $dist;
                        $nearest = $player;
                    }
                }

                if ($nearest !== null && $minDist < 4096) {
                    $this->shootFireball($nearest);
                }
                $this->attackDelay = 60;
            }
            $hasUpdate = true;
        }

        return $hasUpdate;
    }

    private function shootFireball(Player $target): void {
        $diff = $target->getLocation()->subtractVector($this->getLocation());
        $pitch = -atan2($diff->y, sqrt($diff->x * $diff->x + $diff->z * $diff->z));
        $yaw = atan2($diff->z, $diff->x) - M_PI_2;

        $direction = $this->getDirectionVector();
        $spawnPos = $this->getLocation()->add(0, $this->getEyeHeight(), 0)->addVector($direction->multiply(2.0));

        $location = Location::fromObject($spawnPos, $this->getWorld(), rad2deg($yaw), rad2deg($pitch));

        $fireball = new \BeeAZ\AZVanillaMobs\entity\projectile\GhastFireball($location, $this);
        $fireball->setMotion($direction->multiply(1.2));

        $ev = new \pocketmine\event\entity\ProjectileLaunchEvent($fireball);
        $ev->call();

        if (!$ev->isCancelled()) {
            $fireball->spawnToAll();

            $pk = new PlaySoundPacket();
            $pk->soundName = "mob.ghast.fireball";
            $pk->x = $spawnPos->x;
            $pk->y = $spawnPos->y;
            $pk->z = $spawnPos->z;
            $pk->volume = 1.0;
            $pk->pitch = 1.0;
            $this->getWorld()->broadcastPacketToViewers($spawnPos, $pk);
        } else {
            $fireball->close();
        }
    }
}
