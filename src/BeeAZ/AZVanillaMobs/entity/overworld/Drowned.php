<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\entity\overworld;

use BeeAZ\AZVanillaMobs\entity\Monster;
use BeeAZ\AZVanillaMobs\utils\CompatItemManager;
use pocketmine\block\Water;
use pocketmine\entity\Living;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\player\Player;
use pocketmine\world\World;

class Drowned extends Monster
{
    private string $heldVariant = "none";
    private int $equipmentRefreshTicks = 0;
    private ?bool $lastSwimmingState = null;

    public static function getNetworkTypeId(): string
    {
        return "minecraft:drowned";
    }

    protected function initEntity(CompoundTag $nbt): void
    {
        $saved = $nbt->getString("DrownedHeldItem", "");
        if ($saved !== "") {
            $this->heldVariant = $saved;
        } else {
            $roll = mt_rand(1, 1000);
            $this->heldVariant = $roll <= 65 ? "trident" : ($roll <= 95 ? "nautilus_shell" : "none");
        }
        parent::initEntity($nbt);
    }

    public function saveNBT(): CompoundTag
    {
        $nbt = parent::saveNBT();
        $nbt->setString("DrownedHeldItem", $this->heldVariant);
        return $nbt;
    }

    protected function sendSpawnPacket(Player $player): void
    {
        parent::sendSpawnPacket($player);
        $item = match ($this->heldVariant) {
            "trident" => CompatItemManager::get("trident"),
            "nautilus_shell" => CompatItemManager::get("nautilus_shell"),
            default => null,
        };
        $this->sendHeldItem($player, $item ?? VanillaItems::AIR());
    }

    public function onUpdate(int $currentTick): bool
    {
        $updated = parent::onUpdate($currentTick);
        if (!$this->isAlive() || $this->isClosed()) {
            return $updated;
        }

        if (++$this->equipmentRefreshTicks >= 120) {
            $this->equipmentRefreshTicks = 0;
            $item = match ($this->heldVariant) {
                "trident" => CompatItemManager::get("trident"),
                "nautilus_shell" => CompatItemManager::get("nautilus_shell"),
                default => null,
            };
            $this->broadcastHeldItem($item ?? VanillaItems::AIR());
        }

        // Bedrock selects the swimming animation from metadata flags. Refresh
        // them only when the drowned enters or leaves water.
        $swimming = $this->isSwimming();
        if ($this->lastSwimmingState !== $swimming) {
            $this->lastSwimmingState = $swimming;
            $this->sendData(null);
            $updated = true;
        }

        // Idle/daytime drowned should remain below the surface instead of
        // standing on top of water as if it were a solid block.
        if ($swimming && $this->shouldRemainSubmerged() && $this->isAtWaterSurface()) {
            $below = $this->location->subtract(0, 1, 0);
            if ($this->getWorld()->getBlock($below) instanceof Water) {
                $this->motion->y = min($this->motion->y, -0.035);
                $updated = true;
            }
        }

        return $updated;
    }

    protected function syncNetworkData(EntityMetadataCollection $properties): void
    {
        parent::syncNetworkData($properties);
        $swimming = $this->isSwimming();
        $properties->setGenericFlag(EntityMetadataFlags::SWIMMER, $swimming);
        $properties->setGenericFlag(EntityMetadataFlags::SWIMMING, $swimming);
        $properties->setGenericFlag(EntityMetadataFlags::WALKER, !$swimming);
    }

    /**
     * Drowned swimming is based on real body contact with water, not merely an
     * old movement state or water somewhere above/below the entity. This is
     * intentionally stricter than the generic aquatic check.
     */
    public function isInsideOfWater(): bool
    {
        return $this->isBodyInWaterAt($this->location);
    }

    public function isSwimming(): bool
    {
        return $this->isBodyInWaterAt($this->location);
    }

