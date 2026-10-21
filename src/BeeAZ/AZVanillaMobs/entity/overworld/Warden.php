<?php
declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\entity\overworld;

use BeeAZ\AZVanillaMobs\entity\Monster;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Living;
use pocketmine\player\Player;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\network\mcpe\protocol\PlaySoundPacket;
use pocketmine\world\particle\DustParticle;
use pocketmine\color\Color;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\VanillaEffects;

class Warden extends Monster {

    private int $sonicBoomCooldown = 0;
    private int $sonicBoomChargeTicks = 0;
    private ?Living $sonicBoomTarget = null;
    private int $darknessInterval = 0;
    private int $emergeTicks = 0;
    private int $anger = 0;
    private ?Living $suspect = null;

    public static function getNetworkTypeId(): string {
        return "minecraft:warden";
    }

    protected function getInitialSizeInfo(): EntitySizeInfo {
        return new EntitySizeInfo(2.9, 0.9, 2.55);
    }

    protected function initEntity(CompoundTag $nbt): void {
        parent::initEntity($nbt);
        $this->setMaxHealth(500);
        $this->setHealth(min($this->getHealth(), 500));
        $this->anger = $nbt->getInt("WardenAnger", 0);
        if ($nbt->getTag("Emerged") !== null) {
            $this->emergeTicks = 0;
        } else {
            $this->emergeTicks = 80;
            $this->playWardenSound("mob.warden.emerge", 1.0, 1.0);
        }
    }

    public function saveNBT(): CompoundTag {
        $nbt = parent::saveNBT();
        $nbt->setByte("Emerged", 1);
        $nbt->setInt("WardenAnger", $this->anger);
        return $nbt;
    }

    public function getMovementSpeed(): float {
        return $this->getCombatTarget() !== null ? 0.27 : 0.15;
    }

    public function attack(EntityDamageEvent $source): void {
        if ($this->emergeTicks > 0) {
            $source->cancel();
            return;
        }
        parent::attack($source);
        if ($source->isCancelled()) {
            return;
        }
        if ($source instanceof EntityDamageByEntityEvent) {
            $damager = $source->getDamager();
            if ($damager instanceof Living && $damager !== $this) {
                $this->suspect = $damager;
                $this->anger = 150;
                $this->setCombatTarget($damager, 600);
            }
        }
    }

    public function onUpdate(int $currentTick): bool {
        if ($this->emergeTicks > 0) {
            $this->emergeTicks--;
            $this->setMotion(new Vector3(0.0, 0.0, 0.0));
            if ($this->emergeTicks % 4 === 0) {
                $colors = [new Color(110, 80, 50), new Color(50, 60, 65), new Color(0, 100, 100)];
                for ($i = 0; $i < 8; $i++) {
                    $offset = $this->location->add(mt_rand(-10, 10) / 10, 0.05, mt_rand(-10, 10) / 10);
                    $this->getWorld()->addParticle($offset, new DustParticle($colors[array_rand($colors)], 1.2));
                }
            }
            if ($this->emergeTicks === 0) {
                $this->playWardenSound("mob.warden.roar", 1.5, 1.0);
            }
            return true;
        }

        $hasUpdate = parent::onUpdate($currentTick);
        if (!$this->isAlive() || $this->isClosed()) {
            return $hasUpdate;
        }

        if (++$this->darknessInterval >= 120) {
            $this->darknessInterval = 0;
            $effect = method_exists(VanillaEffects::class, "DARKNESS") ? VanillaEffects::DARKNESS() : VanillaEffects::BLINDNESS();
            foreach ($this->getWorld()->getPlayers() as $player) {
                if ($player->isAlive() && !$player->isCreative() && !$player->isSpectator()
                    && $this->location->distanceSquared($player->getLocation()) <= 400) {
                    $player->getEffects()->add(new EffectInstance($effect, 160, 0, false));
                }
            }
        }

        if ($this->sonicBoomChargeTicks > 0) {
            $this->sonicBoomChargeTicks--;
            $this->targetPosition = null;
            $this->motion->x *= 0.3;
            $this->motion->z *= 0.3;
            if ($this->sonicBoomTarget !== null && $this->sonicBoomTarget->isAlive() && !$this->sonicBoomTarget->isClosed()) {
                $this->lookAt($this->sonicBoomTarget->getLocation());
            }
            if ($this->sonicBoomChargeTicks === 0) {
                $this->fireSonicBoom();
            }
            $hasUpdate = true;
        }
        if ($this->sonicBoomCooldown > 0) {
            $this->sonicBoomCooldown--;
        }
        return $hasUpdate;
    }

