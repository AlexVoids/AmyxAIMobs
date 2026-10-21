<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\entity\overworld;

use BeeAZ\AZVanillaMobs\entity\Animal;
use pocketmine\item\Item;
use pocketmine\entity\object\ItemEntity;
use pocketmine\player\Player;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\item\StringToItemParser;
use pocketmine\item\VanillaItems;

class Fox extends Animal {

    private ?ItemEntity $targetItemEntity = null;
    private ?Item $heldItem = null;
    private int $spitDelay = 0;
    private int $attackDelay = 0;

    public static function getNetworkTypeId(): string {
        return "minecraft:fox";
    }

    protected function initEntity(CompoundTag $nbt): void {
        parent::initEntity($nbt);

        $heldItemTag = $nbt->getTag("HeldItem");
        if ($heldItemTag instanceof CompoundTag) {
            $this->heldItem = Item::nbtDeserialize($heldItemTag);
        }
    }

    public function saveNBT(): CompoundTag {
        $nbt = parent::saveNBT();
        if ($this->heldItem !== null && !$this->heldItem->isNull()) {
            $nbt->setTag("HeldItem", $this->heldItem->nbtSerialize());
        }
        return $nbt;
    }

    protected function sendSpawnPacket(Player $player): void {
        parent::sendSpawnPacket($player);
        if ($this->heldItem !== null && !$this->heldItem->isNull()) {
            $this->sendHeldItem($player, $this->heldItem);
        }
    }

    public function equipItem(Item $item): void {
        $this->heldItem = clone $item;
        $this->broadcastHeldItem($this->heldItem);
    }

    public function isBreedingItem(Item $item): bool {
        $sweet = StringToItemParser::getInstance()->parse("sweet_berries");
        $glow = StringToItemParser::getInstance()->parse("glow_berries");
        return ($sweet !== null && $item->equals($sweet, false, false)) ||
               ($glow !== null && $item->equals($glow, false, false));
    }

    public function onUpdate(int $currentTick): bool {
        if ($this->spitDelay > 0) {
            $this->spitDelay--;
        }
        if ($this->attackDelay > 0) {
            $this->attackDelay--;
        }
        return parent::onUpdate($currentTick);
    }

    public function onInteract(Player $player, Vector3 $clickPos): bool {
        $itemInHand = $player->getInventory()->getItemInHand();

        if ($itemInHand->isNull() && $this->heldItem !== null && !$this->heldItem->isNull() && $this->spitDelay <= 0) {
            $player->getInventory()->setItemInHand($this->heldItem);

            $this->equipItem(VanillaItems::AIR());
            $this->spitDelay = 20;

            $this->getWorld()->addSound($this->location, new \pocketmine\world\sound\PopSound());
            return true;
        }

        return parent::onInteract($player, $clickPos);
    }

    protected function calculateAI(): void {
        if ($this->panicTicks > 0 || $this->isOnFire()) {
            parent::calculateAI();
            return;
        }

        $prey = $this->findNearestLiving(function(\pocketmine\entity\Living $entity): bool {
            if ($entity instanceof Chicken || $entity instanceof Cod || $entity instanceof Salmon || $entity instanceof TropicalFish) {
                return true;
            }
            return $entity instanceof Turtle && $entity->age < 0 && !$entity->isInsideOfWater();
        }, 12.0, true);

        if ($prey !== null) {
            $this->setCombatTarget($prey, 160);
            $this->targetPosition = clone $prey->getLocation();
            $this->setBehaviorSpeedMultiplier(1.25);
            if ($this->location->distanceSquared($prey->getLocation()) < 2.2 && $this->attackDelay <= 0) {
                if ($this->performMeleeAttack($prey, 2.0, 2.2)) {
                    $this->attackDelay = 20;
                }
            }
            return;
        }
        $this->clearCombatTarget();

        if ($this->heldItem === null || $this->heldItem->isNull()) {
            if ($this->targetItemEntity !== null && ($this->targetItemEntity->isClosed() || !$this->targetItemEntity->isAlive() || $this->targetItemEntity->getWorld() !== $this->getWorld())) {
                $this->targetItemEntity = null;
            }

            if ($this->targetItemEntity === null) {
                $nearest = null;
                $minDist = 36;
                foreach ($this->getWorld()->getNearbyEntities($this->getBoundingBox()->expandedCopy(6, 3, 6)) as $entity) {
                    if ($entity instanceof ItemEntity && $entity->isAlive() && !$entity->isClosed()) {
                        $dist = $this->location->distanceSquared($entity->getLocation());
                        if ($dist < $minDist) {
                            $minDist = $dist;
                            $nearest = $entity;
                        }
                    }
                }
                $this->targetItemEntity = $nearest;
            }

            if ($this->targetItemEntity !== null) {
                $this->targetPosition = clone $this->targetItemEntity->getLocation();

                $dist = $this->location->distanceSquared($this->targetItemEntity->getLocation());
                if ($dist < 1.5 && $this->spitDelay <= 0) {
                    $item = clone $this->targetItemEntity->getItem();
                    $singleItem = clone $item;
                    $singleItem->setCount(1);

                    $this->targetItemEntity->flagForDespawn();

                    if ($item->getCount() > 1) {
                        $item->setCount($item->getCount() - 1);
                        $this->getWorld()->dropItem($this->targetItemEntity->getLocation(), $item);
                    }

                    $this->equipItem($singleItem);
                    $this->targetItemEntity = null;
                    $this->targetPosition = null;
                    $this->spitDelay = 20;

                    $this->getWorld()->addSound($this->location, new \pocketmine\world\sound\PopSound());
                    return;
                }
                return;
            }
        }

        parent::calculateAI();
    }

    protected function onDeath(): void {
        parent::onDeath();

        if ($this->heldItem !== null && !$this->heldItem->isNull()) {
            $this->getWorld()->dropItem($this->location, $this->heldItem);
            $this->heldItem = null;
        }
    }
}