    private function isBodyInWaterAt(Vector3 $position): bool
    {
        $world = $this->getWorld();
        $width = $this->size !== null ? $this->size->getWidth() : 0.6;
        $height = $this->size !== null ? $this->size->getHeight() : 1.95;
        $radius = min(0.28, max(0.12, $width * 0.35));
        $midHeight = min(0.95, max(0.45, $height * 0.42));

        foreach ([0.12, $midHeight] as $dy) {
            foreach ([[0.0, 0.0], [$radius, 0.0], [-$radius, 0.0], [0.0, $radius], [0.0, -$radius]] as [$dx, $dz]) {
                if ($world->getBlock($position->add($dx, $dy, $dz)) instanceof Water) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Allows a single shoreline transition only when the dry block directly
     * borders water. It must never grant swimming across a chain of dry blocks.
     */
    private function hasHorizontalWaterContact(Vector3 $position): bool
    {
        $world = $this->getWorld();
        $x = (int) floor($position->x);
        $y = (int) floor($position->y);
        $z = (int) floor($position->z);

        foreach ([$y, $y - 1] as $checkY) {
            foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dz]) {
                if ($world->getBlockAt($x + $dx, $checkY, $z + $dz) instanceof Water) {
                    return true;
                }
            }
        }
        return false;
    }

    public function getHeldVariant(): string
    {
        return $this->heldVariant;
    }

    public function isUndead(): bool { return true; }
    public function isAquatic(): bool { return true; }
    public function burnsInDaylight(): bool { return true; }
    public function breathesAir(): bool { return true; }
    public function breathesWater(): bool { return true; }

    public function getMovementSpeed(): float
    {
        // Deliberately slower underwater movement. The previous value made the
        // drowned slide across the surface faster than its animation.
        return $this->isSwimming() ? 0.11 : 0.21;
    }

    protected function calculateAI(): void
    {
        $this->attackDelay = max(0, $this->attackDelay - $this->getAIUpdateInterval());
        $this->setBehaviorSpeedMultiplier(1.0);

        $inWater = $this->isSwimming();
        $daytime = $this->isDaytime();
        $revenge = $this->getRevengeTarget();

        // In daylight a stranded drowned first seeks water. It may still defend
        // itself when directly attacked.
        if (!$inWater && $daytime && $revenge === null) {
            $this->clearCombatTarget();
            $water = $this->findNearbyWaterForMonster(30, 12);
            if ($water !== null) {
                $this->targetPosition = $water;
                $this->setBehaviorSpeedMultiplier(1.08);
                return;
            }
        }

        $target = $revenge;
        if ($target === null) {
            $current = $this->getCombatTarget();
            if ($current !== null && !$this->isValidNaturalTarget($current)) {
                $current = null;
            }
            $target = $this->selectPreferredTarget($current);

            // During daytime a drowned already in water does not voluntarily
            // leave it to chase a dry land target.
            if ($daytime && $inWater && $target !== null && !$this->isEntityInWater($target)) {
                $target = null;
            }
        }

        if ($target !== null) {
            $this->setCombatTarget($target, 300);
            $this->targetPosition = clone $target->getLocation();
            $this->setBehaviorSpeedMultiplier($inWater ? 0.95 : 0.98);
            if ($this->location->distanceSquared($target->getLocation()) <= $this->getMeleeReachSquared() && $this->attackDelay <= 0) {
                if ($this->performMeleeAttack($target, $this->getAttackDamage(), $this->getMeleeReachSquared())) {
                    $this->attackDelay = $this->getAttackCooldownTicks();
                }
            }
            return;
        }

        $this->clearCombatTarget();

        if (!$inWater) {
            $water = $this->findNearbyWaterForMonster(30, 12);
            if ($water !== null) {
                $this->targetPosition = $water;
                $this->setBehaviorSpeedMultiplier(1.08);
                return;
            }
            if ($this->targetPosition === null || $this->location->distanceSquared($this->targetPosition) < 1.5 || mt_rand(1, 100) <= 6) {
                $this->targetPosition = $this->chooseRandomDestination(10.0, 2.0);
            }
            return;
        }

        // Pick an actual submerged water cell. Surface cells receive a lower
        // score, so the drowned swims through the water volume instead of pacing
        // on the top layer.
        if ($this->targetPosition === null
            || $this->location->distanceSquared($this->targetPosition) < 1.8
            || !($this->getWorld()->getBlock($this->targetPosition) instanceof Water)
            || mt_rand(1, 100) <= 8) {
            $this->targetPosition = $this->chooseSubmergedWaterDestination(16, 7);
        }
    }

    protected function moveTowardsTarget(): void
    {
        $wasSwimming = $this->isSwimming();
        parent::moveTowardsTarget();

        if (!$wasSwimming) {
            // Any vertical impulse left from swimming must disappear as soon as
            // the drowned is physically on dry ground. From this tick onward it
            // uses normal walking collision and can burn in daylight.
            if (!$this->hasHorizontalWaterContact($this->location)) {
                $this->motion->y = min($this->motion->y, 0.0);
            }
            return;
        }

        $next = $this->location->add($this->motion->x, 0.0, $this->motion->z);
        $nextInWater = $this->isBodyInWaterAt($next);
        $nextTouchesWater = $this->hasHorizontalWaterContact($next);

        // Never keep aquatic steering across ordinary dry terrain. One
        // shoreline block which directly touches water is allowed so the mob
        // can actually leave or enter the lake; on the following tick it
        // switches to the normal walking controller.
        if (!$nextInWater && !$nextTouchesWater) {
            $this->motion->x *= 0.10;
            $this->motion->z *= 0.10;
            $this->motion->y = min($this->motion->y, 0.0);
            $water = $this->chooseSubmergedWaterDestination(8, 4);
            if ($water !== null) {
                $this->targetPosition = $water;
            }
            return;
        }

        if (!$nextInWater) {
            $this->motion->y = min($this->motion->y, 0.0);
        }

        // Water drag keeps movement slow and visually synchronized with the
        // swimming animation while still allowing vertical steering.
        $this->motion->x *= 0.86;
        $this->motion->y *= 0.72;
        $this->motion->z *= 0.86;

        if ($this->shouldRemainSubmerged() && $this->isAtWaterSurface()
            && $this->getWorld()->getBlock($this->location->subtract(0, 1, 0)) instanceof Water) {
            $this->motion->y = min($this->motion->y, -0.02);
        }
    }

    private function isDaytime(): bool
    {
        $time = $this->getWorld()->getTimeOfDay();
        return $time < World::TIME_NIGHT || $time >= World::TIME_SUNRISE;
    }

    private function shouldRemainSubmerged(): bool
    {
        return $this->isDaytime() || $this->getCombatTarget() === null;
    }

    private function isEntityInWater(Living $entity): bool
    {
        $world = $entity->getWorld();
        if ($world->getBlock($entity->getLocation()) instanceof Water) {
            return true;
        }
        return $world->getBlock($entity->getLocation()->add(0, $entity->getEyeHeight(), 0)) instanceof Water;
    }

    private function isAtWaterSurface(): bool
    {
        if (!($this->getWorld()->getBlock($this->location) instanceof Water)) {
            return false;
        }
        return !($this->getWorld()->getBlock($this->location->add(0, 1, 0)) instanceof Water);
    }

    private function chooseSubmergedWaterDestination(int $radius, int $verticalRadius): ?Vector3
    {
        $world = $this->getWorld();
        $baseX = $this->location->getFloorX();
        $baseY = $this->location->getFloorY();
        $baseZ = $this->location->getFloorZ();
        $best = null;
        $bestScore = PHP_FLOAT_MAX;

        for ($i = 0; $i < 24; $i++) {
            $x = $baseX + mt_rand(-$radius, $radius);
            $y = $baseY + mt_rand(-$verticalRadius, $verticalRadius);
            $z = $baseZ + mt_rand(-$radius, $radius);
            if (!($world->getBlockAt($x, $y, $z) instanceof Water)) {
                continue;
            }

            $waterAbove = $world->getBlockAt($x, $y + 1, $z) instanceof Water;
            $waterBelow = $world->getBlockAt($x, $y - 1, $z) instanceof Water;
            $candidate = new Vector3($x + 0.5, $y + 0.35, $z + 0.5);
            $score = $this->location->distanceSquared($candidate);
            if (!$waterAbove) {
                $score += 36.0; // strongly avoid the top water layer
            }
            if (!$waterBelow) {
                $score += 8.0;
            }
            if ($score < $bestScore) {
                $best = $candidate;
                $bestScore = $score;
            }
        }

        return $best;
    }
}
