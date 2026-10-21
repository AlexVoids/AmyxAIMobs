<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\entity\overworld;

use BeeAZ\AZVanillaMobs\entity\Animal;
use pocketmine\item\Item;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;

class Turtle extends Animal
{
    private ?Vector3 $homeBeach = null;
    private int $shoreSearchCooldown = 0;

    public static function getNetworkTypeId(): string
    {
        return "minecraft:turtle";
    }

    protected function initEntity(CompoundTag $nbt): void
    {
        parent::initEntity($nbt);
        if ($nbt->getTag("TurtleHomeX") !== null) {
            $this->homeBeach = new Vector3(
                $nbt->getInt("TurtleHomeX"),
                $nbt->getInt("TurtleHomeY"),
                $nbt->getInt("TurtleHomeZ")
            );
        } else {
            $this->homeBeach = clone $this->getPosition();
        }
    }

    public function saveNBT(): CompoundTag
    {
        $nbt = parent::saveNBT();
        if ($this->homeBeach !== null) {
            $nbt->setInt("TurtleHomeX", (int) floor($this->homeBeach->x));
            $nbt->setInt("TurtleHomeY", (int) floor($this->homeBeach->y));
            $nbt->setInt("TurtleHomeZ", (int) floor($this->homeBeach->z));
        }
        return $nbt;
    }

    public function isAquatic(): bool
    {
        return true;
    }

    public function breathesAir(): bool { return true; }
    public function breathesWater(): bool { return false; }

    public function getMovementSpeed(): float
    {
        return $this->isInsideOfWater() ? 0.105 : 0.075;
    }

    public function isBreedingItem(Item $item): bool
    {
        $seagrass = \pocketmine\item\StringToItemParser::getInstance()->parse("seagrass");
        return $seagrass !== null && $item->equals($seagrass, false, false);
    }

    public function onUpdate(int $currentTick): bool
    {
        if ($this->shoreSearchCooldown > 0) {
            $this->shoreSearchCooldown--;
        }
        return parent::onUpdate($currentTick);
    }

    protected function calculateAI(): void
    {
        if ($this->panicTicks > 0 || $this->isOnFire() || $this->inLoveTicks > 0) {
            parent::calculateAI();
            return;
        }

        $this->setBehaviorSpeedMultiplier(1.0);
        $inWater = $this->isInsideOfWater();

        if (!$inWater) {
            $below = $this->getWorld()->getBlock($this->location->subtract(0, 1, 0));
            if ($this->isSand($below)) {
                $this->homeBeach = clone $this->getPosition();
                if ($this->targetPosition === null
                    || $this->location->distanceSquared($this->targetPosition) < 1.2
                    || mt_rand(1, 100) <= 3) {
                    $this->targetPosition = mt_rand(1, 100) <= 45
                        ? $this->chooseRandomDestination(4.5, 1.0)
                        : null;
                }
                return;
            }

            $sand = $this->findNearestSand(20);
            if ($sand !== null) {
                $this->targetPosition = $sand;
                return;
            }

            $water = $this->findNearbyWater(16, 5);
            $this->targetPosition = $water ?? $this->chooseRandomDestination(6.0, 1.0);
            return;
        }

        // Turtles swim slowly and keep a destination for a while. This removes
        // the rapid one-block circles produced by constantly replacing targets.
        if ($this->shoreSearchCooldown <= 0 && mt_rand(1, 100) <= 7) {
            $this->shoreSearchCooldown = 100;
            $sand = $this->findNearestSand(18);
            if ($sand !== null) {
                $this->targetPosition = $sand;
                return;
            }
        }

        if ($this->targetPosition === null
            || $this->location->distanceSquared($this->targetPosition) < 2.5
            || mt_rand(1, 100) <= 4) {
            $this->targetPosition = $this->chooseRandomDestination(10.0, 2.5);
        }
    }

    private function findNearestSand(int $radius): ?Vector3
    {
        $world = $this->getWorld();
        $best = null;
        $bestDistance = PHP_FLOAT_MAX;
        $baseY = $this->location->getFloorY();

        for ($x = -$radius; $x <= $radius; $x += 2) {
            for ($z = -$radius; $z <= $radius; $z += 2) {
                $worldX = $this->location->getFloorX() + $x;
                $worldZ = $this->location->getFloorZ() + $z;
                if (!$world->isChunkGenerated($worldX >> 4, $worldZ >> 4)) {
                    continue;
                }
                $surface = $world->getHighestBlockAt($worldX, $worldZ);
                if ($surface === null || abs($surface - $baseY) > 8) {
                    continue;
                }
                $block = $world->getBlockAt($worldX, $surface, $worldZ);
                if (!$this->isSand($block)) {
                    continue;
                }
                $candidate = new Vector3($worldX + 0.5, $surface + 1.0, $worldZ + 0.5);
                $distance = $this->location->distanceSquared($candidate);
                if ($distance < $bestDistance) {
                    $best = $candidate;
                    $bestDistance = $distance;
                }
            }
        }
        return $best;
    }

    private function isSand(\pocketmine\block\Block $block): bool
    {
        return str_contains(strtolower($block->getName() . " " . get_class($block)), "sand");
    }
}
