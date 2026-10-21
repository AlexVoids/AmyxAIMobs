<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\entity\overworld;

use BeeAZ\AZVanillaMobs\entity\Animal;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\player\Player;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\entity\Living;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\world\particle\HeartParticle;
use pocketmine\world\particle\SmokeParticle;
use pocketmine\world\sound\PopSound;

class Wolf extends Animal {

    private bool $isTamed = false;
    private bool $isSitting = false;
    private bool $isAngry = false;
    private string $ownerUuid = "";
    private ?Living $angryTarget = null;
    private int $angerTicks = 0;
    private int $attackDelay = 0;
    private int $collarColor = 14;
    private float $lastInteractTime = 0.0;

    public static function getNetworkTypeId(): string {
        return "minecraft:wolf";
    }

    protected function initEntity(CompoundTag $nbt): void {
        parent::initEntity($nbt);

        $this->isTamed = $nbt->getByte("IsTamed", 0) === 1;
        $this->isSitting = $nbt->getByte("IsSitting", 0) === 1;
        $this->isAngry = $nbt->getByte("IsAngry", 0) === 1;
        $this->ownerUuid = $nbt->getString("OwnerUUID", "");
        $this->collarColor = $nbt->getInt("CollarColor", 14);

        $this->getNetworkProperties()->setGenericFlag(EntityMetadataFlags::TAMED, $this->isTamed);
        $this->getNetworkProperties()->setGenericFlag(EntityMetadataFlags::SITTING, $this->isSitting);
        $this->getNetworkProperties()->setGenericFlag(EntityMetadataFlags::ANGRY, $this->isAngry);
        $this->getNetworkProperties()->setByte(EntityMetadataProperties::COLOR, $this->collarColor);

        $this->syncOwnerNetworkProperties();
    }

    public function saveNBT(): CompoundTag {
        $nbt = parent::saveNBT();
        $nbt->setByte("IsTamed", $this->isTamed ? 1 : 0);
        $nbt->setByte("IsSitting", $this->isSitting ? 1 : 0);
        $nbt->setByte("IsAngry", $this->isAngry ? 1 : 0);
        $nbt->setString("OwnerUUID", $this->ownerUuid);
        $nbt->setInt("CollarColor", $this->collarColor);
        return $nbt;
    }

    public function isBreedingItem(Item $item): bool {
        $typeId = $item->getTypeId();
        return $typeId === VanillaItems::ROTTEN_FLESH()->getTypeId() ||
               $typeId === VanillaItems::RAW_PORKCHOP()->getTypeId() ||
               $typeId === VanillaItems::COOKED_PORKCHOP()->getTypeId() ||
               $typeId === VanillaItems::RAW_BEEF()->getTypeId() ||
               $typeId === VanillaItems::STEAK()->getTypeId() ||
               $typeId === VanillaItems::RAW_CHICKEN()->getTypeId() ||
               $typeId === VanillaItems::COOKED_CHICKEN()->getTypeId() ||
               $typeId === VanillaItems::RAW_MUTTON()->getTypeId() ||
               $typeId === VanillaItems::COOKED_MUTTON()->getTypeId() ||
               $typeId === VanillaItems::RAW_RABBIT()->getTypeId() ||
               $typeId === VanillaItems::COOKED_RABBIT()->getTypeId();
    }

    public function isTamed(): bool {
        return $this->isTamed;
    }

    public function setTamed(bool $tamed, ?Player $owner = null): void {
        $this->isTamed = $tamed;
        $this->getNetworkProperties()->setGenericFlag(EntityMetadataFlags::TAMED, $tamed);
        if ($owner !== null) {
            $this->ownerUuid = $owner->getUniqueId()->toString();
            $this->getNetworkProperties()->setLong(EntityMetadataProperties::OWNER_EID, $owner->getId());
        } else {
            $this->ownerUuid = "";
            $this->getNetworkProperties()->setLong(EntityMetadataProperties::OWNER_EID, -1);
        }
    }

    public function isSitting(): bool {
        return $this->isSitting;
    }

    public function setSitting(bool $sitting): void {
        $this->isSitting = $sitting;
        $this->getNetworkProperties()->setGenericFlag(EntityMetadataFlags::SITTING, $sitting);
        if ($sitting) {
            $this->targetPosition = null;
            $this->motion->x = 0;
            $this->motion->z = 0;
        }
    }

