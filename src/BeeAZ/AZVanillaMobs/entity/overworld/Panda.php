<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\entity\overworld;

use BeeAZ\AZVanillaMobs\entity\Animal;
use pocketmine\entity\object\ItemEntity;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\world\particle\HeartParticle;

class Panda extends Animal
{
    private bool $isTamed = false;
    private int $sitEatingTicks = 0;
    private int $idleSitTicks = 0;
    private bool $isSitting = false;
    private bool $isEating = false;

    public static function getNetworkTypeId(): string
    {
        return "minecraft:panda";
    }

    public function isBreedingItem(\pocketmine\item\Item $item): bool
    {
        $bamboo = \pocketmine\item\StringToItemParser::getInstance()->parse("bamboo");
        return $bamboo !== null && $item->equals($bamboo, false, false);
    }

    protected function initEntity(CompoundTag $nbt): void
    {
        parent::initEntity($nbt);
        $this->isTamed = $nbt->getByte("IsTamed", 0) === 1;
    }

    public function saveNBT(): CompoundTag
    {
        $nbt = parent::saveNBT();
        $nbt->setByte("IsTamed", $this->isTamed ? 1 : 0);
        return $nbt;
    }

    public function isTamed(): bool { return $this->isTamed; }
    public function isSitting(): bool { return $this->isSitting; }

    public function setTamed(bool $tamed): void
    {
        $this->isTamed = $tamed;
        $this->getNetworkProperties()->setGenericFlag(EntityMetadataFlags::TAMED, $tamed);
    }

    protected function syncNetworkData(EntityMetadataCollection $properties): void
    {
        parent::syncNetworkData($properties);
        $properties->setGenericFlag(EntityMetadataFlags::TAMED, $this->isTamed);
        $properties->setGenericFlag(EntityMetadataFlags::SITTING, $this->isSitting);
        $properties->setGenericFlag(EntityMetadataFlags::EATING, $this->isEating);
        $properties->setFloat(EntityMetadataProperties::SITTING_AMOUNT, $this->isSitting ? 1.0 : 0.0);
        $properties->setFloat(EntityMetadataProperties::SITTING_AMOUNT_PREVIOUS, $this->isSitting ? 1.0 : 0.0);
    }

    private function setSittingState(bool $sitting, bool $eating = false): void
    {
        $this->isSitting = $sitting;
        $this->isEating = $sitting && $eating;
        $properties = $this->getNetworkProperties();
        $properties->setGenericFlag(EntityMetadataFlags::SITTING, $this->isSitting);
        $properties->setGenericFlag(EntityMetadataFlags::EATING, $this->isEating);
        $properties->setFloat(EntityMetadataProperties::SITTING_AMOUNT, $this->isSitting ? 1.0 : 0.0);
        $properties->setFloat(EntityMetadataProperties::SITTING_AMOUNT_PREVIOUS, $this->isSitting ? 1.0 : 0.0);
        $this->sendData(null);
    }

    public function startEating(): void
    {
        $this->sitEatingTicks = 100;
        $this->idleSitTicks = 0;
        $this->setSittingState(true, true);
        $this->targetPosition = null;
        $this->motion->x = 0.0;
        $this->motion->z = 0.0;
        $this->broadcastHeldItem(\pocketmine\item\StringToItemParser::getInstance()->parse("bamboo") ?? \pocketmine\item\VanillaItems::AIR());
    }

    public function onUpdate(int $currentTick): bool
    {
        if ($this->sitEatingTicks > 0) {
            $this->sitEatingTicks--;
            $this->targetPosition = null;
            $this->motion->x = 0.0;
            $this->motion->z = 0.0;

            if ($this->sitEatingTicks % 15 === 0) {
                $this->getWorld()->addSound($this->getLocation(), new \pocketmine\world\sound\ItemUseOnBlockSound(\pocketmine\block\VanillaBlocks::OAK_WOOD()));
            }
            if ($this->sitEatingTicks === 0) {
                $this->setSittingState(false);
                $this->broadcastHeldItem(\pocketmine\item\VanillaItems::AIR());
            }
        } elseif ($this->idleSitTicks > 0) {
            $this->idleSitTicks--;
            $this->targetPosition = null;
            $this->motion->x = 0.0;
            $this->motion->z = 0.0;
            if ($this->idleSitTicks === 0) {
                $this->setSittingState(false);
            }
        }

        return parent::onUpdate($currentTick);
    }

    protected function calculateAI(): void
    {
        if ($this->sitEatingTicks > 0 || $this->idleSitTicks > 0) {
            $this->targetPosition = null;
            return;
        }

        $bambooEntity = null;
        $closestDist = 144.0;
        $bambooItem = \pocketmine\item\StringToItemParser::getInstance()->parse("bamboo");
        if ($bambooItem !== null) {
            foreach ($this->getWorld()->getNearbyEntities($this->getBoundingBox()->expandedCopy(12, 5, 12), $this) as $entity) {
                if (!$entity instanceof ItemEntity || !$entity->getItem()->equals($bambooItem, false, false)) {
                    continue;
                }
                $distance = $this->getLocation()->distanceSquared($entity->getLocation());
                if ($distance < $closestDist) {
                    $closestDist = $distance;
                    $bambooEntity = $entity;
                }
            }
        }

        if ($bambooEntity !== null) {
            $this->targetPosition = clone $bambooEntity->getLocation();
            if ($closestDist <= 2.5) {
                $bambooEntity->close();
                $this->setTamed(true);
                $this->getWorld()->addParticle($this->getLocation()->add(0, 1.0, 0), new HeartParticle(3));
                $this->startEating();
            }
            return;
        }

        // Roughly once every few minutes per idle panda, sit for 3-7 seconds.
        if ($this->panicTicks <= 0 && $this->inLoveTicks <= 0 && mt_rand(1, 400) === 1) {
            $this->idleSitTicks = mt_rand(60, 140);
            $this->setSittingState(true, false);
            $this->targetPosition = null;
            return;
        }

        parent::calculateAI();
    }
}