    protected function calculateAI(): void {
        if ($this->emergeTicks > 0 || $this->sonicBoomChargeTicks > 0) {
            $this->targetPosition = null;
            return;
        }

        $this->attackDelay = max(0, $this->attackDelay - $this->getAIUpdateInterval());
        $this->setBehaviorSpeedMultiplier(1.0);

        $target = $this->getRevengeTarget() ?? $this->getCombatTarget();
        if ($target !== null && (!$target->isAlive() || $target->isClosed() || $target->getWorld() !== $this->getWorld())) {
            $target = null;
        }

        // A calm warden does not magically know every creature around it. It
        // builds suspicion only from nearby moving survival players or contact.
        if ($target === null) {
            $disturbance = $this->findNearestLiving(function(Living $entity): bool {
                if (!$entity instanceof Player || $entity->isCreative() || $entity->isSpectator()) {
                    return false;
                }
                $distanceSquared = $this->location->distanceSquared($entity->getLocation());
                $motion = $entity->getMotion();
                $moving = ($motion->x * $motion->x + $motion->z * $motion->z) > 0.0025 || abs($motion->y) > 0.04;
                return $distanceSquared <= 16.0 || ($distanceSquared <= 196.0 && $moving);
            }, 14.0, false);

            if ($disturbance !== null) {
                if ($this->suspect !== $disturbance) {
                    $this->suspect = $disturbance;
                    $this->anger = max(20, $this->anger);
                }
                $distanceSquared = $this->location->distanceSquared($disturbance->getLocation());
                $this->anger = min(150, $this->anger + ($distanceSquared <= 16.0 ? 18 : 7));
                $this->targetPosition = clone $disturbance->getLocation();
                $this->setBehaviorSpeedMultiplier(0.75);
                if ($this->anger >= 80) {
                    $target = $disturbance;
                }
            } else {
                $this->anger = max(0, $this->anger - 2);
                if ($this->anger === 0) {
                    $this->suspect = null;
                }
            }
        }

        if ($target !== null) {
            $this->setCombatTarget($target, 600);
            $this->targetPosition = clone $target->getLocation();
            $this->setBehaviorSpeedMultiplier(1.12);
            $distanceSquared = $this->location->distanceSquared($target->getLocation());

            if ($distanceSquared <= 4.0 && $this->attackDelay <= 0) {
                if ($this->performMeleeAttack($target, 30.0, 4.0)) {
                    $this->attackDelay = 36;
                }
            } elseif ($distanceSquared >= 16.0 && $distanceSquared <= 225.0 && $this->sonicBoomCooldown <= 0) {
                $this->startSonicBoomCharge($target);
            }
            return;
        }

        $this->clearCombatTarget();
        if ($this->targetPosition === null || $this->location->distanceSquared($this->targetPosition) < 1.5 || mt_rand(1, 100) <= 4) {
            $this->targetPosition = mt_rand(1, 100) <= 55 ? $this->chooseRandomDestination(7.0, 2.0) : null;
        }
    }

    private function startSonicBoomCharge(Living $target): void {
        $this->sonicBoomChargeTicks = 60;
        $this->sonicBoomCooldown = 120;
        $this->sonicBoomTarget = $target;
        $this->playWardenSound("mob.warden.sonic_charge", 1.0, 1.0);
    }

    private function fireSonicBoom(): void {
        $target = $this->sonicBoomTarget;
        $this->sonicBoomTarget = null;
        if ($target === null || !$target->isAlive() || $target->isClosed() || $target->getWorld() !== $this->getWorld()) {
            return;
        }
        $distance = $this->location->distance($target->getLocation());
        if ($distance > 16.0) {
            return;
        }

        $startPos = $this->location->add(0, 1.6, 0);
        $endPos = $target->getLocation()->add(0, $target->getEyeHeight() / 2, 0);
        $diff = $endPos->subtractVector($startPos);
        $steps = max(1, (int) ($distance * 2));
        $delta = $diff->multiply(1 / $steps);
        $current = clone $startPos;
        $color = new Color(0, 191, 255);
        for ($i = 0; $i < $steps; $i++) {
            $current = $current->addVector($delta);
            $this->getWorld()->addParticle($current, new DustParticle($color, 1.3));
        }
        $this->playWardenSound("mob.warden.sonic_boom", 1.5, 1.0);

        $event = new EntityDamageByEntityEvent($this, $target, EntityDamageEvent::CAUSE_MAGIC, 10.0);
        $target->attack($event);
        if (!$event->isCancelled() && $diff->lengthSquared() > 0.001) {
            $direction = $diff->normalize();
            $target->setMotion($target->getMotion()->add($direction->x * 2.0, 0.5, $direction->z * 2.0));
        }
    }

    private function playWardenSound(string $sound, float $volume, float $pitch): void {
        $packet = PlaySoundPacket::create($sound, $this->location->x, $this->location->y, $this->location->z, $volume, $pitch, null);
        foreach ($this->getWorld()->getPlayers() as $player) {
            $player->getNetworkSession()->sendDataPacket($packet);
        }
    }
}
