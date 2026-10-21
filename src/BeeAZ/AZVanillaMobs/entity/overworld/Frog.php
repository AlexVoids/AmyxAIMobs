<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\entity\overworld;

use BeeAZ\AZVanillaMobs\entity\Animal;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;

class Frog extends Animal {

    private int $variant = 0;
    private int $eatCooldown = 0;

    public static function getNetworkTypeId(): string {
        return "minecraft:frog";
    }

    public function isBreedingItem(Item $item): bool {
        return $item->getTypeId() === VanillaItems::SLIMEBALL()->getTypeId();
    }

    protected function initEntity(CompoundTag $nbt): void {
        parent::initEntity($nbt);

        if ($nbt->getTag("Variant") !== null) {
            $this->variant = $nbt->getInt("Variant");
        } else {
            $this->variant = mt_rand(0, 2);
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

    public function onUpdate(int $currentTick): bool {
        if ($this->eatCooldown > 0) {
            $this->eatCooldown--;
        }
        return parent::onUpdate($currentTick);
    }

    protected function calculateAI(): void {
        if ($this->panicTicks > 0 || $this->isOnFire()) {
            parent::calculateAI();
            return;
        }

        if ($this->eatCooldown <= 0) {
            $prey = $this->findNearestLiving(function(\pocketmine\entity\Living $entity): bool {
                return ($entity instanceof Slime && $entity->getSlimeSize() === 1)
                    || ($entity instanceof \BeeAZ\AZVanillaMobs\entity\nether\MagmaCube && $entity->getSlimeSize() === 1);
            }, 16.0, true);

            if ($prey !== null) {
                $this->setCombatTarget($prey, 100);
                $this->targetPosition = clone $prey->getLocation();
                $this->setBehaviorSpeedMultiplier(2.0);
                if ($this->location->distanceSquared($prey->getLocation()) <= 3.1) {
                    $wasMagma = $prey instanceof \BeeAZ\AZVanillaMobs\entity\nether\MagmaCube;
                    $event = new \pocketmine\event\entity\EntityDamageByEntityEvent(
                        $this, $prey, \pocketmine\event\entity\EntityDamageEvent::CAUSE_ENTITY_ATTACK, 10.0
                    );
                    $prey->attack($event);
                    if (!$event->isCancelled() && $wasMagma && !$prey->isAlive()) {
                        $froglightName = match ($this->variant) {
                            1 => 'verdant_froglight',
                            2 => 'pearlescent_froglight',
                            default => 'ochre_froglight',
                        };
                        $froglight = \pocketmine\item\StringToItemParser::getInstance()->parse($froglightName);
                        if ($froglight !== null) {
                            $this->getWorld()->dropItem($prey->getLocation(), $froglight);
                        }
                    }
                    $this->eatCooldown = 20;
                    $this->clearCombatTarget();
                    $this->targetPosition = null;
                }
                return;
            }
        }

        $this->clearCombatTarget();
        parent::calculateAI();
    }
}
