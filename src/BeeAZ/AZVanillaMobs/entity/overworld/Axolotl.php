<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\entity\overworld;

use BeeAZ\AZVanillaMobs\entity\Animal;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;

class Axolotl extends Animal {

    private int $variant = 0;
    private int $attackDelay = 0;
    private int $huntCooldown = 0;
    private int $playDeadTicks = 0;
    private int $dryTicks = 0;

    public static function getNetworkTypeId(): string {
        return "minecraft:axolotl";
    }

    public function isAquatic(): bool {
        return true;
    }

    public function isBreedingItem(Item $item): bool {
        return $item->getTypeId() === VanillaItems::CLOWNFISH()->getTypeId();
    }

    protected function initEntity(CompoundTag $nbt): void {
        parent::initEntity($nbt);

        if ($nbt->getTag("Variant") !== null) {
            $this->variant = $nbt->getInt("Variant");
        } else {

            $r = mt_rand(1, 1000);
            if ($r <= 5) {
                $this->variant = 4;
            } elseif ($r <= 250) {
                $this->variant = 0;
            } elseif ($r <= 500) {
                $this->variant = 1;
            } elseif ($r <= 750) {
                $this->variant = 2;
            } else {
                $this->variant = 3;
            }
        }

        $this->getNetworkProperties()->setInt(EntityMetadataProperties::VARIANT, $this->variant);
    }

    public function saveNBT(): CompoundTag {
        $nbt = parent::saveNBT();
        $nbt->setInt("Variant", $this->variant);
        return $nbt;
    }

    public function getVariant(): int {
        return $this->variant;
    }

    public function setVariant(int $variant): void {
        $this->variant = $variant;
        $this->getNetworkProperties()->setInt(EntityMetadataProperties::VARIANT, $variant);
    }

    protected function shouldPanicWhenHurt(): bool {
        return false;
    }

    public function attack(\pocketmine\event\entity\EntityDamageEvent $source): void {
        parent::attack($source);
        if (!$source->isCancelled() && $this->isInsideOfWater() && !$this->isOnFire()
            && ($this->getHealth() <= 8 || mt_rand(1, 3) === 1)) {
            $this->playDeadTicks = 200;
            $this->clearCombatTarget();
            $this->targetPosition = null;
            $this->getEffects()->add(new \pocketmine\entity\effect\EffectInstance(
                \pocketmine\entity\effect\VanillaEffects::REGENERATION(), 200, 0
            ));
        }
    }

    public function onUpdate(int $currentTick): bool {
        if ($this->attackDelay > 0) $this->attackDelay--;
        if ($this->huntCooldown > 0) $this->huntCooldown--;
        if ($this->playDeadTicks > 0) {
            $this->playDeadTicks--;
            $this->targetPosition = null;
            $this->motion->x = 0.0;
            $this->motion->z = 0.0;
        }

        if ($this->isInsideOfWater()) {
            $this->dryTicks = 0;
        } else {
            $this->dryTicks++;
            if ($this->dryTicks > 6000 && $currentTick % 20 === 0) {
                $this->attack(new \pocketmine\event\entity\EntityDamageEvent(
                    $this, \pocketmine\event\entity\EntityDamageEvent::CAUSE_DROWNING, 1.0
                ));
            }
        }
        return parent::onUpdate($currentTick);
    }

    protected function calculateAI(): void {
        if ($this->playDeadTicks > 0) {
            $this->targetPosition = null;
            return;
        }

        if ($this->isInsideOfWater() && $this->huntCooldown <= 0) {
            $prey = $this->findNearestLiving(function(\pocketmine\entity\Living $entity): bool {
                if (!($entity instanceof \BeeAZ\AZVanillaMobs\entity\BaseMob) || !$entity->isInsideOfWater()) {
                    return false;
                }
                return in_array($entity->getShortEntityName(), [
                    'cod', 'salmon', 'tropicalfish', 'pufferfish', 'squid', 'glowsquid',
                    'tadpole', 'drowned', 'guardian', 'elderguardian'
                ], true);
            }, 8.0, true);

            if ($prey !== null) {
                $this->setCombatTarget($prey, 160);
                $this->targetPosition = clone $prey->getLocation();
                $this->setBehaviorSpeedMultiplier(1.2);
                if ($this->location->distanceSquared($prey->getLocation()) < 2.2 && $this->attackDelay <= 0) {
                    if ($this->performMeleeAttack($prey, 2.0, 2.2)) {
                        $this->attackDelay = 20;
                        if (!$prey->isAlive()) {
                            $this->huntCooldown = 2400;
                        }
                    }
                }
                return;
            }
        }
        $this->clearCombatTarget();
        parent::calculateAI();
    }
    public function breathesAir(): bool { return true; }
    public function breathesWater(): bool { return true; }
}
