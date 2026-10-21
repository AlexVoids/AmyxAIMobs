<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\entity\projectile;

use pocketmine\entity\projectile\Projectile;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\event\entity\ProjectileHitEvent;
use pocketmine\event\entity\ProjectileHitEntityEvent;
use pocketmine\entity\Location;
use pocketmine\entity\Entity;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\event\entity\EntityDamageByChildEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;

class BlazeFireball extends Projectile {

    public function __construct(Location $location, ?Entity $shootingEntity, ?CompoundTag $nbt = null) {
        parent::__construct($location, $shootingEntity, $nbt);
    }

    public static function getNetworkTypeId(): string {
        return "minecraft:small_fireball";
    }

    protected function getInitialSizeInfo(): EntitySizeInfo {
        return new EntitySizeInfo(0.31, 0.31);
    }

    protected function getInitialDragMultiplier(): float {
        return 0.0;
    }

    protected function getInitialGravity(): float {
        return 0.0;
    }

    protected function onHit(ProjectileHitEvent $event): void {
        $world = $this->getWorld();
        $location = $this->getLocation();

        if ($event instanceof ProjectileHitEntityEvent) {
            $entity = $event->getEntityHit();
            $shootingEntity = $this->getOwningEntity();

            if ($shootingEntity !== null) {
                $ev = new EntityDamageByChildEntityEvent(
                    $shootingEntity,
                    $this,
                    $entity,
                    EntityDamageEvent::CAUSE_PROJECTILE,
                    5.0
                );
            } else {
                $ev = new \pocketmine\event\entity\EntityDamageByEntityEvent(
                    $this,
                    $entity,
                    EntityDamageEvent::CAUSE_PROJECTILE,
                    5.0
                );
            }

            $entity->attack($ev);
            $entity->setOnFire(5);
        } else {

            $blockHit = $event->getBlockHit();
            $pos = $blockHit->getPosition()->add(0, 1, 0);
            if ($world->getBlock($pos)->getTypeId() === \pocketmine\block\BlockTypeIds::AIR) {
                $world->setBlock($pos, \pocketmine\block\VanillaBlocks::FIRE());
            }
        }

        $this->flagForDespawn();
    }
}
