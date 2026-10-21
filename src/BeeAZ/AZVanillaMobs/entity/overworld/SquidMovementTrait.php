<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\entity\overworld;

use pocketmine\block\Water;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\math\Vector3;

/**
 * Calm, depth-aware movement shared by normal and glow squids.
 *
 * Squids should drift and change course slowly. They also need to keep their
 * full body under water; using the generic aquatic steering made them pitch
 * vertically and repeatedly expose their head above the surface.
 */
trait SquidMovementTrait
{
    private int $squidCourseTicks = 0;
    private int $squidRestTicks = 0;

    protected function getInitialSizeInfo(): EntitySizeInfo
    {
        return new EntitySizeInfo(0.8, 0.8, 0.4);
    }

    public function getMovementSpeed(): float
    {
        return 0.065;
    }

    public function onUpdate(int $currentTick): bool
    {
        if ($this->squidCourseTicks > 0) {
            $this->squidCourseTicks--;
        }
        if ($this->squidRestTicks > 0) {
            $this->squidRestTicks--;
        }

        $updated = parent::onUpdate($currentTick);

        if (!$this->isAlive() || $this->isClosed() || !$this->isSwimming()) {
            return $updated;
        }

        $motion = $this->getMotion();

        // Keep the complete squid body below the surface. A water block at the
        // feet is not enough because the model extends above its base position.
        if (!$this->isWaterAt($this->getLocation()->add(0.0, 0.85, 0.0))) {
            $motion = new Vector3($motion->x * 0.82, min($motion->y, -0.035), $motion->z * 0.82);
            $this->targetPosition = $this->findSubmergedDestination(true);
        }

        // Squids are slow drifters, not fish. Limit acceleration and vertical
        // movement so they do not constantly dart around or stand nose-up.
        $horizontal = sqrt($motion->x * $motion->x + $motion->z * $motion->z);
        $maxHorizontal = $this->panicTicks > 0 ? 0.105 : 0.072;
        if ($horizontal > $maxHorizontal && $horizontal > 0.0001) {
            $factor = $maxHorizontal / $horizontal;
            $motion = new Vector3($motion->x * $factor, $motion->y, $motion->z * $factor);
        }
        $motion = new Vector3($motion->x * 0.975, max(-0.045, min(0.028, $motion->y)), $motion->z * 0.975);
        $this->setMotion($motion);

        if ($horizontal > 0.002) {
            $yaw = rad2deg(atan2($motion->z, $motion->x)) - 90.0;
            // The vanilla squid body stays upright while travelling. Applying
            // target pitch to this model makes it appear to swim head-first up.
            $this->setRotation($yaw, 0.0);
        }

        return true;
    }

    protected function calculateAI(): void
    {
        if (!$this->isSwimming()) {
            $this->setBehaviorSpeedMultiplier(0.55);
            $this->targetPosition = $this->findNearbyWater(10, 5);
            return;
        }

        $this->setBehaviorSpeedMultiplier($this->panicTicks > 0 ? 1.25 : 1.0);

        // At the surface the only valid priority is returning deeper into the
        // water column. This also prevents repeated surface bobbing.
        if (!$this->isWaterAt($this->getLocation()->add(0.0, 1.05, 0.0))) {
            $this->targetPosition = $this->findSubmergedDestination(true);
            $this->squidCourseTicks = max($this->squidCourseTicks, 35);
            return;
        }

        if ($this->squidRestTicks > 0) {
            $this->targetPosition = null;
            $this->setBehaviorSpeedMultiplier(0.35);
            return;
        }

        $needsCourse = $this->targetPosition === null
            || $this->squidCourseTicks <= 0
            || $this->getLocation()->distanceSquared($this->targetPosition) < 1.1;

        if (!$needsCourse) {
            return;
        }

        // Short idle pauses make squid movement look deliberate instead of
        // permanently frantic. Panic skips these pauses.
        if ($this->panicTicks <= 0 && mt_rand(1, 100) <= 20) {
            $this->targetPosition = null;
            $this->squidRestTicks = mt_rand(24, 55);
            $this->squidCourseTicks = $this->squidRestTicks;
            return;
        }

        $this->targetPosition = $this->findSubmergedDestination(false);
        $this->squidCourseTicks = mt_rand(80, 170);
    }

    protected function moveTowardsTarget(): void
    {
        if ($this->targetPosition === null || !$this->isSwimming()) {
            return;
        }

        $x = $this->targetPosition->x - $this->getLocation()->x;
        $y = $this->targetPosition->y - $this->getLocation()->y;
        $z = $this->targetPosition->z - $this->getLocation()->z;
        $distanceSq = $x * $x + $y * $y + $z * $z;

        if ($distanceSq < 0.9 || $distanceSq > 1600.0) {
            $this->targetPosition = null;
            return;
        }

        $distance = sqrt($distanceSq);
        $speed = $this->getEffectiveMovementSpeed();
        $desiredX = ($x / $distance) * $speed;
        $desiredZ = ($z / $distance) * $speed;
        $desiredY = max(-0.030, min(0.022, ($y / $distance) * $speed));

        // Never steer upward when the upper part of the body is already at the
        // water surface.
        if (!$this->isWaterAt($this->getLocation()->add(0.0, 1.0, 0.0))) {
            $desiredY = -0.035;
        }

        $motion = $this->getMotion();
        $motion = new Vector3(
            $motion->x * 0.88 + $desiredX * 0.12,
            $motion->y * 0.86 + $desiredY * 0.14,
            $motion->z * 0.88 + $desiredZ * 0.12
        );
        $this->setMotion($motion);

        if (abs($motion->x) + abs($motion->z) > 0.002) {
            $this->setRotation(rad2deg(atan2($motion->z, $motion->x)) - 90.0, 0.0);
        }
    }

    private function findSubmergedDestination(bool $forceDown): ?Vector3
    {
        $origin = $this->getLocation();

        for ($attempt = 0; $attempt < 24; $attempt++) {
            $horizontalRadius = $forceDown ? 4 : 8;
            $dx = mt_rand(-$horizontalRadius * 10, $horizontalRadius * 10) / 10;
            $dz = mt_rand(-$horizontalRadius * 10, $horizontalRadius * 10) / 10;
            $dy = $forceDown ? -(mt_rand(8, 30) / 10) : mt_rand(-22, 14) / 10;
            $candidate = $origin->add($dx, $dy, $dz);

            if (!$this->isWaterAt($candidate)
                || !$this->isWaterAt($candidate->add(0.0, 0.75, 0.0))) {
                continue;
            }

            // Prefer at least one extra water layer over the model. This keeps
            // the head and mantle from visibly breaking through the surface.
            if (!$this->isWaterAt($candidate->add(0.0, 1.20, 0.0)) && $attempt < 18) {
                continue;
            }

            return $candidate;
        }

        // A shallow-water fallback is better than selecting a dry destination.
        for ($depth = 1; $depth <= 5; $depth++) {
            $candidate = $origin->subtract(0.0, (float) $depth, 0.0);
            if ($this->isWaterAt($candidate) && $this->isWaterAt($candidate->add(0.0, 0.75, 0.0))) {
                return $candidate;
            }
        }

        return null;
    }

    private function isWaterAt(Vector3 $position): bool
    {
        return $this->getWorld()->getBlock($position) instanceof Water;
    }
}
