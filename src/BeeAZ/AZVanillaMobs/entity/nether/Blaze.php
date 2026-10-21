<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\entity\nether;

use BeeAZ\AZVanillaMobs\entity\Monster;
use pocketmine\player\Player;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;

class Blaze extends Monster {

    private int $chargeTicks = 0;
    private int $shootTicks = 0;
    private int $fireballCount = 0;

    public static function getNetworkTypeId(): string {
        return "minecraft:blaze";
    }

    public function isFlying(): bool {
        return true;
    }

    public function getXpDropAmount(): int {
        return 10;
    }

    protected function initEntity(CompoundTag $nbt): void {
        parent::initEntity($nbt);
        $this->setMaxHealth(20);
        $this->setHealth(20);
    }

    public function onUpdate(int $currentTick): bool {
        $hasUpdate = parent::onUpdate($currentTick);
        if (!$this->isAlive() || $this->isClosed()) {
            return $hasUpdate;
        }

        if ($this->shootTicks > 0) {
            $this->shootTicks--;
            if ($this->shootTicks % 6 === 0 && $this->fireballCount < 3) {
                $nearest = $this->getTargetPlayer();
                if ($nearest !== null) {
                    $this->shootFireball($nearest);
                    $this->fireballCount++;
                }

                if ($this->fireballCount >= 3) {
                    $this->shootTicks = 0;
                    $this->getNetworkProperties()->setGenericFlag(EntityMetadataFlags::ACTION, false);
                    $this->attackDelay = mt_rand(60, 100);
                }
            }
            $hasUpdate = true;
        }

        if ($this->chargeTicks > 0) {
            $this->chargeTicks--;
            if ($this->chargeTicks === 0) {
                $this->shootTicks = 18;
                $this->fireballCount = 0;
            }
            $hasUpdate = true;
        }

        return $hasUpdate;
    }

    private function getTargetPlayer(): ?Player {
        $nearest = null;
        $minDist = 1600;
        foreach ($this->getWorld()->getPlayers() as $player) {
            if ($player->isCreative() || $player->isSpectator()) continue;

            $dist = $this->location->distanceSquared($player->getLocation());
            if ($dist < $minDist) {
                $minDist = $dist;
                $nearest = $player;
            }
        }
        return $nearest;
    }

    protected function calculateAI(): void {
        if ($this->attackDelay > 0) {
            $this->attackDelay -= 10;
        }

        $nearest = $this->getTargetPlayer();
        if ($nearest !== null) {
            $this->targetEntity = $nearest;
            $this->lookAt($nearest->getLocation());

            if ($this->targetPosition === null || $this->location->distanceSquared($this->targetPosition) < 9 || mt_rand(1, 100) <= 15) {
                $playerPos = $nearest->getLocation();
                $randX = $playerPos->x + mt_rand(-8, 8);
                $randZ = $playerPos->z + mt_rand(-8, 8);
                $targetY = $playerPos->y + (mt_rand(0, 15) / 10);

                $newPos = new Vector3($randX, $targetY, $randZ);
                if (!$this->getWorld()->getBlock($newPos)->isSolid()) {
                    $this->targetPosition = $newPos;
                }
            }

            if ($this->attackDelay <= 0) {
                $this->chargeTicks = 30;
                $this->attackDelay = 40;
                $this->getNetworkProperties()->setGenericFlag(EntityMetadataFlags::ACTION, true);
                $this->getWorld()->addSound($this->location, new \pocketmine\world\sound\BlazeShootSound());
            }
        } else {
            $this->targetEntity = null;

            if ($this->chargeTicks > 0 || $this->shootTicks > 0) {
                $this->chargeTicks = 0;
                $this->shootTicks = 0;
                $this->getNetworkProperties()->setGenericFlag(EntityMetadataFlags::ACTION, false);
            }

            if ($this->targetPosition === null || mt_rand(1, 100) <= 10) {
                $randX = mt_rand(-6, 6);
                $randZ = mt_rand(-6, 6);
                $randY = mt_rand(-2, 2);
                $newPos = $this->location->add($randX, $randY, $randZ);

                $chunkX = (int)floor($newPos->x) >> 4;
                $chunkZ = (int)floor($newPos->z) >> 4;
                if ($this->getWorld()->isChunkGenerated($chunkX, $chunkZ)) {
                    $highestBlockY = $this->getWorld()->getHighestBlockAt((int)floor($newPos->x), (int)floor($newPos->z));
                    if ($highestBlockY !== null) {
                        $targetY = max($highestBlockY + 0.5, min($highestBlockY + 2.0, $newPos->y));
                        $newPos->y = $targetY;
                    }
                    if (!$this->getWorld()->getBlock($newPos)->isSolid()) {
                        $this->targetPosition = $newPos;
                    }
                }
            }
        }
    }

    private function shootFireball(Player $target): void {
        $location = $this->getLocation();
        $spawnPos = $location->add(0, 1.2, 0)->addVector($this->getDirectionVector()->multiply(0.5));

        $targetPos = $target->getLocation()->add(0, $target->getEyeHeight(), 0);
        $direction = $targetPos->subtractVector($spawnPos)->normalize();

        $direction->x += (mt_rand(-10, 10) / 150);
        $direction->y += (mt_rand(-10, 10) / 150);
        $direction->z += (mt_rand(-10, 10) / 150);
        $direction = $direction->normalize();

        $fireball = new \BeeAZ\AZVanillaMobs\entity\projectile\BlazeFireball(
            \pocketmine\entity\Location::fromObject($spawnPos, $this->getWorld(), $this->location->yaw, $this->location->pitch),
            $this
        );
        $fireball->setMotion($direction->multiply(0.9));

        $ev = new \pocketmine\event\entity\ProjectileLaunchEvent($fireball);
        $ev->call();

        if (!$ev->isCancelled()) {
            $fireball->spawnToAll();
            $this->getWorld()->addSound($this->location, new \pocketmine\world\sound\BlazeShootSound());
        } else {
            $fireball->close();
        }
    }
}
