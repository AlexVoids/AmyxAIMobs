<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\entity\overworld;

use BeeAZ\AZVanillaMobs\entity\Animal;
use pocketmine\player\Player;
use pocketmine\network\mcpe\protocol\SetActorLinkPacket;
use pocketmine\network\mcpe\protocol\types\entity\EntityLink;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\math\Vector3;

class Donkey extends Animal {

    private ?Player $rider = null;
    private bool $isSaddled = false;

    private const SEAT_HEIGHT = 2.0;

    public static function getNetworkTypeId(): string {
        return "minecraft:donkey";
    }

    public function isBreedingItem(\pocketmine\item\Item $item): bool {
        $typeId = $item->getTypeId();
        return $typeId === \pocketmine\item\VanillaItems::GOLDEN_APPLE()->getTypeId() ||
               $typeId === \pocketmine\item\VanillaItems::GOLDEN_CARROT()->getTypeId();
    }

    protected function initEntity(CompoundTag $nbt): void {
        parent::initEntity($nbt);
        $this->isSaddled = $nbt->getByte("IsSaddled", 0) === 1;
        $this->getNetworkProperties()->setGenericFlag(EntityMetadataFlags::SADDLED, $this->isSaddled);
    }

    public function saveNBT(): CompoundTag {
        $nbt = parent::saveNBT();
        $nbt->setByte("IsSaddled", $this->isSaddled ? 1 : 0);
        return $nbt;
    }

    public function isSaddled(): bool {
        return $this->isSaddled;
    }

    public function setSaddled(bool $saddled): void {
        $this->isSaddled = $saddled;
        $this->getNetworkProperties()->setGenericFlag(EntityMetadataFlags::SADDLED, $saddled);
    }

    protected function syncNetworkData(\pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection $properties): void {
        parent::syncNetworkData($properties);
        $properties->setGenericFlag(EntityMetadataFlags::SADDLED, $this->isSaddled);
        $properties->setGenericFlag(EntityMetadataFlags::WASD_CONTROLLED, $this->rider !== null);
        $properties->setGenericFlag(EntityMetadataFlags::CAN_POWER_JUMP, $this->rider !== null);
        $properties->setByte(\pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties::CONTROLLING_RIDER_SEAT_NUMBER, $this->rider !== null ? 0 : -1);
        if ($this->rider !== null) {
            $properties->setVector3(\pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties::RIDER_SEAT_POSITION, new \pocketmine\math\Vector3(0, self::SEAT_HEIGHT, 0));
        }
    }

    public function onUpdate(int $currentTick): bool {
        if ($this->rider !== null) {

            $this->targetPosition = null;
        }
        return parent::onUpdate($currentTick);
    }

    protected function calculateAI(): void {
        if ($this->rider !== null) {
            $this->targetPosition = null;
            return;
        }
        parent::calculateAI();
    }

    public function mountPlayer(Player $player): void {
        if ($this->rider !== null) {
            if ($this->rider->getId() === $player->getId()) {
                $this->dismountPlayer();
            }
            return;
        }

        $player->setHasGravity(false);

        $this->targetPosition = null;
        $this->motion->x = 0.0;
        $this->motion->z = 0.0;

        $player->teleport($this->getLocation()->add(0, self::SEAT_HEIGHT, 0));

        $this->rider = $player;

        $seatVector = new Vector3(0, self::SEAT_HEIGHT, 0);
        $this->getNetworkProperties()->setVector3(EntityMetadataProperties::RIDER_SEAT_POSITION, $seatVector);
        $player->getNetworkProperties()->setVector3(EntityMetadataProperties::RIDER_SEAT_POSITION, $seatVector);
        $player->getNetworkProperties()->setGenericFlag(EntityMetadataFlags::RIDING, true);

        $this->getNetworkProperties()->setGenericFlag(EntityMetadataFlags::WASD_CONTROLLED, true);
        $this->getNetworkProperties()->setGenericFlag(EntityMetadataFlags::CAN_POWER_JUMP, true);

        $this->getNetworkProperties()->setByte(EntityMetadataProperties::CONTROLLING_RIDER_SEAT_NUMBER, 0);

        if ($player->isSurvival()) {
            $player->setAllowFlight(true);
        }

        $link = new EntityLink(
            $this->getId(),
            $player->getId(),
            EntityLink::TYPE_PASSENGER,
            true,
            true,
            0.0
        );

        $pk = SetActorLinkPacket::create($link);
        $this->getWorld()->broadcastPacketToViewers($this->getLocation(), $pk);
        $player->getNetworkSession()->sendDataPacket($pk);

        $link_local = new EntityLink(
            $this->getId(),
            0,
            EntityLink::TYPE_PASSENGER,
            true,
            true,
            0.0
        );
        $pk_local = SetActorLinkPacket::create($link_local);
        $player->getNetworkSession()->sendDataPacket($pk_local);
    }

    public function dismountPlayer(): void {
        if ($this->rider === null) return;

        $player = $this->rider;
        $this->rider = null;

        $player->setHasGravity(true);
        $player->getNetworkProperties()->setGenericFlag(EntityMetadataFlags::RIDING, false);
        $this->getNetworkProperties()->setGenericFlag(EntityMetadataFlags::WASD_CONTROLLED, false);
        $this->getNetworkProperties()->setGenericFlag(EntityMetadataFlags::CAN_POWER_JUMP, false);

        $this->getNetworkProperties()->setByte(EntityMetadataProperties::CONTROLLING_RIDER_SEAT_NUMBER, -1);

        $player->setFlying(false);
        if ($player->isSurvival()) {
            $player->setAllowFlight(false);
        }

        $link = new EntityLink(
            $this->getId(),
            $player->getId(),
            EntityLink::TYPE_REMOVE,
            true,
            true,
            0.0
        );

        $pk = SetActorLinkPacket::create($link);
        $this->getWorld()->broadcastPacketToViewers($this->getLocation(), $pk);
        $player->getNetworkSession()->sendDataPacket($pk);

        $link_local = new EntityLink(
            $this->getId(),
            0,
            EntityLink::TYPE_REMOVE,
            true,
            true,
            0.0
        );
        $pk_local = SetActorLinkPacket::create($link_local);
        $player->getNetworkSession()->sendDataPacket($pk_local);

        $player->teleport($this->getLocation()->add(1, 0.1, 0));
        $player->setMotion(new \pocketmine\math\Vector3(0, -0.2, 0));
    }

    public function getRider(): ?Player {
        return $this->rider;
    }

    protected function entityBaseTick(int $tickDiff = 1): bool {
        $hasUpdate = parent::entityBaseTick($tickDiff);

        if ($this->rider !== null) {
            if (!$this->rider->isOnline() || $this->rider->getWorld() !== $this->getWorld()) {
                $this->dismountPlayer();
            } else {
                $player = $this->rider;

                $this->setRotation($player->getLocation()->yaw, $player->getLocation()->pitch);

                $horseLocation = $this->getLocation();
                $expectedY = $horseLocation->y + self::SEAT_HEIGHT;

                $player->location->x = $horseLocation->x;
                $player->location->y = $expectedY;
                $player->location->z = $horseLocation->z;
                $player->recalculateBoundingBox();
            }
        }

        return $hasUpdate;
    }

    protected function onDispose(): void {
        $this->dismountPlayer();
        parent::onDispose();
    }
}