    protected function syncNetworkData(\pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection $properties): void {
        parent::syncNetworkData($properties);
        $properties->setGenericFlag(EntityMetadataFlags::TAMED, $this->isTamed);
        $properties->setGenericFlag(EntityMetadataFlags::SITTING, $this->isSitting);
        $properties->setGenericFlag(EntityMetadataFlags::ANGRY, $this->isAngry);
        $properties->setByte(EntityMetadataProperties::COLOR, $this->collarColor);
    }

    public function isAngry(): bool {
        return $this->isAngry;
    }

    public function setAngry(bool $angry): void {
        $this->isAngry = $angry;
        $this->getNetworkProperties()->setGenericFlag(EntityMetadataFlags::ANGRY, $angry);
    }

    public function getOwnerUuid(): string {
        return $this->ownerUuid;
    }

    public function getOwner(): ?Player {
        if ($this->ownerUuid === "") return null;
        return $this->getWorld()->getServer()->getPlayerByUUID(\ramsey\uuid\Uuid::fromString($this->ownerUuid));
    }

    public function syncOwnerNetworkProperties(): void {
        $owner = $this->getOwner();
        if ($owner !== null) {
            $this->getNetworkProperties()->setLong(EntityMetadataProperties::OWNER_EID, $owner->getId());
        } else {
            $this->getNetworkProperties()->setLong(EntityMetadataProperties::OWNER_EID, -1);
        }
    }

    public function onUpdate(int $currentTick): bool {
        if ($this->attackDelay > 0) {
            $this->attackDelay--;
        }

        if ($currentTick % 100 === 0) {
            $this->syncOwnerNetworkProperties();
        }

        return parent::onUpdate($currentTick);
    }

    public function attack(EntityDamageEvent $source): void {
        parent::attack($source);

        if (!$source->isCancelled()) {
            if ($source instanceof EntityDamageByEntityEvent) {
                $damager = $source->getDamager();
                if ($damager instanceof Living && !($damager instanceof Player && $damager->isCreative())) {

                    if ($this->isTamed && $damager instanceof Player && $damager->getUniqueId()->toString() === $this->ownerUuid) {
                        return;
                    }

                    $this->setSitting(false);
                    $this->angryTarget = $damager;
                    $this->angerTicks = 600;

                    if (!$this->isTamed) {
                        $this->setAngry(true);
                    }
                }
            }
        }
    }

    public function setAngryTarget(?Living $target): void {
        $this->angryTarget = $target;
        if ($target !== null) {
            $this->angerTicks = 600;
            $this->setSitting(false);
            if (!$this->isTamed) {
                $this->setAngry(true);
            }
        }
    }

    public function onInteract(Player $player, Vector3 $clickPos): bool {
        $time = microtime(true);
        if ($time - $this->lastInteractTime < 0.5) {
            return true;
        }
        $this->lastInteractTime = $time;

        $item = $player->getInventory()->getItemInHand();

        if (!$this->isTamed) {

            if ($item->getTypeId() === VanillaItems::BONE()->getTypeId()) {
                $item->pop();
                $player->getInventory()->setItemInHand($item);

                if (mt_rand(1, 5) === 1) {
                    $this->setTamed(true, $player);
                    $this->setAngry(false);
                    $this->angryTarget = null;
                    $this->angerTicks = 0;
                    $this->setSitting(true);
                    $this->getWorld()->addParticle($this->location->add(0, 1.0, 0), new HeartParticle(4));
                    $this->getWorld()->addSound($this->location, new PopSound());
                } else {

                    for ($i = 0; $i < 5; $i++) {
                        $this->getWorld()->addParticle($this->location->add(mt_rand(-5, 5)/10, mt_rand(2, 10)/10, mt_rand(-5, 5)/10), new SmokeParticle());
                    }
                }
                return true;
            }
        } else {

            if ($player->getUniqueId()->toString() === $this->ownerUuid) {

                if ($this->isBreedingItem($item)) {
                    if ($this->getHealth() < $this->getMaxHealth()) {
                        $this->setHealth(min($this->getMaxHealth(), $this->getHealth() + 4.0));
                        $item->pop();
                        $player->getInventory()->setItemInHand($item);
                        $this->getWorld()->addParticle($this->location->add(0, 1.0, 0), new HeartParticle(3));
                        $this->getWorld()->addSound($this->location, new PopSound());
                        return true;
                    }
                }

                if ($item instanceof \pocketmine\item\Dye) {
                    $cases = \pocketmine\block\utils\DyeColor::cases();
                    $colorIndex = array_search($item->getColor(), $cases, true);
                    if ($colorIndex !== false) {
                        if ($this->collarColor !== $colorIndex) {
                            $this->collarColor = $colorIndex;
                            $this->getNetworkProperties()->setByte(EntityMetadataProperties::COLOR, $colorIndex);

                            if (!$player->isCreative()) {
                                $item->pop();
                                $player->getInventory()->setItemInHand($item);
                            }

                            $this->getWorld()->addSound($this->location, new PopSound());
                            return true;
                        }
                    }
                }

                $this->setSitting(!$this->isSitting);
                $this->getWorld()->addSound($this->location, new PopSound());
                return true;
            }
        }

        return parent::onInteract($player, $clickPos);
    }

