<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\entity\overworld;

use BeeAZ\AZVanillaMobs\entity\Monster;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\math\Vector3;

class Slime extends Monster {

    protected int $slimeSize = 4;
    private int $jumpDelay = 0;

    public static function getNetworkTypeId(): string {
        return "minecraft:slime";
    }

    protected function initEntity(CompoundTag $nbt): void {
        if ($nbt->getTag("SlimeSize") !== null) {
            $requestedSize = $nbt->getInt("SlimeSize", 1);
            $this->slimeSize = $requestedSize >= 4 ? 4 : ($requestedSize >= 2 ? 2 : 1);
        } else {
            // Natural vanilla-style variation instead of every new slime using
            // the same size. Split children always provide SlimeSize in NBT.
            $roll = mt_rand(1, 100);
            $this->slimeSize = $roll <= 45 ? 4 : ($roll <= 85 ? 2 : 1);
        }

        parent::initEntity($nbt);
        $this->setScale($this->slimeSize * 0.5);

        $maxHealth = $this->slimeSize === 4 ? 16 : ($this->slimeSize === 2 ? 4 : 1);
        $this->setMaxHealth($maxHealth);
        if ($nbt->getTag("Health") === null) {
            $this->setHealth($maxHealth);
        }
    }

    public function saveNBT(): CompoundTag {
        $nbt = parent::saveNBT();
        $nbt->setInt("SlimeSize", $this->slimeSize);
        return $nbt;
    }

    protected function getInitialSizeInfo(): EntitySizeInfo {

        return new EntitySizeInfo(1.02, 1.02);
    }

    public function getAttackDamage(): float {
        return $this->slimeSize === 4 ? 4.0 : ($this->slimeSize === 2 ? 2.0 : 0.0);
    }

    public function getXpDropAmount(): int {
        return $this->slimeSize === 4 ? 4 : ($this->slimeSize === 2 ? 2 : 1);
    }

    public function onUpdate(int $currentTick): bool {
        $hasUpdate = parent::onUpdate($currentTick);
        if (!$this->isAlive() || $this->isClosed()) {
            return $hasUpdate;
        }

        if ($this->targetPosition === null && $this->onGround) {
            if ($this->jumpDelay <= 0) {

                $angle = deg2rad($this->location->yaw + mt_rand(0, 360));
                $speed = $this->getMovementSpeed() * 0.8;
                $this->motion->x = cos($angle) * $speed;
                $this->motion->z = sin($angle) * $speed;
                $this->motion->y = 0.35;
                $this->jumpDelay = mt_rand(30, 60);

                $this->getWorld()->addSound($this->location, new \pocketmine\world\sound\LaunchSound());
            } else {
                $this->jumpDelay--;
                $this->motion->x *= 0.8;
                $this->motion->z *= 0.8;
            }
        }
        return $hasUpdate;
    }

    protected function moveTowardsTarget(): void {
        if ($this->targetPosition === null) return;

        $x = $this->targetPosition->x - $this->location->x;
        $z = $this->targetPosition->z - $this->location->z;

        $distanceSq = $x * $x + $z * $z;
        if ($distanceSq < 1.0) {
            $this->targetPosition = null;
            $this->motion->x = 0;
            $this->motion->z = 0;
            return;
        }

        $angle = atan2($z, $x);
        $this->setRotation(rad2deg($angle) - 90, 0);

        if ($this->onGround) {
            if ($this->jumpDelay <= 0) {

                $speed = $this->getMovementSpeed() * 1.5;
                $this->motion->x = cos($angle) * $speed;
                $this->motion->z = sin($angle) * $speed;
                $this->motion->y = 0.42 + ($this->slimeSize * 0.05);
                $this->jumpDelay = mt_rand(15, 30);

                $this->getWorld()->addSound($this->location, new \pocketmine\world\sound\LaunchSound());
            } else {
                $this->jumpDelay--;
                $this->motion->x *= 0.8;
                $this->motion->z *= 0.8;
            }
        }
    }

    protected function onDeath(): void {
        parent::onDeath();

        if ($this->slimeSize > 1) {
            $numSplits = mt_rand(2, 4);
            $splitSize = (int)($this->slimeSize / 2);

            for ($i = 0; $i < $numSplits; $i++) {
                $nbt = CompoundTag::create()->setInt("SlimeSize", $splitSize);

                $spawnPos = $this->getLocation();
                $spawnPos->x += mt_rand(-100, 100) / 150.0;
                $spawnPos->z += mt_rand(-100, 100) / 150.0;

                $splitSlime = new Slime($spawnPos, $nbt);
                $splitSlime->spawnToAll();

                $splitSlime->setMotion(new Vector3(
                    mt_rand(-100, 100) / 250.0,
                    0.25,
                    mt_rand(-100, 100) / 250.0
                ));
            }
        }
    }
    public function getSlimeSize(): int {
        return $this->slimeSize;
    }

}