    protected function calculateAI(): void {

        if ($this->isSitting) {
            $this->targetPosition = null;
            $this->motion->x = 0;
            $this->motion->z = 0;
            return;
        }

        if ($this->angerTicks > 0) {
            $this->angerTicks -= 10;
            if ($this->angryTarget !== null && (!$this->angryTarget->isAlive() || $this->angryTarget->isClosed() || ($this->angryTarget instanceof Player && !$this->angryTarget->isOnline()))) {
                $this->angryTarget = null;
                $this->angerTicks = 0;
                if (!$this->isTamed) {
                    $this->setAngry(false);
                }
            }
        } else {
            $this->angryTarget = null;
            if (!$this->isTamed) {
                $this->setAngry(false);
            }
        }

        if ($this->angryTarget === null) {
            if (!$this->isTamed) {
                $llama = $this->findNearestLiving(
                    fn(Living $entity): bool => $entity instanceof Llama || $entity instanceof TraderLlama,
                    6.0,
                    false
                );
                if ($llama !== null) {
                    $this->targetPosition = $this->chooseEscapeDestination($llama, 9.0);
                    $this->setBehaviorSpeedMultiplier(1.5);
                    return;
                }
            }

            $prey = $this->findNearestLiving(function(Living $entity): bool {
                if ($entity instanceof Skeleton || $entity instanceof Stray || $entity instanceof Sheep || $entity instanceof Fox) {
                    return true;
                }
                return $entity instanceof Turtle && $entity->age < 0 && !$entity->isInsideOfWater();
            }, 16.0, true);

            // Tamed wolves still have their vanilla skeleton-hunting goal; wild wolves
            // additionally hunt sheep, foxes and baby turtles on land.
            if ($prey !== null && (!$this->isTamed || $prey instanceof Skeleton || $prey instanceof Stray)) {
                $this->setAngryTarget($prey);
            }
        }

        if ($this->angryTarget !== null) {
            $this->targetPosition = clone $this->angryTarget->getLocation();

            $dist = $this->location->distanceSquared($this->angryTarget->getLocation());
            if ($dist < 1.8 && $this->attackDelay <= 0) {

                $ev = new EntityDamageByEntityEvent($this, $this->angryTarget, EntityDamageEvent::CAUSE_ENTITY_ATTACK, $this->isTamed ? 5.0 : 3.0);
                $this->angryTarget->attack($ev);
                $this->attackDelay = 15;
            }
            return;
        }

        if ($this->isTamed) {
            $owner = $this->getOwner();
            if ($owner !== null && $owner->getWorld() === $this->getWorld()) {
                $dist = $this->location->distanceSquared($owner->getLocation());

                if ($dist > 144) {
                    $this->teleport($owner->getLocation());
                    $this->setSitting(false);
                    return;
                }

                if ($dist > 9) {
                    $this->targetPosition = clone $owner->getLocation();
                    return;
                }
            }
        }

        if ($this->panicTicks <= 0) {
            parent::calculateAI();
        }
    }
}
